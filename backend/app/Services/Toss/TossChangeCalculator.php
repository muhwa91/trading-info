<?php

declare(strict_types=1);

namespace App\Services\Toss;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스 /prices 응답에 없는 등락액·등락률을 직접 계산.
 *
 * 전략:
 *   - `/api/v1/candles?symbol=&interval=1d&count=2` 로 직전 완료봉 종가(prevClose) 취득
 *   - 캐시 키 `toss_prev_close_{symbol}` — TTL = 당일 자정까지(KST) 초
 *   - 종목당 1일 1~2회 조회면 충분
 *
 * 등락 계산:
 *   change_amount  = lastPrice − prevClose
 *   change_percent = change_amount / prevClose × 100
 *   부호는 차액 부호 자동 반영 (KIS sign 4/5 분기 불필요)
 *
 * 설계 §2.4 준거.
 * prevClose 없을 시 0 반환(graceful fallback) — 캐시 기아 시 UI 에 0% 표시.
 *
 * 보안:
 *   응답 캔들 값만 로그 (토큰·시크릿 없음 — TossApiClient 책임).
 */
class TossChangeCalculator
{
    /** 캐시 키 접두 */
    private const CACHE_PREFIX = 'toss_prev_close_';

    /** 캔들 엔드포인트 */
    private const CANDLES_ENDPOINT = '/api/v1/candles';

    private TossApiClient $client;

    public function __construct(TossApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * prevClose 를 기반으로 등락액·등락률을 계산하여 반환한다.
     *
     * prevClose 캐시 miss 시 /candles 호출 후 캐시 저장.
     * API 실패 시 graceful — change=0, percent=0.
     *
     * @param  string  $tossSymbol  토스 API 심볼 (국내: 005930 등)
     * @param  float   $lastPrice   토스 /prices 에서 받은 현재가
     * @return array{change_amount:float,change_percent:float,prev_close:float|null}
     */
    public function calculate(string $tossSymbol, float $lastPrice): array
    {
        $prevClose = $this->getPrevClose($tossSymbol);

        if ($prevClose === null || $prevClose <= 0.0) {
            return [
                'change_amount'  => 0.0,
                'change_percent' => 0.0,
                'prev_close'     => null,
            ];
        }

        $changeAmount  = $lastPrice - $prevClose;
        $changePercent = ($changeAmount / $prevClose) * 100.0;

        return [
            'change_amount'  => round($changeAmount, 4),
            'change_percent' => round($changePercent, 4),
            'prev_close'     => $prevClose,
        ];
    }

    /**
     * 전일 종가를 반환한다 (캐시 우선, miss 시 /candles 호출).
     *
     * TTL: 당일 KST 자정까지 남은 초.
     * 국내 장(KST 09:00~15:30) 기준 — 토스 1d 봉이 완료된 것만 취득.
     *
     * @return float|null  prevClose (없으면 null)
     */
    public function getPrevClose(string $tossSymbol): ?float
    {
        $cacheKey = self::CACHE_PREFIX . $tossSymbol;
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            return (float) $cached;
        }

        return $this->fetchAndCachePrevClose($tossSymbol);
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * /candles 를 호출해 직전 완료봉 종가를 캐시에 저장 후 반환.
     *
     * count=2 로 최근 2봉을 받아 직전 봉의 closePrice 를 사용.
     *
     * 토스 /candles 실측 응답 구조 (2026-06-24 검증):
     *   {
     *     "result": {
     *       "candles": [
     *         { "timestamp": "2026-06-24T...", "openPrice": "...", "highPrice": "...",
     *           "lowPrice": "...", "closePrice": "...", "volume": "...", "currency": "KRW" },
     *         { "timestamp": "2026-06-23T...", ... }
     *       ],
     *       "nextBefore": "..."
     *     }
     *   }
     *
     * 정렬: 최신 봉(index 0) → 오래된 봉(index 1). timestamp 기준 내림차순.
     * 즉 index 1 이 "직전 완료 거래일" 종가 = prevClose.
     *
     * 휴장일 가짜봉 방지:
     *   /candles 는 실제 거래일 봉만 반환. 주말·공휴일에는 당일 봉이 없으므로
     *   count=2 면 "직전 2 거래일" 봉이 오고, index 0 이 마지막 거래일, index 1 이 그 전날.
     *   자연스럽게 "마지막 완료 거래일"의 전일 종가를 얻을 수 있다.
     */
    private function fetchAndCachePrevClose(string $tossSymbol): ?float
    {
        try {
            $response = $this->client->get(self::CANDLES_ENDPOINT, [
                'symbol'   => $tossSymbol,
                'interval' => '1d',
                'count'    => 2,
            ]);

            if (empty($response)) {
                Log::warning("[TossChangeCalculator] /candles 빈응답: {$tossSymbol}");
                return null;
            }

            // 실측 구조: result.candles 배열
            $result  = $response['result'] ?? null;
            $candles = null;

            if (is_array($result) && isset($result['candles'])) {
                // 정상 응답: { result: { candles: [...] } }
                $candles = $result['candles'];
            } elseif (is_array($result) && !isset($result['candles'])) {
                // result 가 직접 배열인 경우 (키가 숫자 인덱스) — 호환 처리
                $candles = $result;
            } elseif (isset($response['candles'])) {
                // 루트에 candles 가 있는 경우
                $candles = $response['candles'];
            }

            if (!is_array($candles) || count($candles) < 2) {
                Log::debug("[TossChangeCalculator] {$tossSymbol} 봉 데이터 부족: " . json_encode(array_keys($response)));
                return null;
            }

            // 봉 정렬: timestamp 기준 내림차순(최신 먼저) 확인 후 오래된 봉 선택
            // 실측: index 0 = 최신(당일), index 1 = 전일 → prevClose = index 1
            // 안전하게 timestamp 비교 정렬 후 index 1 선택
            usort($candles, function (array $a, array $b): int {
                // timestamp 는 ISO 8601 문자열 — 문자열 비교로 내림차순 정렬
                $tA = (string) ($a['timestamp'] ?? '');
                $tB = (string) ($b['timestamp'] ?? '');
                return strcmp($tB, $tA);  // 내림차순 (최신 먼저)
            });

            // index 0 = 최신 봉(당일), index 1 = 직전 봉(prevClose)
            $prevCandle = $candles[1];
            $prevClose  = isset($prevCandle['closePrice']) ? (float) $prevCandle['closePrice'] : null;

            if ($prevClose === null || $prevClose <= 0.0) {
                Log::warning("[TossChangeCalculator] {$tossSymbol} prevClose 이상: " . json_encode($prevCandle));
                return null;
            }

            // TTL: 당일 KST 자정까지 남은 초
            $ttl = $this->secondsUntilKstMidnight();

            Cache::put(self::CACHE_PREFIX . $tossSymbol, $prevClose, $ttl);

            Log::debug("[TossChangeCalculator] prevClose 캐싱", [
                'symbol'    => $tossSymbol,
                'prevClose' => $prevClose,
                'ttl'       => $ttl,
            ]);

            return $prevClose;
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} 캔들 조회 실패: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 당일 KST(Asia/Seoul) 자정까지 남은 초를 계산한다.
     *
     * 최솟값 300초(5분) — 자정 직전에도 너무 짧은 TTL 방지.
     */
    private function secondsUntilKstMidnight(): int
    {
        $now      = Carbon::now('Asia/Seoul');
        $midnight = $now->copy()->endOfDay()->addSecond();  // 다음날 00:00:00 KST
        $seconds  = max(300, (int) $now->diffInSeconds($midnight, false));

        return $seconds;
    }
}
