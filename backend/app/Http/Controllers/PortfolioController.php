<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePortfolioRequest;
use App\Http\Requests\UpdatePortfolioRequest;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Services\FxService;
use App\Services\KrStockResolver;
use App\Services\PriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 포트폴리오 트래커 — 시세 폴링 + 보유 종목 CRUD.
 *
 * 주문·거래 엔드포인트 없음(자문·주문 금지, 읽기 전용 원칙).
 */
class PortfolioController extends Controller
{
    /** 고정 user_id (로그인 이전 단계) */
    private const USER_ID = 1;

    /** 기본 account_id */
    private const DEFAULT_ACCOUNT_ID = 1;

    private PriceService    $priceService;
    private FxService       $fxService;
    private KrStockResolver $krStockResolver;

    public function __construct(
        PriceService $priceService,
        FxService $fxService,
        KrStockResolver $krStockResolver
    ) {
        $this->priceService    = $priceService;
        $this->fxService       = $fxService;
        $this->krStockResolver = $krStockResolver;
    }

    // ──────────────────────────────────────────────────────────────
    // 시세 폴링 엔드포인트 (STEP 2 — 변경 없음)
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /api/prices
     *
     * Query params:
     *   ids     : string  콤마 구분 stock_id 목록 (없으면 portfolio∪watchlist 합집합)
     *   session : string  'regular'|'pre'|'after' (기본: 'regular')
     */
    public function prices(Request $request): JsonResponse
    {
        $session  = $this->resolveSession((string)$request->query('session', 'regular'));
        $idsParam = (string)$request->query('ids', '');

        $stockIds = $this->resolveStockIds($idsParam);

        $prices = [];
        if (!empty($stockIds)) {
            try {
                $prices = $this->priceService->refresh($stockIds, $session);
            } catch (\Throwable $e) {
                Log::error('[PortfolioController::prices] PriceService 오류: ' . $e->getMessage());
                $prices = $this->priceService->latest($stockIds, $session);
            }
        }

        $fxData = null;
        try {
            $fxData = $this->fxService->getUsdKrw();
        } catch (\Throwable $e) {
            Log::error('[PortfolioController::prices] FxService 오류: ' . $e->getMessage());
        }

        return response()->json([
            'session'       => $session,
            'exchange_rate' => $fxData !== null ? [
                'USD_KRW'     => $fxData['rate'],
                'recorded_at' => $fxData['recorded_at'],
            ] : null,
            'prices'        => $prices,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // 보유 종목 CRUD (STEP 3 신규)
    // ──────────────────────────────────────────────────────────────

    /**
     * POST /api/portfolio
     * 보유 종목 추가. US 종목이 stocks 에 없으면 lazy 생성.
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $stock = $this->resolveOrCreateStock($data);

        if ($stock === null) {
            return response()->json([
                'message' => 'stock_id 또는 symbol+market 을 입력해 주세요.',
            ], 422);
        }

        // KR 종목은 avg_fx_rate 자동 1.0
        $avgFxRate = ($stock->currency === 'USD')
            ? (float)($data['avg_fx_rate'] ?? 1.0)
            : 1.0;

        $accountId = isset($data['account_id']) ? (int)$data['account_id'] : self::DEFAULT_ACCOUNT_ID;
        $source    = $data['source'] ?? 'manual';

        $portfolio = DB::transaction(function () use ($stock, $data, $avgFxRate, $accountId, $source): Portfolio {
            return Portfolio::create([
                'account_id'    => $accountId,
                'stock_id'      => $stock->id,
                'quantity'      => (float)$data['quantity'],
                'average_price' => (float)$data['average_price'],
                'avg_fx_rate'   => $avgFxRate,
                'source'        => $source,
            ]);
        });

        return response()->json([
            'message'   => '보유 종목이 추가되었습니다.',
            'portfolio' => $this->formatPortfolioRow($portfolio->load('stock')),
        ], 201);
    }

    /**
     * PATCH /api/portfolio/{id}
     * 보유 종목 수정 (수량, 평단, 환율, source).
     */
    public function update(UpdatePortfolioRequest $request, int $id): JsonResponse
    {
        $portfolio = Portfolio::find($id);
        if ($portfolio === null) {
            return response()->json(['message' => '보유 종목을 찾을 수 없습니다.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($portfolio, $data): void {
            $portfolio->fill($data);
            $portfolio->save();
        });

        return response()->json([
            'message'   => '보유 종목이 수정되었습니다.',
            'portfolio' => $this->formatPortfolioRow($portfolio->load('stock')),
        ]);
    }

    /**
     * DELETE /api/portfolio/{id}
     * 보유 종목 삭제.
     */
    public function destroy(int $id): JsonResponse
    {
        $portfolio = Portfolio::find($id);
        if ($portfolio === null) {
            return response()->json(['message' => '보유 종목을 찾을 수 없습니다.'], 404);
        }

        DB::transaction(function () use ($portfolio): void {
            $portfolio->delete();
        });

        return response()->json(['message' => '보유 종목이 삭제되었습니다.']);
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * stock_id 또는 symbol+market 으로 Stock 조회·생성.
     *
     * US : stocks 에 없으면 firstOrCreate (lazy 생성).
     * KR : 심볼 정규화(.KS/.KQ 접미사 제거) 후 조회. 없으면 KrStockResolver 로 lazy 생성.
     *
     * @param  array<string, mixed> $data
     * @return Stock|null
     */
    private function resolveOrCreateStock(array $data): ?Stock
    {
        // stock_id 우선
        if (!empty($data['stock_id'])) {
            return Stock::find((int)$data['stock_id']);
        }

        $symbol = isset($data['symbol']) ? trim((string)$data['symbol']) : '';
        $market = isset($data['market']) ? strtoupper(trim((string)$data['market'])) : '';

        if ($symbol === '' || $market === '') {
            return null;
        }

        if ($market === 'US') {
            // Phase 7: name·type·currency 는 토스 캐시(TossStockMaster)가 제공.
            // 마이그레이션 실행 전까지는 NOT NULL 제약을 위해 기본값을 유지하고,
            // 실행 후에는 컬럼이 사라지므로 자동으로 무해화된다.
            $symbolUpper = strtoupper($symbol);
            return Stock::firstOrCreate(
                ['symbol' => $symbolUpper, 'market' => 'US'],
                [
                    'name'     => $symbolUpper, // 마이그레이션 전 NOT NULL 대비 기본값; 표시명은 TossStockMaster accessor 경유
                    'type'     => 'stock',       // 동일
                    'currency' => 'USD',         // 동일
                    'exchange' => null,
                ]
            );
        }

        if ($market === 'KR') {
            // KR 종목: 정규화(접미사 제거) 후 조회. 없으면 lazy 생성.
            $nameHint = isset($data['name']) ? trim((string)$data['name']) : null;
            return $this->krStockResolver->resolveOrCreate($symbol, $nameHint ?: null);
        }

        return null;
    }

    /**
     * Portfolio 행을 응답용 배열로 포맷.
     *
     * @param  Portfolio $portfolio  stock 관계가 로드된 모델
     * @return array<string, mixed>
     */
    private function formatPortfolioRow(Portfolio $portfolio): array
    {
        $stock = $portfolio->stock;
        return [
            'id'            => $portfolio->id,
            'account_id'    => $portfolio->account_id,
            'stock_id'      => $portfolio->stock_id,
            'symbol'        => $stock ? $stock->symbol : null,
            'name'          => $stock ? $stock->name   : null,
            'market'        => $stock ? $stock->market  : null,
            'currency'      => $stock ? $stock->currency : null,
            'quantity'      => $portfolio->quantity,
            'average_price' => $portfolio->average_price,
            'avg_fx_rate'   => $portfolio->avg_fx_rate,
            'source'        => $portfolio->source,
            'created_at'    => $portfolio->created_at ? $portfolio->created_at->toDateTimeString() : null,
            'updated_at'    => $portfolio->updated_at ? $portfolio->updated_at->toDateTimeString() : null,
        ];
    }

    /**
     * ids 쿼리 파라미터를 정수 배열로 변환.
     * 비어 있으면 portfolio∪watchlist 합집합 조회.
     *
     * @return int[]
     */
    private function resolveStockIds(string $idsParam): array
    {
        if ($idsParam !== '') {
            $ids = array_filter(
                array_map('intval', explode(',', $idsParam)),
                static function (int $id): bool {
                    return $id > 0;
                }
            );
            return array_values(array_unique($ids));
        }

        $portfolioIds = Portfolio::pluck('stock_id')->toArray();
        $watchlistIds = Watchlist::pluck('stock_id')->toArray();

        $merged = array_unique(array_merge($portfolioIds, $watchlistIds));
        return array_values(array_map('intval', $merged));
    }

    /**
     * 허용된 session 값만 통과. 알 수 없는 값은 'regular' 로 폴백.
     */
    private function resolveSession(string $raw): string
    {
        $allowed = ['regular', 'pre', 'after'];
        return in_array($raw, $allowed, true) ? $raw : 'regular';
    }
}
