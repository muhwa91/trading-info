<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * KIS 현재가를 여러 종목 동시에(Guzzle Pool) 조회해 캐시에 저장하는 서비스.
 *
 * 목적:
 *   WebSocketAgentServer 의 푸시 사이클에서 순차(sequential) 네트워크 호출이
 *   사이클 전체를 ~10초 이상으로 늘리는 병목을 제거한다.
 *   이 서비스가 한 번의 Guzzle Pool 로 모든 종목을 동시에 조회하면,
 *   사이클이 단일 요청 레이턴시(~300~500ms) 수준으로 단축된다.
 *
 * 동시성 상한 (MAX_CONCURRENCY):
 *   KIS 초당 거래건수 제한(약 20건/s, 실전 기준)을 안전하게 준수하기 위해
 *   동시 요청을 8~10개로 제한한다. Pool 이 이를 내부적으로 순서 관리한다.
 *   종목 수가 늘어도 총 실행 시간이 ceil(N/8) × (평균 레이턴시) 이하로 유지된다.
 *
 * 캐시 TTL:
 *   국내 현재가: "kis_realtime_price_{ticker}"        TTL 3초  — StockController 와 동일
 *   해외 현재가: "kis_realtime_price_us_{ticker}"    TTL 3초  — StockController 와 동일
 *   조회 결과를 캐시에 미리 채워두면, 이후 getStockData() 호출이 캐시만 읽는다.
 */
class KisParallelPriceFetcher
{
    /** 동시 HTTP 요청 최대 수 (KIS 레이트리밋 대비) */
    private const MAX_CONCURRENCY = 8;

    /** KIS 현재가 캐시 TTL(초) — StockController 와 동일해야 함 */
    private const PRICE_CACHE_TTL = 3;

    /**
     * 주어진 종목 목록의 KIS 현재가를 병렬 조회해 각각 캐시에 저장한다.
     *
     * @param  string[] $tickers   조회할 종목 심볼 배열 (국내·해외 혼재 가능)
     * @param  string   $apiUrl    KIS API 기본 URL
     * @param  string   $appKey    KIS APP KEY
     * @param  string   $appSecret KIS APP SECRET
     * @param  string   $token     KIS 액세스 토큰
     * @return array{fetched:int, cached:int, failed:int}  결과 요약 (로그용)
     */
    public function fetchAll(
        array $tickers,
        string $apiUrl,
        string $appKey,
        string $appSecret,
        string $token
    ): array {
        if (empty($tickers)) {
            return ['fetched' => 0, 'cached' => 0, 'failed' => 0];
        }

        // 세션 판정 — 해외 종목의 주간거래(Blue Ocean) 여부를 결정한다.
        $session = $this->resolveUsSession();
        $isOvernight = ($session === '주간거래');

        // 지수·환율 등 KIS 현재가 불필요 종목 제외
        $skipList = ['NQ=F', '^KS200', 'USDKRW=X', 'KOSPI_NIGHT', 'KOSPI200'];

        // 국내/해외 분류 및 캐시 유효 여부 확인
        $domestic = [];   // 국내: 키 => ticker
        $overseas = [];   // 해외: 키 => ticker

        foreach ($tickers as $ticker) {
            if (in_array($ticker, $skipList, true)) {
                continue;
            }

            // 국내 종목 판정: .KS/.KQ 접미사, 또는 6자리 숫자/영숫자(예: 0167A0)
            if (
                preg_match('/(\.KS|\.KQ)$/i', $ticker)
                || preg_match('/^\d{4}[0-9A-Z]{2}$/', $ticker)
                || preg_match('/^\d+$/', $ticker)
            ) {
                $cacheKey = "kis_realtime_price_{$ticker}";
            } else {
                $cacheKey = "kis_realtime_price_us_{$ticker}";
            }

            // 캐시에 이미 유효한 값이 있으면 네트워크 호출 불필요
            if (Cache::has($cacheKey)) {
                continue;
            }

            if (
                preg_match('/(\.KS|\.KQ)$/i', $ticker)
                || preg_match('/^\d{4}[0-9A-Z]{2}$/', $ticker)
                || preg_match('/^\d+$/', $ticker)
            ) {
                $domestic[$ticker] = $ticker;
            } else {
                $overseas[$ticker] = $ticker;
            }
        }

        $fetched = 0;
        $failed = 0;

        // ── 국내 종목 병렬 조회 ───────────────────────────────────────────────
        if (!empty($domestic)) {
            $result = $this->fetchDomesticBatch(
                array_values($domestic),
                $apiUrl, $appKey, $appSecret, $token
            );
            $fetched += $result['fetched'];
            $failed  += $result['failed'];
        }

        // ── 해외 종목 병렬 조회 ───────────────────────────────────────────────
        if (!empty($overseas)) {
            $result = $this->fetchOverseasBatch(
                array_values($overseas),
                $apiUrl, $appKey, $appSecret, $token,
                $isOvernight
            );
            $fetched += $result['fetched'];
            $failed  += $result['failed'];
        }

        // 캐시 히트(이미 유효) 종목 수
        $totalRequested = count($tickers) - count(array_intersect($tickers, $skipList));
        $cached = max(0, $totalRequested - count($domestic) - count($overseas));

        return ['fetched' => $fetched, 'cached' => $cached, 'failed' => $failed];
    }

    /**
     * 국내 종목 현재가 병렬 조회.
     * KIS inquire-price (국내주식현재가) — tr_id: VHPST01010000 / FHPST01010000
     */
    private function fetchDomesticBatch(
        array $tickers,
        string $apiUrl,
        string $appKey,
        string $appSecret,
        string $token
    ): array {
        $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHPST01010000' : 'FHPST01010000';
        $client = new Client(['timeout' => 5.0, 'connect_timeout' => 3.0]);
        $fetched = 0;
        $failed  = 0;

        // Guzzle Pool 용 요청 제너레이터
        $requests = function () use ($tickers, $apiUrl, $appKey, $appSecret, $token, $trId, $client) {
            foreach ($tickers as $ticker) {
                $code = preg_replace('/(\.KS|\.KQ)$/i', '', $ticker);
                // Pool 에서 Fulfilled 콜백으로 ticker 를 참조하기 위해 key 를 ticker 로 설정
                yield $ticker => new GuzzleRequest(
                    'GET',
                    "{$apiUrl}/uapi/domestic-stock/v1/quotations/inquire-price?" . http_build_query([
                        'FID_COND_MRKT_DIV_CODE' => 'J',
                        'FID_INPUT_ISCD' => $code,
                    ]),
                    [
                        'content-type'  => 'application/json',
                        'authorization' => "Bearer {$token}",
                        'appkey'        => $appKey,
                        'appsecret'     => $appSecret,
                        'tr_id'         => $trId,
                    ]
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => self::MAX_CONCURRENCY,
            'fulfilled' => function ($response, $ticker) use (&$fetched) {
                try {
                    $data = json_decode($response->getBody()->getContents(), true);

                    if (!isset($data['output']['stck_prpr'])) {
                        Log::debug("[KisParallelPriceFetcher] 국내 {$ticker} 응답 이상(price 없음)");
                        return;
                    }

                    $price  = (float)$data['output']['stck_prpr'];
                    $change = (float)$data['output']['prdy_vrss'];
                    $pct    = (float)$data['output']['prdy_ctrt'];
                    $sign   = $data['output']['prdy_vrss_sign'] ?? '3';

                    if ($sign === '4' || $sign === '5') {
                        $change = -abs($change);
                        $pct    = -abs($pct);
                    }

                    $result = [
                        'price'          => $price,
                        'change_amount'  => $change,
                        'change_percent' => $pct,
                    ];

                    // StockController 와 동일한 캐시 키·TTL
                    $cacheKey = "kis_realtime_price_{$ticker}";
                    Cache::put($cacheKey, $result, self::PRICE_CACHE_TTL);

                    // 24시간 폴백 캐시도 갱신
                    Cache::put("kis_last_successful_price_{$ticker}", $result, 86400);

                    $fetched++;
                } catch (\Throwable $e) {
                    Log::debug("[KisParallelPriceFetcher] 국내 {$ticker} 응답 파싱 실패: " . $e->getMessage());
                }
            },
            'rejected' => function ($reason, $ticker) use (&$failed) {
                Log::debug("[KisParallelPriceFetcher] 국내 {$ticker} 요청 실패: " . (string)$reason);
                $failed++;
            },
        ]);

        // Pool 실행 — wait() 가 완료될 때까지 블로킹 (내부적으로 curl_multi 비동기)
        $pool->promise()->wait();

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    /**
     * 해외 종목 현재가 병렬 조회.
     * KIS overseas-price (HHDFS00000300) — EXCD 캐시로 1차 적중률 극대화.
     *
     * $isOvernight=true  → BAQ/BAY/BAA 우선 (주간거래)
     * $isOvernight=false → NAS/NYS/AMS 우선 (정규장/프리/애프터)
     */
    private function fetchOverseasBatch(
        array $tickers,
        string $apiUrl,
        string $appKey,
        string $appSecret,
        string $token,
        bool $isOvernight
    ): array {
        $client = new Client(['timeout' => 5.0, 'connect_timeout' => 3.0]);
        $fetched = 0;
        $failed  = 0;

        $sessionGroup  = $isOvernight ? 'overnight' : 'regular';
        $primaryExcds  = $isOvernight ? ['BAQ', 'BAY', 'BAA'] : ['NAS', 'NYS', 'AMS'];
        $fallbackExcds = $isOvernight ? ['NAS', 'NYS', 'AMS'] : ['BAQ', 'BAY', 'BAA'];

        // 각 종목에 대해 "현재 시도할 EXCD 목록" 결정 (캐시 우선)
        $tickerExcds = [];
        foreach ($tickers as $ticker) {
            $excdCacheKey = "kis_excd_{$ticker}_{$sessionGroup}";
            $cachedExcd   = Cache::get($excdCacheKey);
            if ($cachedExcd !== null) {
                $ordered = array_merge(
                    [$cachedExcd],
                    array_values(array_filter(
                        array_merge($primaryExcds, $fallbackExcds),
                        fn($e) => $e !== $cachedExcd
                    ))
                );
            } else {
                $ordered = array_merge($primaryExcds, $fallbackExcds);
            }
            $tickerExcds[$ticker] = $ordered;
        }

        // ── 1차 시도: 각 종목에 대해 "첫 번째(최선) EXCD" 로 병렬 요청 ────────
        $firstResults = $this->runOverseasPool(
            $client, $tickers, $tickerExcds,
            $apiUrl, $appKey, $appSecret, $token,
            0 // $excdIndex = 0 (첫 번째 EXCD)
        );

        // 성공한 종목 캐시 저장, 실패 종목은 폴백 EXCD 로 재시도
        $retryTickers = [];
        foreach ($firstResults as $ticker => $data) {
            if ($data['success']) {
                $this->cacheOverseasPrice($ticker, $data, $sessionGroup, $tickerExcds[$ticker][0]);
                $fetched++;
            } else {
                // 1차 EXCD 이외에 시도할 EXCD 가 남아 있으면 재시도 목록에 추가
                if (count($tickerExcds[$ticker]) > 1) {
                    $retryTickers[] = $ticker;
                } else {
                    $failed++;
                }
            }
        }

        // ── 폴백: 실패 종목을 나머지 EXCD 로 순차 재시도 (한 번에 병렬) ────────
        // 폴백은 전체 종목 중 일부(캐시 미스+1차 실패)에만 적용되므로 오버헤드 미미
        if (!empty($retryTickers)) {
            // 남은 EXCD 중 두 번째부터 시도
            for ($idx = 1; $idx <= 5; $idx++) {
                // idx 범위 내 EXCD 가 있는 종목만 추림
                $eligible = array_filter($retryTickers, fn($t) => isset($tickerExcds[$t][$idx]));
                if (empty($eligible)) {
                    break;
                }

                $results = $this->runOverseasPool(
                    $client, array_values($eligible), $tickerExcds,
                    $apiUrl, $appKey, $appSecret, $token,
                    $idx
                );

                $stillFailing = [];
                foreach ($results as $ticker => $data) {
                    if ($data['success']) {
                        $this->cacheOverseasPrice(
                            $ticker, $data, $sessionGroup, $tickerExcds[$ticker][$idx]
                        );
                        $fetched++;
                        // retryTickers 에서 제거
                        $retryTickers = array_values(array_filter($retryTickers, fn($t) => $t !== $ticker));
                    } else {
                        $stillFailing[] = $ticker;
                    }
                }
                $retryTickers = $stillFailing;
                if (empty($retryTickers)) {
                    break;
                }
            }
            $failed += count($retryTickers);
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    /**
     * 지정된 EXCD 인덱스로 종목들을 병렬 요청하고 결과 배열을 반환한다.
     *
     * @return array<string, array{success:bool, data:array|null}>  ticker => {success, data}
     */
    private function runOverseasPool(
        Client $client,
        array $tickers,
        array $tickerExcds,
        string $apiUrl,
        string $appKey,
        string $appSecret,
        string $token,
        int $excdIndex
    ): array {
        $results = [];

        $requests = function () use ($tickers, $tickerExcds, $apiUrl, $appKey, $appSecret, $token, $excdIndex) {
            foreach ($tickers as $ticker) {
                $excd = $tickerExcds[$ticker][$excdIndex] ?? null;
                if ($excd === null) {
                    continue;
                }
                yield $ticker => new GuzzleRequest(
                    'GET',
                    "{$apiUrl}/uapi/overseas-price/v1/quotations/price?" . http_build_query([
                        'AUTH' => '',
                        'EXCD' => $excd,
                        'SYMB' => $ticker,
                    ]),
                    [
                        'content-type'  => 'application/json',
                        'authorization' => "Bearer {$token}",
                        'appkey'        => $appKey,
                        'appsecret'     => $appSecret,
                        'tr_id'         => 'HHDFS00000300',
                    ]
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => self::MAX_CONCURRENCY,
            'fulfilled' => function ($response, $ticker) use (&$results, $tickerExcds, $excdIndex) {
                try {
                    $data = json_decode($response->getBody()->getContents(), true);
                    if (
                        isset($data['output']['last'])
                        && (float)$data['output']['last'] > 0
                    ) {
                        $results[$ticker] = ['success' => true, 'data' => $data['output']];
                    } else {
                        $results[$ticker] = ['success' => false, 'data' => null];
                    }
                } catch (\Throwable $e) {
                    $results[$ticker] = ['success' => false, 'data' => null];
                }
            },
            'rejected' => function ($reason, $ticker) use (&$results) {
                $results[$ticker] = ['success' => false, 'data' => null];
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * 해외 현재가 조회 결과를 캐시에 저장한다.
     * 캐시 키·TTL 은 StockController::fetchOverseasPriceFromKis() 와 동일.
     */
    private function cacheOverseasPrice(
        string $ticker,
        array $data,
        string $sessionGroup,
        string $successExcd
    ): void {
        $output = $data['data'];

        $price  = (float)$output['last'];
        $change = (float)($output['diff'] ?? 0);
        $pct    = (float)($output['rate'] ?? 0);
        $sign   = $output['sign'] ?? '3';

        if ($sign === '4' || $sign === '5') {
            $change = -abs($change);
            $pct    = -abs($pct);
        } else {
            $change = abs($change);
            $pct    = abs($pct);
        }

        $result = [
            'price'          => $price,
            'change_amount'  => $change,
            'change_percent' => $pct,
            'sign'           => $sign,
            'excd'           => $successExcd,
        ];

        Cache::put("kis_realtime_price_us_{$ticker}", $result, self::PRICE_CACHE_TTL);
        Cache::put("kis_last_successful_overseas_price_{$ticker}", $result, 86400);

        // 성공한 EXCD 를 세션 그룹별로 캐싱 → 다음 사이클 1차 적중률 극대화
        $excdCacheKey = "kis_excd_{$ticker}_{$sessionGroup}";
        if (Cache::get($excdCacheKey) !== $successExcd) {
            Cache::put($excdCacheKey, $successExcd, 86400);
        }
    }

    /**
     * Yahoo Finance 캔들 캐시를 병렬로 워밍업한다.
     *
     * getStockData() 내부는 Cache::remember("yahoo_stock_data_*", 30, ...) 로 Yahoo 캔들을
     * 30초마다 갱신한다. 캐시가 만료된 시점에 getStockData() 가 순차로 Yahoo HTTP 호출 → 지연.
     * 이 메서드를 사이클 시작 시 병렬로 먼저 실행해 캐시를 채워두면 지연이 사라진다.
     *
     * 대상:
     *   - 지수/환율(NQ=F, USDKRW=X, KOSPI200): Yahoo 캔들만 사용
     *   - 국내/해외 개별종목: Yahoo 캔들(히스토리) + KIS 현재가 이중 구조
     *     → Yahoo 캔들 캐시 키: "yahoo_stock_data_{ticker}_{timeframe}_raw" (개별종목)
     *                           "yahoo_stock_data_{ticker}_{timeframe}"    (지수)
     *
     * @param array<array{ticker:string, timeframe:string}> $pairs
     */
    public function warmYahooCandles(array $pairs): void
    {
        // 캐시가 이미 유효한 (ticker, timeframe) 는 건너뜀
        $toWarm = [];
        foreach ($pairs as $pair) {
            $ticker = $pair['ticker'];
            $tf     = $pair['timeframe'];

            // 지수/환율은 별도 캐시 키 패턴
            $isIndex = in_array($ticker, ['NQ=F', '^KS200', 'USDKRW=X', 'KOSPI_NIGHT', 'KOSPI200'], true);

            if ($isIndex) {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}";
            } else {
                $cacheKey = "yahoo_stock_data_{$ticker}_{$tf}_raw";
            }

            if (!Cache::has($cacheKey)) {
                $toWarm[] = ['ticker' => $ticker, 'tf' => $tf, 'isIndex' => $isIndex, 'key' => $cacheKey];
            }
        }

        if (empty($toWarm)) {
            return;
        }

        $client = new Client(['timeout' => 8.0, 'connect_timeout' => 4.0]);

        // Yahoo 캔들은 KIS 레이트리밋과 무관하므로 동시성을 더 높여도 됨 (최대 10)
        $requests = function () use ($toWarm, $client) {
            foreach ($toWarm as $item) {
                $ticker  = $item['ticker'];
                $tf      = $item['tf'];
                $symbol  = $ticker;

                // Yahoo 심볼 변환
                if ($ticker === 'KOSPI200') {
                    $symbol = '^KS200';
                } elseif (
                    !preg_match('/(\.KS|\.KQ)$/i', $ticker)
                    && preg_match('/^\d{4}[0-9A-Z]{2}$/', $ticker)
                    || preg_match('/^\d+$/', $ticker)
                ) {
                    // 국내 종목(순수 코드) → .KS 추가
                    if (!preg_match('/(\.KS|\.KQ)$/i', $ticker)) {
                        $symbol = $ticker . '.KS';
                    }
                }

                // timeframe → Yahoo interval/range 변환
                [$interval, $range] = $this->tfToYahooParams($tf);

                $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}"
                     . "?interval={$interval}&range={$range}&includePrePost=true";

                yield $item['key'] => new GuzzleRequest(
                    'GET',
                    $url,
                    ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36']
                );
            }
        };

        // 응답을 받으면 raw JSON 을 30초 캐시에 저장 (StockController::getStockData 캐시 미스 방지용)
        // StockController 는 자체적으로 파싱·가공하므로, 여기서는 "이미 조회 완료" 표시자로
        // 빈 배열 대신 실제 응답을 저장할 수 있다. 그러나 StockController 가 직접 Guzzle 로
        // 호출해 파싱하는 구조이므로, 실제로는 "캐시에 아무 값이나 있으면 재조회 안 함"이 아니다.
        //
        // StockController 의 캐시는 Response 객체(직렬화 불가)가 아닌 파싱 후 배열을 저장한다.
        // 따라서 raw HTTP 응답을 직접 캐시에 넣기는 어렵다.
        //
        // 대신: Yahoo 캔들 워밍업의 실제 목적은 "캐시가 만료됐을 때 순차 재조회를 병렬로 미리 실행"
        // → StockController 가 Cache::remember 의 클로저를 실행하기 전에 우리가 먼저 파싱된 값을
        //    동일 캐시 키에 저장해두면 된다.
        //
        // StockController::getYahooChartData 의 캐시 저장 방식:
        //   Cache::remember("yahoo_stock_data_{ticker}_{tf}_raw", 30, fn() => $this->getYahooChartData(...))
        //   → 저장값: Response 객체 (Laravel Response 는 직렬화 불가 → Cache 에 저장되지 않음)
        //
        // 실제로 Cache::remember 의 클로저가 반환하는 Response 객체는 파일/DB 캐시에 직렬화가
        // 실패하면 매번 재실행된다. 이 문제가 Yahoo 캔들 지연의 실제 원인일 수 있다.
        // → Yahoo 캔들은 캐시 warm-up 보다 클라이언트 분산(사이클 분산)이 더 효과적.
        // → 이 메서드는 현재 구현하되, Fulfilled 에서 아무것도 하지 않고 단순히 "선점 요청" 역할만.
        //    (사이클 사이에 Yahoo 네트워크 레이턴시를 미리 소모해 두는 효과)
        $pool = new Pool($client, $requests(), [
            'concurrency' => 10,
            'fulfilled'   => function ($response, $cacheKey) {
                // Yahoo 응답은 StockController 가 직접 파싱하므로 여기서 캐시에 저장하지 않는다.
                // 단순히 사이클 시작 시 선점 요청만 한다.
            },
            'rejected' => function ($reason, $cacheKey) {
                // 무시 — Yahoo 실패 시 StockController 가 getMockStockData 폴백으로 처리
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * timeframe 문자열을 Yahoo Finance API 의 interval/range 파라미터로 변환한다.
     *
     * @return array  [interval, range]
     */
    private function tfToYahooParams(string $tf): array
    {
        switch ($tf) {
            case '1m':  return ['1m', '1d'];
            case '3m':  return ['1m', '2d'];
            case '5m':  return ['1m', '2d'];
            case '10m': return ['1m', '3d'];
            case '30m': return ['1m', '5d'];
            case '1h':  return ['1m', '7d'];
            default:    return ['1d', '60d']; // 1d
        }
    }

    /**
     * 현재 미국 시장 세션을 간략 판정한다.
     * ET 20:00~03:30 = 주간거래, 그 외 = regular/pre/after/closed.
     * 상세 판정은 MarketSessionService 에 위임하지 않고 여기서 간단히 처리
     * (서비스 의존성 주입 없이도 WebSocketAgentServer 에서 사용 가능하도록).
     */
    private function resolveUsSession(): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int)$dt->format('N');
        $timeVal   = (int)$dt->format('H') * 100 + (int)$dt->format('i');

        // 주말 경계: 금 20:00 이후 ~ 일 20:00 이전
        if ($dayOfWeek === 6) return '장마감';
        if ($dayOfWeek === 7 && $timeVal < 2000) return '장마감';
        if ($dayOfWeek === 5 && $timeVal >= 2000) return '장마감';

        // 주간거래(ET 20:00~03:30)
        if ($timeVal >= 2000 || $timeVal < 330) return '주간거래';

        return '기타';
    }
}
