<?php

declare(strict_types=1);

namespace App\Services\Toss;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스증권 /prices 배치 현재가 페처 — 국내(KR) + 미국(US) (Phase 4).
 *
 * 책임:
 *   - `/api/v1/prices?symbols=` 배치 호출 (≤195 콤마구분 청크)
 *   - TossSymbolMapper 로 앱심볼 → 토스심볼 변환 (국내: .KS/.KQ 제거)
 *   - 지수(NQ=F, KOSPI200 등) skip
 *   - 캐시 키 하위호환:
 *     - KR: `kis_realtime_price_{ticker}` (TTL 8초)
 *     - US: `kis_realtime_price_us_{ticker}` (TTL 8초)
 *     → StockController::getStockData · 프론트 무변경으로 읽힘
 *   - 폴백 키 하위호환:
 *     - KR: `kis_last_successful_price_{ticker}` (TTL 86400)
 *     - US: `kis_last_successful_overseas_price_{ticker}` (TTL 86400)
 *   - 미국 단건 조회: 토스 → Yahoo → 24h 캐시 폴백 체인
 *
 * 설계 §2.3 · §2.5 준거.
 * 캐시 키 `kis_` 접두는 하위호환 목적으로 유지 (Phase 6 정리 예정).
 */
class TossPriceFetcher
{
    /** 배치 청크 크기 — 토스 최대 200에서 여유 마진 5 */
    private const CHUNK_SIZE = 195;

    /** 현재가 캐시 TTL(초) — KisParallelPriceFetcher::PRICE_CACHE_TTL 과 동일 */
    private const PRICE_CACHE_TTL = 8;

    /** 폴백 캐시 TTL(초) — 24h */
    private const FALLBACK_CACHE_TTL = 86400;

    /** 토스 현재가 엔드포인트 */
    private const PRICES_ENDPOINT = '/api/v1/prices';

    /** Yahoo Finance v8 chart endpoint 기본 URL */
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    /** Yahoo 폴백 요청 타임아웃(초) */
    private const YAHOO_TIMEOUT = 5;

    private TossApiClient $client;
    private TossSymbolMapper $mapper;
    private TossChangeCalculator $changeCalculator;

    public function __construct(
        TossApiClient $client,
        TossSymbolMapper $mapper,
        TossChangeCalculator $changeCalculator
    ) {
        $this->client           = $client;
        $this->mapper           = $mapper;
        $this->changeCalculator = $changeCalculator;
    }

    /**
     * 국내·미국 종목 현재가를 배치 조회하여 캐시에 저장한다.
     *
     * 지수(INDEX)는 skip. 캐시 유효 종목은 네트워크 불필요.
     * 마켓별 캐시 키를 달리 사용한다 (KR/US).
     *
     * @param  string[]  $tickers  앱 내부 심볼 배열 (국내·해외 혼재 가능)
     * @return array{fetched:int, cached:int, skipped:int, failed:int}
     */
    public function fetchDomestic(array $tickers): array
    {
        if (empty($tickers)) {
            return ['fetched' => 0, 'cached' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // 조회 대상 필터: 지수 제외, 캐시 유효 제외
        // appSymbol => ['toss' => tossSymbol, 'market' => market]
        $toFetch      = [];
        $cachedCount  = 0;
        $skippedCount = 0;

        foreach ($tickers as $appSymbol) {
            $appSymbol = (string) $appSymbol;

            // 지수 skip
            if ($this->mapper->shouldSkip($appSymbol)) {
                $skippedCount++;
                continue;
            }

            $market = $this->mapper->market($appSymbol);

            // 마켓별 캐시 키 결정
            if ($market === 'KR') {
                $cacheKey = "kis_realtime_price_{$appSymbol}";
            } elseif ($market === 'US') {
                $cacheKey = "kis_realtime_price_us_{$appSymbol}";
            } else {
                // KR/US 외(INDEX 등) — shouldSkip()으로 이미 걸러지지 않은 경우 방어
                $skippedCount++;
                continue;
            }

            // 캐시 히트: 네트워크 불필요
            if (Cache::has($cacheKey)) {
                $cachedCount++;
                continue;
            }

            $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
            if ($tossSymbol === null) {
                $skippedCount++;
                continue;
            }

            $toFetch[$appSymbol] = ['toss' => $tossSymbol, 'market' => $market];
        }

        if (empty($toFetch)) {
            return ['fetched' => 0, 'cached' => $cachedCount, 'skipped' => $skippedCount, 'failed' => 0];
        }

        // tossSymbol → ['appSymbol' => ..., 'market' => ...] 역매핑 (응답 파싱 시 캐시 키 복원용)
        $reverseMap = [];
        foreach ($toFetch as $appSym => $info) {
            $upperToss              = strtoupper($info['toss']);
            $reverseMap[$upperToss] = ['appSymbol' => $appSym, 'market' => $info['market']];
        }

        // 청크는 tossSymbol 값만 추출해 구성
        $chunks  = array_chunk($toFetch, self::CHUNK_SIZE, true);
        $fetched = 0;
        $failed  = 0;

        foreach ($chunks as $chunk) {
            $tossSymbols = implode(',', array_map(fn($v) => $v['toss'], $chunk));

            $response = $this->client->get(self::PRICES_ENDPOINT, [
                'symbols' => $tossSymbols,
            ]);

            if (empty($response)) {
                Log::warning('[TossPriceFetcher] /prices 응답 빈값', ['symbols' => $tossSymbols]);
                $failed += count($chunk);
                continue;
            }

            $results = $response['result'] ?? $response;
            if (!is_array($results)) {
                Log::warning('[TossPriceFetcher] /prices result 형식 이상', ['keys' => array_keys($response)]);
                $failed += count($chunk);
                continue;
            }

            // result 가 연관배열(단건)인 경우 배열로 감싸기
            if (isset($results['symbol'])) {
                $results = [$results];
            }

            foreach ($results as $item) {
                $tossSymbol = (string) ($item['symbol'] ?? '');
                if ($tossSymbol === '') {
                    $failed++;
                    continue;
                }

                $lastPrice = isset($item['lastPrice']) ? (float) $item['lastPrice'] : null;
                if ($lastPrice === null || $lastPrice <= 0) {
                    Log::debug("[TossPriceFetcher] {$tossSymbol} lastPrice 없음 또는 0");
                    $failed++;
                    continue;
                }

                // 앱 심볼 복원: reverseMap 에서 찾기
                // toTossSymbol() 은 대문자화하므로 대소문자 무관하게 검색
                $upperToss = strtoupper($tossSymbol);
                $mapped    = $reverseMap[$upperToss] ?? $reverseMap[$tossSymbol] ?? null;

                if ($mapped === null) {
                    // 청크 내 다른 심볼로 요청했는데 응답에 없는 경우는 정상 skip
                    Log::debug("[TossPriceFetcher] 역매핑 없음: {$tossSymbol}");
                    continue;
                }

                $appSymbol = $mapped['appSymbol'];
                $market    = $mapped['market'];

                // 등락 계산 (TossChangeCalculator: 국내 기준 자정 TTL 캐싱)
                $change        = $this->changeCalculator->calculate($tossSymbol, $lastPrice);
                $changeAmount  = $change['change_amount'];
                $changePercent = $change['change_percent'];

                // US 종목은 regular_close(최근 완료 정규장 종가)를 포함
                // — Yahoo chartPreviousClose 기반으로 프리마켓/애프터에서도 정확한 값 제공
                $regularClose = null;
                if ($market === 'US') {
                    $regularClose = $this->fetchYahooRegularClose($appSymbol);
                }

                $result = [
                    'price'          => $lastPrice,
                    'change_amount'  => $changeAmount,
                    'change_percent' => $changePercent,
                    'regular_close'  => $regularClose,
                ];

                // 마켓별 하위호환 캐시 키 — StockController·프론트 무변경
                if ($market === 'US') {
                    $cacheKey    = "kis_realtime_price_us_{$appSymbol}";
                    $fallbackKey = "kis_last_successful_overseas_price_{$appSymbol}";
                } else {
                    $cacheKey    = "kis_realtime_price_{$appSymbol}";
                    $fallbackKey = "kis_last_successful_price_{$appSymbol}";
                }

                Cache::put($cacheKey, $result, self::PRICE_CACHE_TTL);
                Cache::put($fallbackKey, $result, self::FALLBACK_CACHE_TTL);

                $fetched++;
            }
        }

        Log::debug('[TossPriceFetcher] 배치 완료', [
            'fetched'  => $fetched,
            'cached'   => $cachedCount,
            'skipped'  => $skippedCount,
            'failed'   => $failed,
        ]);

        return [
            'fetched' => $fetched,
            'cached'  => $cachedCount,
            'skipped' => $skippedCount,
            'failed'  => $failed,
        ];
    }

    /**
     * 미국 종목 현재가를 동기 조회하여 반환한다 (REST 단건 경로 — StockController 미국 경로용).
     *
     * 폴백 체인: 토스 → Yahoo → 24h 캐시.
     * 프리마켓 포함: Yahoo includePrePost=true.
     *
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:float|null}|null
     */
    public function fetchOverseasSingle(string $appSymbol): ?array
    {
        // 지수 또는 US 외 종목은 처리 불가
        if ($this->mapper->shouldSkip($appSymbol) || $this->mapper->market($appSymbol) !== 'US') {
            return null;
        }

        $cacheKey    = "kis_realtime_price_us_{$appSymbol}";
        $fallbackKey = "kis_last_successful_overseas_price_{$appSymbol}";

        // 캐시 히트 시 즉시 반환
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
        if ($tossSymbol === null) {
            return Cache::get($fallbackKey);
        }

        $response = $this->client->get(self::PRICES_ENDPOINT, [
            'symbols' => $tossSymbol,
        ]);

        if (!empty($response)) {
            $results = $response['result'] ?? $response;
            if (isset($results['symbol'])) {
                $results = [$results];
            }

            if (is_array($results) && !empty($results)) {
                $item      = $results[0];
                $lastPrice = isset($item['lastPrice']) ? (float) $item['lastPrice'] : null;

                if ($lastPrice !== null && $lastPrice > 0) {
                    $change        = $this->changeCalculator->calculate($tossSymbol, $lastPrice);
                    $changeAmount  = $change['change_amount'];
                    $changePercent = $change['change_percent'];

                    $regularClose = $this->fetchYahooRegularClose($appSymbol);

                    $result = [
                        'price'          => $lastPrice,
                        'change_amount'  => $changeAmount,
                        'change_percent' => $changePercent,
                        'regular_close'  => $regularClose,
                    ];

                    Cache::put($cacheKey, $result, self::PRICE_CACHE_TTL);
                    Cache::put($fallbackKey, $result, self::FALLBACK_CACHE_TTL);

                    return $result;
                }
            }
        }

        // 토스 실패 시 Yahoo 폴백
        $yahooResult = $this->fetchYahooCurrentPrice($appSymbol);
        if ($yahooResult !== null) {
            Cache::put($fallbackKey, $yahooResult, self::FALLBACK_CACHE_TTL);
            return $yahooResult;
        }

        // 모두 실패 시 24h 캐시 반환
        return Cache::get($fallbackKey);
    }

    /**
     * 단일 종목 현재가를 동기 조회하여 반환한다 (REST 단건 경로 — StockController 모달용).
     *
     * 캐시에 있으면 즉시 반환. 없으면 /prices 단건 호출.
     * US 종목은 fetchOverseasSingle 에 위임.
     *
     * @return array{price:float,change_amount:float,change_percent:float}|null
     */
    public function fetchSingle(string $appSymbol): ?array
    {
        // 지수 skip
        if ($this->mapper->shouldSkip($appSymbol)) {
            return null;
        }

        $market = $this->mapper->market($appSymbol);

        if ($market === 'US') {
            return $this->fetchOverseasSingle($appSymbol);
        }

        // 이하 기존 KR 로직 유지
        if ($market !== 'KR') {
            return null;
        }

        $cacheKey    = "kis_realtime_price_{$appSymbol}";
        $fallbackKey = "kis_last_successful_price_{$appSymbol}";

        // 캐시 히트
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
        if ($tossSymbol === null) {
            return Cache::get($fallbackKey);
        }

        $response = $this->client->get(self::PRICES_ENDPOINT, [
            'symbols' => $tossSymbol,
        ]);

        if (empty($response)) {
            Log::warning("[TossPriceFetcher] 단건 /prices 빈응답: {$appSymbol}");
            return Cache::get($fallbackKey);
        }

        $results = $response['result'] ?? $response;
        if (isset($results['symbol'])) {
            $results = [$results];
        }
        if (!is_array($results) || empty($results)) {
            return Cache::get($fallbackKey);
        }

        $item      = $results[0];
        $lastPrice = isset($item['lastPrice']) ? (float) $item['lastPrice'] : null;

        if ($lastPrice === null || $lastPrice <= 0) {
            return Cache::get($fallbackKey);
        }

        $change        = $this->changeCalculator->calculate($tossSymbol, $lastPrice);
        $changeAmount  = $change['change_amount'];
        $changePercent = $change['change_percent'];

        $result = [
            'price'          => $lastPrice,
            'change_amount'  => $changeAmount,
            'change_percent' => $changePercent,
        ];

        Cache::put($cacheKey, $result, self::PRICE_CACHE_TTL);
        Cache::put($fallbackKey, $result, self::FALLBACK_CACHE_TTL);

        return $result;
    }

    /**
     * Yahoo Finance v8 chart meta.regularMarketPrice (세션별 정규장 기준가) 조회.
     *
     * 캐시 키: "yahoo_regular_close_{symbol}" — ET 자정까지 TTL.
     * 실패 시 null 반환 (graceful).
     *
     * 왜 regularMarketPrice 인가 (실측 검증: MU 1051.77, TSLA 381.61):
     *   - 프리마켓 시간대: 어제 정규장 종가 반환 (전일 기준가로 손익 계산 정확)
     *   - 정규장 진행 중: 현재 정규장 거래가 반환
     *   - 애프터마켓 시간대: 오늘 정규장 종가 반환
     *   → 세션을 직접 판별하지 않아도 항상 correct 한 정규장 기준가를 반환.
     *   - chartPreviousClose: range 시작 직전 기준(여러 봉 밀림)이라 부정확 → 사용 안 함.
     *     range=5d 요청 시 5 거래일 전 종가가 될 수 있음.
     *
     * @param string $symbol 앱 심볼 (미국 티커, 예: TSLA)
     * @return float|null
     */
    private function fetchYahooRegularClose(string $symbol): ?float
    {
        $cacheKey = "yahoo_regular_close_{$symbol}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float) $cached;
        }

        try {
            $httpClient = new Client();
            // range=5d + includePrePost: meta.regularMarketPrice 가 세션별 정규장 기준가를 정확히 반영
            $url = self::YAHOO_CHART_URL . urlencode($symbol) . '?interval=1d&range=5d&includePrePost=true';

            $res = $httpClient->get($url, [
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'http_errors' => false,
                'timeout'     => self::YAHOO_TIMEOUT,
            ]);

            $data = json_decode((string) $res->getBody(), true);
            $meta = $data['chart']['result'][0]['meta'] ?? null;

            if ($meta === null) {
                return null;
            }

            // regularMarketPrice = Yahoo 가 세션에 맞춰 주는 정규장 기준가:
            //   정규장 중 = 현재 정규장가 / 애프터 = 오늘 종가 / 프리마켓 = 어제 정규장 종가.
            // chartPreviousClose 는 range 시작 직전(여러 봉 밀림)이라 부정확 → 폴백 2순위로만 유지.
            $price = $meta['regularMarketPrice']
                ?? $meta['regularMarketPreviousClose']
                ?? $meta['chartPreviousClose']
                ?? null;

            if ($price === null || (float) $price <= 0) {
                Log::debug("[TossPriceFetcher] {$symbol} Yahoo regularClose: regularMarketPrice 없음, meta 키=" . implode(',', array_keys($meta)));
                return null;
            }

            $price = (float) $price;

            // ET 자정까지 남은 초 (최소 300초)
            $nyTz     = new \DateTimeZone('America/New_York');
            $midnight = new \DateTime('tomorrow', $nyTz);
            $ttl      = max($midnight->getTimestamp() - time(), 300);

            Cache::put($cacheKey, $price, $ttl);

            return $price;
        } catch (\Throwable $e) {
            Log::warning("[TossPriceFetcher] {$symbol} Yahoo regularClose 실패: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Yahoo Finance v8 chart에서 장외 포함 현재가를 폴백으로 조회.
     *
     * includePrePost=true로 장외 시세 포함.
     * 폴백 체인 마지막 단계 — 토스 실패 시에만 호출.
     *
     * @param string $symbol 미국 티커
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:float|null}|null
     */
    private function fetchYahooCurrentPrice(string $symbol): ?array
    {
        try {
            $httpClient = new Client();
            $url        = self::YAHOO_CHART_URL . urlencode($symbol) . '?interval=1d&range=5d&includePrePost=true';

            $res  = $httpClient->get($url, [
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'http_errors' => false,
                'timeout'     => self::YAHOO_TIMEOUT,
            ]);

            $data = json_decode((string) $res->getBody(), true);
            $meta = $data['chart']['result'][0]['meta'] ?? null;

            if ($meta === null) {
                return null;
            }

            // regularMarketPrice를 현재가로 사용 (Yahoo meta는 실시간 반영됨)
            $price = isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null;

            if ($price === null || $price <= 0) {
                return null;
            }

            $prevClose = isset($meta['chartPreviousClose'])
                ? (float) $meta['chartPreviousClose']
                : (isset($meta['regularMarketPreviousClose']) ? (float) $meta['regularMarketPreviousClose'] : null);

            if ($prevClose !== null && $prevClose > 0) {
                $changeAmount  = $price - $prevClose;
                $changePercent = $changeAmount / $prevClose * 100;
            } else {
                $changeAmount  = 0.0;
                $changePercent = 0.0;
            }

            $regularClose = $this->fetchYahooRegularClose($symbol);

            return [
                'price'          => $price,
                'change_amount'  => $changeAmount,
                'change_percent' => $changePercent,
                'regular_close'  => $regularClose,
            ];
        } catch (\Throwable $e) {
            Log::warning("[TossPriceFetcher] {$symbol} Yahoo 현재가 폴백 실패: " . $e->getMessage());
            return null;
        }
    }
}
