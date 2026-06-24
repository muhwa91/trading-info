<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Services\FxService;
use App\Services\MarketSessionService;
use App\Services\PnlService;
use App\Services\PriceService;
use App\Services\Toss\TossStockMaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 포트폴리오 대시보드 API.
 *
 * GET /api/portfolio/dashboard?session=regular
 *
 * 응답 구조:
 * {
 *   "session": "regular",
 *   "exchange_rate": { "USD_KRW": 1380.5, "recorded_at": "..." },
 *   "summary": {
 *     "totalMarketValueKRW": 0.0,
 *     "totalCostKRW": 0.0,
 *     "totalProfitKRW": 0.0,
 *     "totalPriceProfitKRW": 0.0,
 *     "totalFxProfitKRW": 0.0,
 *     "totalProfitRate": 0.0
 *   },
 *   "holdings": [
 *     {
 *       "portfolio_id": 1,
 *       "stock_id": 1,
 *       "symbol": "005930",
 *       "name": "삼성전자",
 *       "market": "KR",
 *       "currency": "KRW",
 *       "type": "stock",
 *       "quantity": "10.000000",
 *       "average_price": "75000.0000",
 *       "avg_fx_rate": "1.0000",
 *       "current_price": 76000.0,
 *       "session_badge": "REG",
 *       "price_available": true,
 *       "marketValueKRW": 760000.0,
 *       "costKRW": 750000.0,
 *       "profitKRW": 10000.0,
 *       "priceProfitKRW": 10000.0,
 *       "fxProfitKRW": 0.0,
 *       "profitRate": 0.013333
 *     }
 *   ],
 *   "watchlist": [
 *     {
 *       "watchlist_id": 1,
 *       "stock_id": 2,
 *       "symbol": "AAPL",
 *       "name": "Apple Inc.",
 *       "market": "US",
 *       "currency": "USD",
 *       "type": "stock",
 *       "current_price": 210.5,
 *       "change_amount": 1.2,
 *       "change_percent": 0.57,
 *       "price_available": true
 *     }
 *   ]
 * }
 */
class DashboardController extends Controller
{
    /** 고정 user_id (로그인 이전 단계) */
    private const USER_ID = 1;

    /** 기본 account_id */
    private const DEFAULT_ACCOUNT_ID = 1;

    /** session 별 배지 레이블 */
    private const SESSION_BADGE = [
        'regular' => 'REG',
        'pre'     => 'PRE',
        'after'   => 'AFT',
    ];

    private PriceService         $priceService;
    private FxService            $fxService;
    private PnlService           $pnlService;
    private MarketSessionService $sessionService;
    private TossStockMaster      $stockMaster;

    public function __construct(
        PriceService $priceService,
        FxService $fxService,
        PnlService $pnlService,
        MarketSessionService $sessionService,
        TossStockMaster $stockMaster
    ) {
        $this->priceService   = $priceService;
        $this->fxService      = $fxService;
        $this->pnlService     = $pnlService;
        $this->sessionService = $sessionService;
        $this->stockMaster    = $stockMaster;
    }

    /**
     * GET /api/portfolio/dashboard
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function dashboard(Request $request): JsonResponse
    {
        $session = $this->resolveSession((string)$request->query('session', 'regular'));

        // ── 1. 보유/관심 종목 합집합 stock_id 확보 ─────────────────
        /** @var \Illuminate\Database\Eloquent\Collection<int, Portfolio> $holdings */
        $holdings = Portfolio::with('stock')
            ->whereHas('account', function ($q): void {
                $q->where('user_id', self::USER_ID);
            })
            ->get();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Watchlist> $watchlistItems */
        $watchlistItems = Watchlist::with('stock')
            ->where('user_id', self::USER_ID)
            ->orderBy('sort_order')
            ->get();

        $holdingStockIds   = $holdings->pluck('stock_id')->map('intval')->toArray();
        $watchlistStockIds = $watchlistItems->pluck('stock_id')->map('intval')->toArray();

        $allStockIds = array_values(array_unique(array_merge($holdingStockIds, $watchlistStockIds)));

        // ── 2. 시세 일괄 갱신 + 환율 확보 ──────────────────────────
        $prices = [];
        if (!empty($allStockIds)) {
            try {
                $prices = $this->priceService->refresh($allStockIds, $session);
            } catch (\Throwable $e) {
                Log::error('[DashboardController] PriceService 오류: ' . $e->getMessage());
                $prices = $this->priceService->latest($allStockIds, $session);
            }
        }

        // ── 2-1. 종목 마스터 배치 워밍 (N+1 방지) ───────────────────
        // 보유·관심 전 종목 심볼을 한 번에 TossStockMaster::getInfoBatch 로 캐싱.
        // 이후 $stock->name / $stock->type / $stock->currency accessor 는 모두 캐시 히트.
        $allSymbols = [];
        foreach ($holdings as $holding) {
            if ($holding->stock !== null) {
                $allSymbols[] = $holding->stock->symbol;
            }
        }
        foreach ($watchlistItems as $item) {
            if ($item->stock !== null) {
                $allSymbols[] = $item->stock->symbol;
            }
        }
        if (!empty($allSymbols)) {
            try {
                $this->stockMaster->getInfoBatch(array_unique($allSymbols));
            } catch (\Throwable $e) {
                Log::warning('[DashboardController] 종목 마스터 워밍 실패 (graceful): ' . $e->getMessage());
            }
        }

        $fxData = null;
        $fxNow  = 1.0;
        try {
            $fxData = $this->fxService->getUsdKrw();
            if ($fxData !== null) {
                $fxNow = $fxData['rate'];
            }
        } catch (\Throwable $e) {
            Log::error('[DashboardController] FxService 오류: ' . $e->getMessage());
        }

        // ── 3. 보유 손익 계산 ────────────────────────────────────────
        $holdingRows  = [];
        $evaluations  = [];
        $sessionBadge = self::SESSION_BADGE[$session] ?? 'REG';

        foreach ($holdings as $holding) {
            $stock = $holding->stock;
            if ($stock === null) {
                continue;
            }

            $stockId      = (int)$holding->stock_id;
            $priceData    = $prices[$stockId] ?? null;
            $hasPrice     = ($priceData !== null && isset($priceData['price']));
            $currentPrice = $hasPrice ? (float)$priceData['price'] : 0.0;

            // US 종목 전용: 정규장 마지막 종가 (KIS base 필드).
            // KR 종목은 regular_close_price = null (장전 손익 없음).
            $regularClosePrice = null;
            if ($hasPrice && $stock->market === 'US') {
                $regularClosePrice = isset($priceData['regular_close']) && (float)$priceData['regular_close'] > 0
                    ? (float)$priceData['regular_close']
                    : $currentPrice; // base 없으면 current 로 폴백 → 장전 손익 = 0
            }

            $qty    = (float)$holding->quantity;
            $avg    = (float)$holding->average_price;
            $fxBuy  = (float)$holding->avg_fx_rate;

            $evaluation = null;
            if ($hasPrice) {
                // 미실현손익 계산 기준: US 는 정규장 종가(regular_close), KR 은 current_price
                $evalPrice = ($stock->market === 'US' && $regularClosePrice !== null)
                    ? $regularClosePrice
                    : $currentPrice;

                $evaluation = $this->pnlService->evaluate(
                    $qty,
                    $avg,
                    $fxBuy,
                    (string)$stock->currency,
                    $evalPrice,
                    $fxNow
                );
                $evaluations[] = $evaluation;
            }

            $row = [
                'portfolio_id'        => (int)$holding->id,
                'stock_id'            => $stockId,
                'symbol'              => $stock->symbol,
                'name'                => $stock->name,
                'market'              => $stock->market,
                'currency'            => $stock->currency,
                'type'                => $stock->type,
                'quantity'            => $holding->quantity,
                'average_price'       => $holding->average_price,
                'avg_fx_rate'         => $holding->avg_fx_rate,
                'current_price'       => $hasPrice ? $currentPrice : null,
                'regular_close_price' => $regularClosePrice,
                'session_badge'       => $sessionBadge,
                'live_session'        => $this->resolveHoldingSession($stock),
                'price_available'     => $hasPrice,
            ];

            if ($evaluation !== null) {
                $row['marketValueKRW']  = $evaluation['marketValueKRW'];
                $row['costKRW']         = $evaluation['costKRW'];
                $row['profitKRW']       = $evaluation['profitKRW'];
                $row['priceProfitKRW']  = $evaluation['priceProfitKRW'];
                $row['fxProfitKRW']     = $evaluation['fxProfitKRW'];
                $row['profitRate']      = $evaluation['profitRate'];
            } else {
                $row['marketValueKRW']  = null;
                $row['costKRW']         = null;
                $row['profitKRW']       = null;
                $row['priceProfitKRW']  = null;
                $row['fxProfitKRW']     = null;
                $row['profitRate']      = null;
            }

            $holdingRows[] = $row;
        }

        $summary = $this->pnlService->summarize($evaluations);

        // ── 4. 관심종목 (손익 없음 — 현재가·등락률만) ────────────────
        $watchlistRows = [];
        foreach ($watchlistItems as $item) {
            $stock = $item->stock;
            if ($stock === null) {
                continue;
            }

            $stockId   = (int)$item->stock_id;
            $priceData = $prices[$stockId] ?? null;
            $hasPrice  = ($priceData !== null && isset($priceData['price']));

            $watchlistRows[] = [
                'watchlist_id'   => (int)$item->id,
                'stock_id'       => $stockId,
                'symbol'         => $stock->symbol,
                'name'           => $stock->name,
                'market'         => $stock->market,
                'currency'       => $stock->currency,
                'type'           => $stock->type,
                'sort_order'     => $item->sort_order,
                'current_price'  => $hasPrice ? (float)$priceData['price'] : null,
                'change_amount'  => $hasPrice ? (float)$priceData['change_amount'] : null,
                'change_percent' => $hasPrice ? (float)$priceData['change_percent'] : null,
                'price_available' => $hasPrice,
            ];
        }

        return response()->json([
            'session'       => $session,
            'exchange_rate' => $fxData !== null ? [
                'USD_KRW'    => $fxData['rate'],
                'recorded_at' => $fxData['recorded_at'],
                'source'     => $fxData['source'] ?? null,
            ] : null,
            'summary'       => $summary,
            'holdings'      => $holdingRows,
            'watchlist'     => $watchlistRows,
        ]);
    }

    /**
     * 허용된 session 값만 통과, 나머지는 'regular' 로 폴백.
     */
    private function resolveSession(string $raw): string
    {
        $allowed = ['regular', 'pre', 'after'];
        return in_array($raw, $allowed, true) ? $raw : 'regular';
    }

    /**
     * 종목의 시장(US/KR)에 따라 현재 라이브 세션명을 반환한다.
     *
     * 반환 예: '정규장' | '프리마켓' | '애프터마켓' | '주간거래' | '장마감'
     */
    private function resolveHoldingSession(Stock $stock): string
    {
        $now = time();
        if ($stock->market === 'US') {
            return $this->sessionService->getUsSession($now);
        }
        if ($stock->market === 'KR') {
            return $this->sessionService->getKrSession($now);
        }
        return '장마감';
    }
}
