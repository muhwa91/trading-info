<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Price;
use App\Models\Stock;
use App\Services\Quote\KisDomesticQuoteProvider;
use App\Services\Quote\KisOverseasQuoteProvider;
use App\Services\Quote\QuoteProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * prices 테이블의 최신 1건 upsert 관리.
 *
 * 고유 키: (stock_id, session) — 종목 + 세션당 최신 1건만 유지.
 * recorded_at = KIS 기준 시각(또는 조회 시각)
 * updated_at  = DB 저장/갱신 시각 (신선도 판단 기준)
 *
 * 신선도 임계값:
 *   시세  = PRICE_STALE_SECONDS (기본 10초) — 폴링 5~15초 주기에 맞춤
 *   환율은 FxService 가 별도 관리
 */
class PriceService
{
    /** 시세 신선도 임계값(초). 이 시간 이내의 데이터는 KIS 호출 생략. */
    private const PRICE_STALE_SECONDS = 10;

    /**
     * KIS rate limit 대응: 연속 호출 간 간격(마이크로초).
     * KIS 초당 거래건수(EGW00201) 방지. 100ms 간격.
     */
    private const KIS_CALL_INTERVAL_US = 100000;

    private KisDomesticQuoteProvider $domestic;
    private KisOverseasQuoteProvider $overseas;

    public function __construct(
        KisDomesticQuoteProvider $domestic,
        KisOverseasQuoteProvider $overseas
    ) {
        $this->domestic = $domestic;
        $this->overseas = $overseas;
    }

    /**
     * 지정 종목 목록의 시세를 필요 시에만 갱신(KIS 호출) 후 최신값 반환.
     *
     * @param  int[]  $stockIds
     * @param  string $session  'regular'|'pre'|'after'
     * @return array  [stock_id => ['price', 'change_amount', 'change_percent', 'recorded_at', 'updated_at', 'stale']]
     */
    public function refresh(array $stockIds, string $session): array
    {
        if (empty($stockIds)) {
            return [];
        }

        // 현재 캐시된 rows 일괄 조회 (N+1 방지)
        $existing = Price::whereIn('stock_id', $stockIds)
            ->where('session', $session)
            ->get()
            ->keyBy('stock_id');

        // 신선도 기준 시각
        $staleThreshold = Carbon::now()->subSeconds(self::PRICE_STALE_SECONDS);

        // 종목 정보 일괄 조회 (N+1 방지)
        $stocks = Stock::whereIn('id', $stockIds)->get()->keyBy('id');

        $kisCallCount = 0;

        foreach ($stockIds as $stockId) {
            $stock = $stocks->get($stockId);
            if ($stock === null) {
                continue;
            }

            $row = $existing->get($stockId);

            // updated_at 이 임계값보다 최신이면 스킵 (신선)
            if ($row !== null && $row->updated_at !== null && $row->updated_at->gt($staleThreshold)) {
                continue;
            }

            $provider = $this->resolveProvider($stock);
            if ($provider === null) {
                Log::debug("[PriceService] 종목 {$stock->symbol} 지원 provider 없음");
                continue;
            }

            // KIS rate limit 방지: 두 번째 호출부터 100ms 대기
            if ($kisCallCount > 0) {
                usleep(self::KIS_CALL_INTERVAL_US);
            }
            $kisCallCount++;

            $quote = $provider->fetchQuote($stock, $session);
            if ($quote === null) {
                // 휴장·API 장애 — 갱신 스킵, 마지막값 유지
                Log::debug("[PriceService] {$stock->symbol} quote null — 갱신 스킵");
                continue;
            }

            $this->upsertPrice($stockId, $session, $quote);
        }

        // 갱신 후 최신값 재조회
        return $this->latest($stockIds, $session);
    }

    /**
     * prices 테이블에서 최신값만 조회 (KIS 호출 없음).
     *
     * @param  int[]  $stockIds
     * @param  string $session
     * @return array  [stock_id => price_data]
     */
    public function latest(array $stockIds, string $session): array
    {
        if (empty($stockIds)) {
            return [];
        }

        $rows = Price::whereIn('stock_id', $stockIds)
            ->where('session', $session)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            // regular_close: US 종목의 직전 정규장 종가.
            // KR 종목 또는 아직 저장되지 않은 경우 null.
            $regularClose = ($row->regular_close !== null && (float)$row->regular_close > 0)
                ? (float)$row->regular_close
                : null;

            $result[(int)$row->stock_id] = [
                'stock_id'       => (int)$row->stock_id,
                'price'          => (float)$row->price,
                'regular_close'  => $regularClose,
                'change_amount'  => (float)($row->change_amount ?? 0),
                'change_percent' => (float)($row->change_percent ?? 0),
                'recorded_at'    => $row->recorded_at ? $row->recorded_at->toDateTimeString() : null,
                'updated_at'     => $row->updated_at ? $row->updated_at->toDateTimeString() : null,
                'stale'          => false,
            ];
        }

        return $result;
    }

    /**
     * prices 테이블 upsert: (stock_id, session) 고유 키로 최신 1건 유지.
     *
     * regular_close: US 종목의 직전 정규장 종가(KIS output.base).
     *   KisOverseasQuoteProvider 가 채워 반환하면 저장,
     *   없으면(KR 종목 등) null 저장.
     *
     * @param array{
     *   price:float,
     *   regular_close?:float|null,
     *   change_amount:float,
     *   change_percent:float,
     *   recorded_at:string
     * } $quote
     */
    public function upsertPrice(int $stockId, string $session, array $quote): void
    {
        $now = Carbon::now();

        // regular_close 는 선택 필드 — 없으면 null
        $regularClose = isset($quote['regular_close']) && (float)$quote['regular_close'] > 0
            ? $quote['regular_close']
            : null;

        DB::table('prices')->upsert(
            [
                'stock_id'       => $stockId,
                'session'        => $session,
                'price'          => $quote['price'],
                'regular_close'  => $regularClose,
                'change_amount'  => $quote['change_amount'],
                'change_percent' => $quote['change_percent'],
                'recorded_at'    => $quote['recorded_at'],
                'updated_at'     => $now->toDateTimeString(),
            ],
            ['stock_id', 'session'],          // unique 키 컬럼
            ['price', 'regular_close', 'change_amount', 'change_percent', 'recorded_at', 'updated_at']  // 갱신 컬럼
        );
    }

    /**
     * market 에 따라 적절한 QuoteProvider 반환.
     * KR → KisDomesticQuoteProvider
     * US → KisOverseasQuoteProvider
     * 기타 → null
     */
    private function resolveProvider(Stock $stock): ?QuoteProviderInterface
    {
        if ($stock->market === 'KR') {
            return $this->domestic;
        }

        if ($stock->market === 'US') {
            return $this->overseas;
        }

        return null;
    }
}
