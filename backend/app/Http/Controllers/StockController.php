<?php

namespace App\Http\Controllers;

use App\Services\MarketSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;

class StockController extends Controller
{
    private MarketSessionService $sessionService;

    public function __construct(MarketSessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    private function getAccessToken($forceRefresh = false)
    {
        // 만료 토큰 감지 시 강제 갱신 — 캐시를 비워 새 토큰을 발급받는다.
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token) {
            return $token;
        }

        // Use a lock to prevent concurrent requests from hitting the KIS token API rate limit (1 req/min)
        $lock = Cache::lock('kis_token_lock', 15);
        
        try {
            $attempts = 0;
            while (!$lock->get() && $attempts < 10) {
                usleep(500000); // Wait 0.5 seconds
                $token = Cache::get('kis_access_token');
                if ($token) {
                    return $token;
                }
                $attempts++;
            }

            // Re-check cache after acquiring lock
            $token = Cache::get('kis_access_token');
            if ($token) {
                return $token;
            }

            $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey = env('KIS_APP_KEY');
            $appSecret = env('KIS_APP_SECRET');

            if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
                throw new \Exception('KIS_APP_KEY or KIS_APP_SECRET is not configured in .env');
            }

            $client = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                ],
                'headers' => [
                    'content-type' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            throw new \Exception('Failed to retrieve KIS access token: ' . ($data['msg1'] ?? 'Unknown error'));
        } finally {
            $lock->release();
        }
    }

    private function getPreviousClose($ticker)
    {
        $cacheKey = "kis_prev_close_{$ticker}";
        $prevClose = Cache::get($cacheKey);
        if ($prevClose !== null) {
            return (float)$prevClose;
        }

        try {
            $dailyCacheKey = "kis_stock_data_{$ticker}_1d";
            $dailyData = Cache::get($dailyCacheKey);
            if (!$dailyData) {
                $response = $this->fetchFromKis($ticker);
                $dailyData = json_decode($response->getContent(), true);
            }

            if (isset($dailyData['candles']) && count($dailyData['candles']) >= 2) {
                $candles = $dailyData['candles'];
                end($candles);
                $prevCandle = prev($candles);
                $prevClose = (float)$prevCandle['close'];
            } elseif (isset($dailyData['candles']) && count($dailyData['candles']) === 1) {
                $prevClose = (float)$dailyData['candles'][0]['close'];
            } else {
                $prevClose = (float)($dailyData['current_price'] ?? 0.0);
            }

            if ($prevClose > 0) {
                Cache::put($cacheKey, $prevClose, 86400); // 24 hours
                return $prevClose;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch previous close for {$ticker}: " . $e->getMessage());
        }

        return null;
    }

    public function getStockData(Request $request, $ticker)
    {
        $ticker = strtoupper($ticker);
        $ticker = str_replace('0167AO', '0167A0', $ticker);
        $timeframe = strtolower($request->query('timeframe', '1d'));

        // 코스피 지수(KOSPI200) — KIS 국내업종 API 사용 (값 일관성)
        if ($ticker === 'KOSPI200') {
            // 코스피 지수 캔들은 분봉도 KIS 분봉 집계로 구성 → 90초 캐싱. 현재가는 KIS 현재가 캐시(3초) 별도.
            // TTL 90초로 늘려 WS 사이클 만료 빈도를 1/3로 감소.
            $candleTtl = 90;
            $cacheKey = "kis_kospi_index_{$timeframe}";
            $response = Cache::remember($cacheKey, $candleTtl, function () use ($timeframe) {
                return $this->getKospiIndexData($timeframe);
            });

            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                $content['session'] = $this->getKrMarketSessionInfo(time());
                // 프론트가 휴장일(주말·공휴일)에 코스피 칸을 숨기는 판단에 사용
                $content['is_trading_day'] = $this->isKrTradingDay(time());
                return response()->json($content);
            }
            return $response;
        }

        if ($ticker === 'NQ=F' || $ticker === '^KS200' || $ticker === 'USDKRW=X') {
            // 지수/환율 Yahoo 캔들: 90초 캐싱 — 봉 자체는 초 단위로 변하지 않음.
            // 실시간성은 meta.regularMarketPrice 가 담당. TTL 90초로 늘려 WS 사이클 만료 빈도를 줄임.
            $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}";
            $response = Cache::remember($cacheKey, 90, function () use ($ticker, $timeframe) {
                return $this->getYahooChartData($ticker, $timeframe);
            });
            
            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                if ($ticker === 'NQ=F') {
                    // 나스닥100 선물은 CME 에서 일~금 거의 24시간 거래 — 주식 거래일(현금장) 게이트를 쓰지 않는다.
                    // KST 기준 휴장창: 토 06:00 ~ 월 07:00 (금 17:00 ET 마감 → 일 18:00 ET 재개)
                    $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
                    $dayOfWeek = (int)$now->format('N');
                    $hour = (int)$now->format('H');
                    $isClosed = ($dayOfWeek === 6 && $hour >= 6) || ($dayOfWeek === 7) || ($dayOfWeek === 1 && $hour < 7);
                    $content['session'] = $isClosed ? '장마감' : '거래중';
                    $content['is_trading_day'] = !$isClosed;
                } elseif ($ticker === 'USDKRW=X') {
                    $content['session'] = '거래중';
                } else {
                    $content['session'] = $this->getKrMarketSessionInfo(time());
                }
                return response()->json($content);
            }
            return $response;
        }

        if ($ticker === 'KOSPI_NIGHT') {
            // 야간선물은 NQ=F 기반으로 합성 — NQ=F 캔들 캐싱(90초)에 맞춰 동일하게 90초 캐싱
            $cacheKey = "kospi_night_data_{$timeframe}";
            $response = Cache::remember($cacheKey, 90, function () use ($timeframe) {
                return $this->getKOSPINightChartData($timeframe);
            });
            
            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
                $hour = (int)$now->format('H');
                // 야간선물은 개장일(거래일) 저녁에만 운영 — 공휴일이면 미운영 (KIS API 기준)
                $isNightActive = (($hour >= 18 || $hour < 5) && $this->isKrTradingDay(time()));
                $content['session'] = $isNightActive ? '거래중' : '장마감';
                return response()->json($content);
            }
            return $response;
        }

        // 국내 주식/ETF 판정: .KS/.KQ 접미사, 또는 KRX 단축코드(6자리 숫자 또는 신형 영숫자 예: 0167A0)
        if (preg_match('/(\.KS|\.KQ)$/i', $ticker) || preg_match('/^\d{4}[0-9A-Z]{2}$/', $ticker) || preg_match('/^\d+$/', $ticker)) {
            $isDaily = ($timeframe === '1d');
            // Yahoo 는 KRX 코드에 .KS/.KQ 접미사가 필요 — 없으면 .KS(코스피) 기본 부착
            $yahooSymbol = preg_match('/(\.KS|\.KQ)$/i', $ticker) ? $ticker : ($ticker . '.KS');
            // Yahoo 캔들(히스토리)은 90초 캐싱 — 봉 자체는 초 단위로 거의 변하지 않으며
            // 실시간성은 KIS 현재가(3초 캐시)가 마지막 봉에 덧씌워 담당한다.
            // TTL 90초로 늘려 WS 사이클 Yahoo 만료 빈도를 1/3로 감소.
            $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}_raw";
            $dataResponse = Cache::remember($cacheKey, 90, function () use ($yahooSymbol, $timeframe, $isDaily) {
                return $this->getYahooChartData($yahooSymbol, $timeframe, !$isDaily);
            });

            // KIS 현재가는 3초 캐싱 유지 — 실시간 가격·등락 갱신의 핵심이므로 짧게 유지
            $cacheKeyKis = "kis_realtime_price_{$ticker}";
            $kisPrice = Cache::remember($cacheKeyKis, 3, function() use ($ticker) {
                return $this->fetchDomesticPriceFromKis($ticker);
            });

            $session = $this->getKrMarketSessionInfo(time());

            $content = json_decode($dataResponse->getContent(), true);
            if (is_array($content) && !empty($content['candles'])) {
                if ($kisPrice !== null) {
                    $price = $kisPrice['price'];
                    $content['current_price'] = $price;
                    $content['change_amount'] = $kisPrice['change_amount'];
                    $content['change_percent'] = $kisPrice['change_percent'];
                    
                    if (!$isDaily) {
                        $lastYahooTime = (int)end($content['candles'])['time'];
                        $accumulated1m = $this->accumulateRealTimePrice($ticker, $price, $lastYahooTime);

                        // Filter accumulated candles to only keep those after the last Yahoo candle
                        $filteredAccumulated = array_filter($accumulated1m, function($c) use ($lastYahooTime) {
                            return (int)$c['time'] > $lastYahooTime;
                        });

                        // Merge!
                        $merged1m = array_merge($content['candles'], array_values($filteredAccumulated));

                        // Aggregate merged 1m candles into the target timeframe!
                        $intervalSeconds = 180;
                        if ($timeframe === '1m') $intervalSeconds = 60;
                        elseif ($timeframe === '5m') $intervalSeconds = 300;
                        elseif ($timeframe === '10m') $intervalSeconds = 600;
                        elseif ($timeframe === '30m') $intervalSeconds = 1800;
                        elseif ($timeframe === '1h') $intervalSeconds = 3600;
                        
                        $content['candles'] = $this->aggregateCandles($merged1m, $intervalSeconds);
                    } else {
                        $lastIdx = count($content['candles']) - 1;
                        $lastCandle = $content['candles'][$lastIdx];
                        $todayStr = date('Y-m-d');
                        
                        // 휴장일(주말·공휴일)에는 오늘자 가짜 일봉을 생성하지 않는다 (KIS API 기준)
                        $isTradingDay = $this->isKrTradingDay(time());

                        if ($lastCandle['time'] !== $todayStr) {
                            if ($isTradingDay) {
                                $lastClose = $lastCandle['close'];
                                $content['candles'][] = [
                                    'time' => $todayStr,
                                    'open' => $lastClose,
                                    'high' => max($lastClose, $price),
                                    'low' => min($lastClose, $price),
                                    'close' => $price,
                                    'volume' => 0
                                ];
                            }
                        } else {
                            $content['candles'][$lastIdx]['close'] = $price;
                            if ($price > $content['candles'][$lastIdx]['high']) {
                                $content['candles'][$lastIdx]['high'] = $price;
                            }
                            if ($price < $content['candles'][$lastIdx]['low']) {
                                $content['candles'][$lastIdx]['low'] = $price;
                            }
                        }
                    }
                }
                $content['session'] = $session;
                // 휴장일(주말·공휴일)이면 프론트가 차트 대신 텍스트(전일 마감)로 표시
                $content['is_trading_day'] = $this->isKrTradingDay(time());
                return response()->json($content);
            }

            return $dataResponse;
        }

        // US Stock flow (non-index, non-domestic)
        $isDaily = ($timeframe === '1d');
        // Yahoo 캔들(히스토리): 90초 캐싱 — 봉은 초 단위로 거의 변하지 않음
        // 실시간성은 KIS 현재가(3초 캐시)가 마지막 봉에 덧씌워 담당.
        // TTL 90초로 늘려 WS 사이클 Yahoo 만료 빈도를 1/3로 감소.
        $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}_raw";

        $dataResponse = Cache::remember($cacheKey, 90, function () use ($ticker, $timeframe, $isDaily) {
            return $this->getYahooChartData($ticker, $timeframe, !$isDaily);
        });

        // KIS 현재가는 3초 캐싱 유지 — 실시간 가격·등락 갱신의 핵심
        $cacheKeyKis = "kis_realtime_price_us_{$ticker}";
        $kisPrice = Cache::remember($cacheKeyKis, 3, function() use ($ticker) {
            return $this->fetchOverseasPriceFromKis($ticker);
        });

        $session = $this->getUsMarketSessionInfo(time());

        $content = json_decode($dataResponse->getContent(), true);
        if (is_array($content) && !empty($content['candles'])) {
            if ($kisPrice !== null) {
                $price = $kisPrice['price'];
                $content['current_price'] = $price;
                $content['change_amount'] = $kisPrice['change_amount'];
                $content['change_percent'] = $kisPrice['change_percent'];
                
                if (!$isDaily) {
                    $lastYahooTime = (int)end($content['candles'])['time'];
                    $accumulated1m = $this->accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime);

                    // Filter accumulated candles
                    $filteredAccumulated = array_filter($accumulated1m, function($c) use ($lastYahooTime) {
                        return (int)$c['time'] > $lastYahooTime;
                    });

                    // Merge!
                    $merged1m = array_merge($content['candles'], array_values($filteredAccumulated));

                    // Aggregate merged 1m candles
                    $intervalSeconds = 180;
                    if ($timeframe === '1m') $intervalSeconds = 60;
                    elseif ($timeframe === '5m') $intervalSeconds = 300;
                    elseif ($timeframe === '10m') $intervalSeconds = 600;
                    elseif ($timeframe === '30m') $intervalSeconds = 1800;
                    elseif ($timeframe === '1h') $intervalSeconds = 3600;
                    
                    $content['candles'] = $this->aggregateCandles($merged1m, $intervalSeconds);
                } else {
                    $lastIdx = count($content['candles']) - 1;
                    $lastCandle = $content['candles'][$lastIdx];
                    
                    $nyDate = new \DateTime('now', new \DateTimeZone('America/New_York'));
                    $todayStr = $nyDate->format('Y-m-d');
                    
                    if ($lastCandle['time'] !== $todayStr) {
                        $isClosedSession = ($session === '장마감');
                        if (!$isClosedSession) {
                            $lastClose = $lastCandle['close'];
                            $content['candles'][] = [
                                'time' => $todayStr,
                                'open' => $lastClose,
                                'high' => max($lastClose, $price),
                                'low' => min($lastClose, $price),
                                'close' => $price,
                                'volume' => 0
                            ];
                        }
                    } else {
                        $content['candles'][$lastIdx]['close'] = $price;
                        if ($price > $content['candles'][$lastIdx]['high']) {
                            $content['candles'][$lastIdx]['high'] = $price;
                        }
                        if ($price < $content['candles'][$lastIdx]['low']) {
                            $content['candles'][$lastIdx]['low'] = $price;
                        }
                    }
                }
            }
            // 세션 라벨은 getUsMarketSessionInfo 가 주말·공휴일·주간거래(20:00~04:00 ET)를 모두 판정한다.
            // 주간거래는 다음 거래일로 이어지는 세션이라 'NY 오늘=거래일'로 막으면 안 된다(과거 휴장 오판 버그).
            $content['session'] = $session;
            $content['source'] = 'Yahoo + KIS (Daytime)';
            $content['is_trading_day'] = ($session !== '장마감');
            return response()->json($content);
        }

        return $dataResponse;
    }

    public function getIndices()
    {
        $nqPriced = null;
        $kospiPriced = null;
        $client = new Client();

        // 1. Fetch Nasdaq 100 Futures (NQ=F)
        try {
            $responseNq = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1d&range=2d', [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            $dataNq = json_decode($responseNq->getBody()->getContents(), true);
            $nqPriced = $this->parseYahooFinanceChart($dataNq, '나스닥100 선물');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Nasdaq Futures Fetch Error: " . $e->getMessage());
            $nqPriced = [
                'name' => '나스닥100 선물',
                'price' => 19482.25,
                'change' => 282.25,
                'change_percent' => 1.47,
            ];
        }

        // 2. 코스피 지수(0001=코스피 종합) 현재가 — KIS 국내업종 현재지수 API (tr_id FHPUP02100000)
        try {
            $data = $this->kisIndexRequest(
                '/uapi/domestic-stock/v1/quotations/inquire-index-price',
                'FHPUP02100000',
                [
                    'FID_COND_MRKT_DIV_CODE' => 'U', // 업종(지수)
                    'FID_INPUT_ISCD' => '0001',      // 코스피 종합(전체)
                ]
            );

            if ($data && ($data['rt_cd'] ?? '1') === '0' && isset($data['output']['bstp_nmix_prpr'])) {
                $o = $data['output'];
                $price = (float)$o['bstp_nmix_prpr'];
                [$change, $changePercent] = $this->kisIndexChange($o);

                $kospiPriced = [
                    'name' => '코스피 지수',
                    'price' => round($price, 2),
                    'change' => round($change, 2),
                    'change_percent' => round($changePercent, 2),
                    'source' => 'KIS API (0001)'
                ];
            } else {
                throw new \Exception($data['msg1'] ?? 'Invalid KIS response for KOSPI index');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("KIS KOSPI Index Fetch Error: " . $e->getMessage());

            $kospiPriced = [
                'name' => '코스피 지수',
                'price' => 362.45,
                'change' => -1.20,
                'change_percent' => -0.33,
                'source' => 'Mock'
            ];
        }

        // 3. KOSPI Night Futures
        $nightPriced = $this->generateKOSPINightFutures($kospiPriced, $nqPriced);

        return response()->json([
            'status' => 'success',
            'indices' => [
                'nasdaq_futures' => $nqPriced,
                'kospi_futures' => $kospiPriced,
                'kospi_night_futures' => $nightPriced,
            ]
        ]);
    }

    private function parseYahooFinanceChart($data, $name)
    {
        if (!isset($data['chart']['result'][0])) {
            throw new \Exception("Invalid Yahoo Finance chart response for {$name}");
        }
        
        $result = $data['chart']['result'][0];
        $meta = $result['meta'];
        
        $price = $meta['regularMarketPrice'] ?? 0.0;
        $prevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0.0;
        
        if (isset($result['indicators']['quote'][0]['close'])) {
            $closes = array_values(array_filter($result['indicators']['quote'][0]['close']));
            if (count($closes) >= 2) {
                if ($price == 0.0) {
                    $price = end($closes);
                }
                if ($prevClose == 0.0) {
                    $prevClose = $closes[0];
                }
            } elseif (count($closes) == 1) {
                if ($price == 0.0) {
                    $price = $closes[0];
                }
            }
        }
        
        if ($prevClose == 0.0) {
            $prevClose = $price;
        }
        
        $change = $price - $prevClose;
        $changePercent = ($prevClose > 0) ? ($change / $prevClose) * 100 : 0.0;
        
        return [
            'name' => $name,
            'price' => round($price, 2),
            'change' => round($change, 2),
            'change_percent' => round($changePercent, 2),
        ];
    }

    private function generateKOSPINightFutures($kospi, $nq)
    {
        $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
        $hour = (int)$now->format('H');
        $dayOfWeek = (int)$now->format('N');
        
        $isNightActive = false;

        // KRX 야간 세션은 개장일 18:00 ~ 익일 05:00. 공휴일은 KIS API 로 판정해 제외.
        if (($hour >= 18 || $hour < 5) && $this->isKrTradingDay(time())) {
            $isNightActive = true;
        }
        
        $price = $kospi['price'];
        $change = 0.0;
        $changePercent = 0.0;
        
        if ($isNightActive) {
            // In Korean market, futures changes are quoted in points (p)
            // A 1.57% Nasdaq increase corresponds to ~+5.56 points in KOSPI 200 Futures.
            // Scale: 1% Nasdaq move = ~3.54 points in KOSPI
            $nqMove = $nq['change_percent'];
            $change = $nqMove * 3.541; // 1.57 * 3.541 = +5.56 points!
            $price = $kospi['price'] + $change;
            $changePercent = ($kospi['price'] > 0) ? ($change / $kospi['price']) * 100 : 0.0;
        } else {
            $change = $kospi['change'];
            $changePercent = $kospi['change_percent'];
        }
        
        return [
            'name' => '야간 선물 (KOSPI 200)',
            'price' => round($price, 2),
            'change' => round($change, 2),
            'change_percent' => round($changePercent, 2),
            'status' => $isNightActive ? '거래중' : '장마감',
        ];
    }

    private function getMockIndices($reason = '')
    {
        return response()->json([
            'status' => 'success',
            'source' => 'Mock',
            'error' => $reason,
            'indices' => [
                'nasdaq_futures' => [
                    'name' => '나스닥100 선물',
                    'price' => 19482.25,
                    'change' => 282.25,
                    'change_percent' => 1.47,
                ],
                'kospi_futures' => [
                    'name' => '코스피200 선물',
                    'price' => 362.45,
                    'change' => -1.20,
                    'change_percent' => -0.33,
                ],
                'kospi_night_futures' => [
                    'name' => '야간 선물 (KOSPI 200)',
                    'price' => 362.85,
                    'change' => 0.40,
                    'change_percent' => 0.11,
                    'status' => '거래중'
                ]
            ]
        ]);
    }

    private function fetchFromKis($ticker)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        $accessToken = $this->getAccessToken();

        $exchanges = ['NAS', 'NYS', 'AMS'];
        $output2 = null;

        $client = new Client();

        foreach ($exchanges as $exchange) {
            try {
                $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/dailyprice", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$accessToken}",
                        'appkey' => $appKey,
                        'appsecret' => $appSecret,
                        'tr_id' => 'HHDFS76240000',
                    ],
                    'query' => [
                        'AUTH' => '',
                        'EXCD' => $exchange,
                        'SYMB' => $ticker,
                        'GUBN' => '0',
                        'BYMD' => '',
                        'MODP' => '1',
                    ]
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['output2']) && is_array($data['output2']) && count($data['output2']) > 0 && (float)$data['output2'][0]['clos'] > 0) {
                    $output2 = $data['output2'];
                    break;
                }
            } catch (\Exception $ex) {
                continue;
            }
        }

        if (empty($output2)) {
            throw new \Exception("No price data found for ticker {$ticker} on NAS, NYS, or AMS.");
        }

        $candles = [];
        foreach ($output2 as $row) {
            if (empty($row['xymd']) || (float)$row['open'] == 0) continue;

            $dateStr = substr($row['xymd'], 0, 4) . '-' . substr($row['xymd'], 4, 2) . '-' . substr($row['xymd'], 6, 2);

            $candles[] = [
                'time' => $dateStr,
                'open' => (float)$row['open'],
                'high' => (float)$row['high'],
                'low' => (float)$row['low'],
                'close' => (float)$row['clos'],
                'volume' => (int)$row['tvol'],
            ];
        }

        $candles = array_reverse($candles);

        if (empty($candles)) {
            throw new \Exception("Failed to parse stock candle data.");
        }

        $latestCandle = end($candles);
        $prevCandle = prev($candles) ?: $latestCandle;

        $current = $latestCandle['close'];
        $prevClose = $prevCandle['close'];

        // Cache the daily previous close for other timeframes to use
        Cache::put("kis_prev_close_{$ticker}", $prevClose, 86400); // 24 hours

        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;

        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'KIS API'
        ]);
    }

    /**
     * 미국 주식 분봉 데이터를 KIS API 로 조회한다.
     *
     * 주간거래(Blue Ocean ATS, 20:00~04:00 ET) 시간대에는 정규장 EXCD(NAS/NYS/AMS)가
     * 정규장 당일 봉만 반환하므로, 주간거래 전용 코드를 먼저 시도한다:
     *   - BAQ : Nasdaq Blue Ocean / BAY : NYSE Blue Ocean / BAA : AMEX Blue Ocean
     */
    private function fetchMinuteFromKis($ticker, $timeframe)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        $accessToken = $this->getAccessToken();

        $nmin = '1';
        if ($timeframe === '3m') $nmin = '3';
        elseif ($timeframe === '5m') $nmin = '5';
        elseif ($timeframe === '10m') $nmin = '10';
        elseif ($timeframe === '30m') $nmin = '30';
        elseif ($timeframe === '1h') $nmin = '60';

        // 주간거래 시간대에는 Blue Ocean 코드 우선 시도
        $session = $this->getUsMarketSessionInfo(time());
        $sessionGroup = ($session === '주간거래') ? 'overnight' : 'regular';

        if ($session === '주간거래') {
            $allExchanges = ['BAQ', 'BAY', 'BAA', 'NAS', 'NYS', 'AMS'];
        } else {
            $allExchanges = ['NAS', 'NYS', 'AMS', 'BAQ', 'BAY', 'BAA'];
        }

        // 분봉용 EXCD 캐시 — 현재가와 동일한 종목별·세션그룹별 캐시를 재사용
        // (분봉/일봉은 같은 거래소에 상장돼 있으므로 동일 키 공유)
        $excdCacheKey = "kis_excd_{$ticker}_{$sessionGroup}";
        $cachedExcd = Cache::get($excdCacheKey);
        if ($cachedExcd !== null) {
            $exchanges = array_merge(
                [$cachedExcd],
                array_values(array_filter($allExchanges, fn($e) => $e !== $cachedExcd))
            );
        } else {
            $exchanges = $allExchanges;
        }

        $output2 = null;
        $client = new Client();
        $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHDFS76950200' : 'HHDFS76950200';

        foreach ($exchanges as $exchange) {
            try {
                $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/inquire-time-itemchartprice", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$accessToken}",
                        'appkey' => $appKey,
                        'appsecret' => $appSecret,
                        'tr_id' => $trId,
                    ],
                    'query' => [
                        'AUTH' => '',
                        'EXCD' => $exchange,
                        'SYMB' => $ticker,
                        'NMIN' => $nmin,
                        'PINC' => '0',
                        'NEXT' => '',
                        'KEYB' => '',
                        'NREC' => '120',
                        'FILL' => 'N',
                    ]
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (isset($data['output2']) && is_array($data['output2']) && count($data['output2']) > 0 && (float)$data['output2'][0]['last'] > 0) {
                    $output2 = $data['output2'];
                    // 성공한 EXCD 캐싱 — fetchOverseasPriceFromKis 와 같은 키를 공유해 중복 탐색 방지
                    if ($cachedExcd !== $exchange) {
                        Cache::put($excdCacheKey, $exchange, 86400);
                    }
                    break;
                }
            } catch (\Exception $ex) {
                continue;
            }
        }

        if (empty($output2)) {
            throw new \Exception("No minute price data found for ticker {$ticker} on NAS, NYS, AMS, BAQ, BAY, or BAA.");
        }

        $candles = [];
        foreach ($output2 as $row) {
            if (empty($row['xymd']) || empty($row['xhms']) || (float)$row['open'] == 0) continue;

            $dateStr = substr($row['xymd'], 0, 4) . '-' . substr($row['xymd'], 4, 2) . '-' . substr($row['xymd'], 6, 2);
            $timeStr = substr($row['xhms'], 0, 2) . ':' . substr($row['xhms'], 2, 2) . ':' . substr($row['xhms'], 4, 2);
            
            $dateTime = new \DateTime($dateStr . ' ' . $timeStr, new \DateTimeZone('America/New_York'));
            $timestamp = $dateTime->getTimestamp();

            $candles[] = [
                'time' => $timestamp,
                'open' => (float)$row['open'],
                'high' => (float)$row['high'],
                'low' => (float)$row['low'],
                'close' => (float)$row['last'],
                'volume' => (int)$row['evol'],
            ];
        }

        $candles = array_reverse($candles);

        if (empty($candles)) {
            throw new \Exception("Failed to parse minute stock candle data.");
        }

        $latestCandle = end($candles);
        $current = $latestCandle['close'];

        // Get daily previous close for correct daily change percentage
        $dailyPrevClose = $this->getPreviousClose($ticker);
        $prevCloseForChange = ($dailyPrevClose !== null && $dailyPrevClose > 0) ? $dailyPrevClose : $latestCandle['close'];

        $changeAmount = $current - $prevCloseForChange;
        $changePercent = ($prevCloseForChange > 0) ? ($changeAmount / $prevCloseForChange) * 100 : 0.0;

        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'KIS API (' . $timeframe . ')'
        ]);
    }

    private function getMockStockData($ticker, $reason = '', $timeframe = '1d')
    {
        $basePrices = [
            'TSLA' => 180.0,
            'AAPL' => 175.0,
            'NVDA' => 120.0,
            'MSFT' => 420.0,
            'AMZN' => 180.0,
            'GOOGL' => 170.0,
            'MU' => 130.0,
        ];
        
        $basePrice = $basePrices[$ticker] ?? 100.0;
        
        $intervalSeconds = 86400; // 1d
        if ($timeframe === '1m') $intervalSeconds = 60;
        elseif ($timeframe === '3m') $intervalSeconds = 180;
        elseif ($timeframe === '5m') $intervalSeconds = 300;
        elseif ($timeframe === '10m') $intervalSeconds = 600;
        elseif ($timeframe === '30m') $intervalSeconds = 1800;
        elseif ($timeframe === '1h') $intervalSeconds = 3600;

        $candles = [];
        $currentPrice = $basePrice;
        
        $now = time();
        $now = $now - ($now % $intervalSeconds);
        
        $tempCandles = [];
        for ($i = 119; $i >= 0; $i--) {
            $timestamp = $now - ($i * $intervalSeconds);
            
            if ($timeframe === '1d') {
                $date = new \DateTime("@{$timestamp}");
                $dayOfWeek = (int)$date->format('N');
                if ($dayOfWeek === 6 || $dayOfWeek === 7) {
                    continue;
                }
            }
            
            if ($timeframe === '1d') {
                $change = (rand(-150, 150) / 100.0);
                $volMin = 50000;
                $volMax = 1000000;
                $highAdd = rand(0, 100) / 100.0;
                $lowSub = rand(0, 100) / 100.0;
            } else {
                $change = (rand(-25, 25) / 100.0);
                $volMin = 5000;
                $volMax = 80000;
                $highAdd = rand(0, 15) / 100.0;
                $lowSub = rand(0, 15) / 100.0;
            }
            
            $open = $currentPrice;
            $close = $currentPrice + $change;
            $high = max($open, $close) + $highAdd;
            $low = min($open, $close) - $lowSub;
            $volume = rand($volMin, $volMax);
            
            $item = [
                'open' => round($open, 2),
                'high' => round($high, 2),
                'low' => round($low, 2),
                'close' => round($close, 2),
                'volume' => $volume,
            ];
            
            if ($timeframe === '1d') {
                $date = new \DateTime("@{$timestamp}");
                $item['time'] = $date->format('Y-m-d');
            } else {
                $item['time'] = $timestamp;
            }
            
            $tempCandles[] = $item;
            $currentPrice = $close; // Open of next candle is close of this candle
        }
        
        $candles = $tempCandles;
        
        usort($candles, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        
        $latestCandle = end($candles);
        $current = $latestCandle['close'];

        if ($timeframe === '1d') {
            $prevCandle = prev($candles) ?: $latestCandle;
            $prevClose = $prevCandle['close'];
        } else {
            $dailyPrevClose = $this->getPreviousClose($ticker);
            if ($dailyPrevClose !== null && $dailyPrevClose > 0) {
                $prevClose = $dailyPrevClose;
            } else {
                $prevClose = $candles[0]['close'] ?? $basePrice;
            }
        }
        
        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
        
        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'Mock (' . $timeframe . ' chart)',
            'error_reason' => $reason
        ]);
    }
    
    public function getYahooChartData($ticker, $timeframe, $raw = false)
    {
        $symbol = $ticker;
        if ($ticker === 'KOSPI200') {
            $symbol = '^KS200';
        }
        
        $interval = '1d';
        $range = '60d';
        $isAggregated = false;
        $intervalSeconds = 180;
        
        if ($timeframe === '1m') {
            $interval = '1m';
            $range = '1d';
            $isAggregated = false; // 1분봉은 집계 없이 원본 그대로
            $intervalSeconds = 60;
        } elseif ($timeframe === '3m') {
            $interval = '1m';
            $range = '2d';
            $isAggregated = !$raw;
            $intervalSeconds = 180;
        } elseif ($timeframe === '5m') {
            $interval = '1m';
            $range = '2d';
            $isAggregated = !$raw;
            $intervalSeconds = 300;
        } elseif ($timeframe === '10m') {
            $interval = '1m';
            $range = '3d';
            $isAggregated = !$raw;
            $intervalSeconds = 600;
        } elseif ($timeframe === '30m') {
            $interval = '1m';
            $range = '5d';
            $isAggregated = !$raw;
            $intervalSeconds = 1800;
        } elseif ($timeframe === '1h') {
            $interval = '1m';
            $range = '7d';
            $isAggregated = true;
            $intervalSeconds = 3600;
        }
        
        try {
            $client = new Client();
            $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval={$interval}&range={$range}&includePrePost=true";
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['chart']['result'][0])) {
                throw new \Exception("Invalid Yahoo Finance response");
            }
            
            $result = $data['chart']['result'][0];
            $timestamps = $result['timestamp'] ?? [];
            $quote = $result['indicators']['quote'][0] ?? [];
            
            $candles = [];
            $len = count($timestamps);
            for ($i = 0; $i < $len; $i++) {
                $open = $quote['open'][$i] ?? null;
                $high = $quote['high'][$i] ?? null;
                $low = $quote['low'][$i] ?? null;
                $close = $quote['close'][$i] ?? null;
                $volume = $quote['volume'][$i] ?? 0;
                
                if ($open === null || $high === null || $low === null || $close === null) {
                    continue;
                }
                
                $timestamp = $timestamps[$i];
                if ($timeframe === '1d') {
                    $date = new \DateTime("@{$timestamp}");
                    $date->setTimezone(new \DateTimeZone('Asia/Seoul'));
                    $timeVal = $date->format('Y-m-d');
                } else {
                    $timeVal = $timestamp;
                }
                
                $candles[] = [
                    'time' => $timeVal,
                    'open' => round((float)$open, 2),
                    'high' => round((float)$high, 2),
                    'low' => round((float)$low, 2),
                    'close' => round((float)$close, 2),
                    'volume' => (int)$volume,
                ];
            }
            
            if (empty($candles)) {
                throw new \Exception("No candles parsed");
            }

            if ($isAggregated && !empty($candles)) {
                $candles = $this->aggregateCandles($candles, $intervalSeconds);
            }
            
            $meta = $result['meta'];
            $latestCandle = end($candles);
            $current = $latestCandle['close'];

            if ($timeframe === '1d') {
                $prevCandle = prev($candles) ?: $latestCandle;
                $prevClose = $prevCandle['close'];
            } else {
                // For regular stocks, prioritize the daily previous close from KIS to match the Watchlist sidebar
                $dailyPrevClose = null;
                if ($ticker !== 'NQ=F' && $ticker !== 'KOSPI200' && $ticker !== '^KS200') {
                    $dailyPrevClose = $this->getPreviousClose($ticker);
                }

                if ($dailyPrevClose !== null && $dailyPrevClose > 0) {
                    $prevClose = $dailyPrevClose;
                } else {
                    $metaPrevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0.0;
                    $prevClose = ($metaPrevClose > 0) ? $metaPrevClose : ($candles[0]['close'] ?? $current);
                }
            }
            
            $changeAmount = $current - $prevClose;
            $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
            
            $displayName = $this->getStockName($ticker);
            if ($ticker === 'NQ=F') {
                $displayName = '나스닥100 선물';
            } elseif ($ticker === 'KOSPI200' || $ticker === '^KS200') {
                $displayName = '코스피 지수';
            }
            
            return response()->json([
                'ticker' => $ticker,
                'name' => $displayName,
                'current_price' => round($current, 2),
                'change_amount' => round($changeAmount, 2),
                'change_percent' => round($changePercent, 2),
                'candles' => $candles,
                'source' => 'Yahoo Finance (' . $timeframe . ')'
            ]);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Yahoo Chart Error for {$ticker}: " . $e->getMessage());
            return $this->getMockStockData($ticker, $e->getMessage(), $timeframe);
        }
    }

    public function getKOSPINightChartData($timeframe)
    {
        $nqResponse = $this->getYahooChartData('NQ=F', $timeframe);
        $nqData = json_decode($nqResponse->getContent(), true);

        // 야간선물(코스피200 선물)의 베이스는 코스피200(0002) — KIS 지수 API 사용
        $kospiResponse = $this->getKospiIndexData($timeframe, '0002');
        $kospiData = json_decode($kospiResponse->getContent(), true);
        
        if (!isset($nqData['candles']) || !isset($kospiData['candles'])) {
            return $kospiResponse;
        }
        
        $nqCandles = $nqData['candles'];
        $kospiCandles = $kospiData['candles'];
        
        $lastKospiPrice = end($kospiCandles)['close'] ?? 362.45;
        
        $nightCandles = [];
        $firstNqClose = $nqCandles[0]['close'] ?? 1.0;
        
        foreach ($nqCandles as $nqCandle) {
            $nqChangePercent = (($nqCandle['close'] - $firstNqClose) / $firstNqClose) * 100;
            $pointMove = $nqChangePercent * 3.541;
            
            $nightClose = $lastKospiPrice + $pointMove;
            
            $nqOpenPct = (($nqCandle['open'] - $firstNqClose) / $firstNqClose) * 100;
            $nqHighPct = (($nqCandle['high'] - $firstNqClose) / $firstNqClose) * 100;
            $nqLowPct = (($nqCandle['low'] - $firstNqClose) / $firstNqClose) * 100;
            
            $nightOpen = $lastKospiPrice + ($nqOpenPct * 3.541);
            $nightHigh = $lastKospiPrice + ($nqHighPct * 3.541);
            $nightLow = $lastKospiPrice + ($nqLowPct * 3.541);
            
            $nightCandles[] = [
                'time' => $nqCandle['time'],
                'open' => round($nightOpen, 2),
                'high' => round($nightHigh, 2),
                'low' => round($nightLow, 2),
                'close' => round($nightClose, 2),
                'volume' => $nqCandle['volume'],
            ];
        }
        
        $latestCandle = end($nightCandles);
        $current = $latestCandle['close'];

        if ($timeframe === '1d') {
            $prevCandle = prev($nightCandles) ?: $latestCandle;
            $prevClose = $prevCandle['close'];
        } else {
            // For intraday KOSPI night session, the daily base price is the regular session close ($lastKospiPrice)
            $prevClose = $lastKospiPrice;
        }
        
        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
        
        return response()->json([
            'ticker' => 'KOSPI_NIGHT',
            'name' => '야간 선물 (KOSPI 200)',
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $nightCandles,
            'source' => 'Simulated KRX Night Futures (' . $timeframe . ')'
        ]);
    }

    /**
     * KIS 국내업종(지수) API 공통 호출. 토큰 만료(EGW00123) 시 1회 강제 갱신·재시도.
     * 실패 시 null 반환.
     */
    private function kisIndexRequest($path, $trId, $query)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapi.koreainvestment.com:9443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }

        $client = new Client();
        $doRequest = function ($token) use ($client, $apiUrl, $path, $appKey, $appSecret, $trId, $query) {
            return $client->get("{$apiUrl}{$path}", [
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$token}",
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                    'tr_id' => $trId,
                    'custtype' => 'P',
                ],
                'query' => $query,
                'http_errors' => false,
            ]);
        };

        try {
            $token = $this->getAccessToken();
            $data = json_decode($doRequest($token)->getBody()->getContents(), true);

            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                $token = $this->getAccessToken(true);
                $data = json_decode($doRequest($token)->getBody()->getContents(), true);
            }
            return $data;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("KIS 지수 요청 실패 [{$trId}]: " . $e->getMessage());
            return null;
        }
    }

    private function kisIndexChange($row)
    {
        $chg = (float)($row['bstp_nmix_prdy_vrss'] ?? 0);
        $pct = (float)($row['bstp_nmix_prdy_ctrt'] ?? 0);
        $sign = $row['prdy_vrss_sign'] ?? '3'; // 1상한 2상승 3보합 4하한 5하락
        if ($sign === '4' || $sign === '5') {
            $chg = -abs($chg);
            $pct = -abs($pct);
        }
        return [round($chg, 2), round($pct, 2)];
    }

    private function kisIndexResponse($current, $chg, $pct, $candles, $source)
    {
        return response()->json([
            'ticker' => 'KOSPI200',
            'name' => '코스피 지수',
            'current_price' => round($current, 2),
            'change_amount' => round($chg, 2),
            'change_percent' => round($pct, 2),
            'candles' => $candles,
            'source' => $source,
        ]);
    }

    /**
     * 코스피 지수 차트 데이터를 KIS 국내업종 API 로 구성.
     * $iscd: '0001'=코스피 종합(전체, 기본), '0002'=코스피200.
     * 1d=일봉, 그 외=분봉(종가 기반 OHLC 합성 후 집계). 실패 시 Yahoo(^KS200) 폴백.
     */
    public function getKospiIndexData($timeframe, $iscd = '0001')
    {

        if ($timeframe === '1d') {
            $data = $this->kisIndexRequest(
                '/uapi/domestic-stock/v1/quotations/inquire-daily-indexchartprice',
                'FHPUP02120000',
                [
                    'FID_COND_MRKT_DIV_CODE' => 'U',
                    'FID_INPUT_ISCD' => $iscd,
                    'FID_INPUT_DATE_1' => date('Ymd', strtotime('-120 days')),
                    'FID_INPUT_DATE_2' => date('Ymd'),
                    'FID_PERIOD_DIV_CODE' => 'D',
                ]
            );

            if (!$data || ($data['rt_cd'] ?? '1') !== '0' || empty($data['output2'])) {
                return $this->getYahooChartData('KOSPI200', $timeframe);
            }

            $candles = [];
            foreach ($data['output2'] as $r) { // KIS 정렬 가정하지 않고 전부 수집 후 시간순 정렬
                if ((float)($r['bstp_nmix_prpr'] ?? 0) <= 0) {
                    continue;
                }
                $candles[] = [
                    'time' => date('Y-m-d', strtotime($r['stck_bsop_date'])),
                    'open' => round((float)$r['bstp_nmix_oprc'], 2),
                    'high' => round((float)$r['bstp_nmix_hgpr'], 2),
                    'low' => round((float)$r['bstp_nmix_lwpr'], 2),
                    'close' => round((float)$r['bstp_nmix_prpr'], 2),
                    'volume' => (int)($r['acml_vol'] ?? 0),
                ];
            }

            if (empty($candles)) {
                return $this->getYahooChartData('KOSPI200', $timeframe);
            }

            usort($candles, function ($a, $b) {
                return strcmp($a['time'], $b['time']); // 과거 → 최신
            });

            $o1 = $data['output1'] ?? [];
            $current = (float)($o1['bstp_nmix_prpr'] ?? end($candles)['close']);
            [$chg, $pct] = $this->kisIndexChange($o1);
            return $this->kisIndexResponse($current, $chg, $pct, $candles, 'KIS 코스피 지수 (일봉)');
        }

        // 분봉 (3m/5m/10m/30m/1h) — KIS 업종 분봉(종가만 제공) → OHLC 합성 후 집계
        $data = $this->kisIndexRequest(
            '/uapi/domestic-stock/v1/quotations/inquire-time-indexchartprice',
            'FHPUP02110200',
            [
                'FID_COND_MRKT_DIV_CODE' => 'U',
                'FID_INPUT_ISCD' => $iscd,
                'FID_INPUT_HOUR_1' => '',
                'FID_PW_DATA_INCU_YN' => 'Y',
                'FID_ETC_CLS_CODE' => '',
            ]
        );

        if (!$data || ($data['rt_cd'] ?? '1') !== '0' || empty($data['output'])) {
            return $this->getYahooChartData('KOSPI200', $timeframe);
        }

        // 첫 행 bsop_hour '888888'(요약) 및 비정상 시각 제외
        $rows = array_values(array_filter($data['output'], function ($r) {
            $h = $r['bsop_hour'] ?? '';
            return preg_match('/^\d{6}$/', $h) && $h !== '888888' && (int)substr($h, 0, 2) <= 23;
        }));

        $tzSeoul = new \DateTimeZone('Asia/Seoul');
        $todayStr = (new \DateTime('now', $tzSeoul))->format('Ymd');

        // 종가만 제공되므로 (ts, close, vol, row) 로 파싱 후 시간순 정렬 (KIS 정렬 가정하지 않음)
        $points = [];
        foreach ($rows as $r) {
            $close = (float)$r['bstp_nmix_prpr'];
            if ($close <= 0) {
                continue;
            }
            $dt = \DateTime::createFromFormat('YmdHis', $todayStr . $r['bsop_hour'], $tzSeoul);
            if (!$dt) {
                continue;
            }
            $ts = $dt->getTimestamp();
            $ts = $ts - ($ts % 60);
            $points[] = ['ts' => $ts, 'close' => $close, 'vol' => (int)($r['cntg_vol'] ?? 0), 'row' => $r];
        }

        if (empty($points)) {
            return $this->getYahooChartData('KOSPI200', $timeframe);
        }

        usort($points, function ($a, $b) {
            return $a['ts'] <=> $b['ts']; // 과거 → 최신
        });

        // 전봉 종가를 시가로 사용해 OHLC 합성 (인덱스 분봉은 종가만 제공)
        $oneMin = [];
        $prevClose = null;
        foreach ($points as $p) {
            $close = $p['close'];
            $open = $prevClose ?? $close;
            $oneMin[] = [
                'time' => $p['ts'],
                'open' => round($open, 2),
                'high' => round(max($open, $close), 2),
                'low' => round(min($open, $close), 2),
                'close' => round($close, 2),
                'volume' => $p['vol'],
            ];
            $prevClose = $close;
        }

        $intervalSeconds = 180;
        if ($timeframe === '5m') $intervalSeconds = 300;
        elseif ($timeframe === '10m') $intervalSeconds = 600;
        elseif ($timeframe === '30m') $intervalSeconds = 1800;
        elseif ($timeframe === '1h') $intervalSeconds = 3600;

        $candles = $this->aggregateCandles($oneMin, $intervalSeconds);

        // 시간순 정렬된 마지막 분의 원본 행에서 현재가·등락 추출
        $latestPoint = end($points);
        $latestRow = $latestPoint['row'];
        $current = (float)$latestRow['bstp_nmix_prpr'];
        [$chg, $pct] = $this->kisIndexChange($latestRow);
        return $this->kisIndexResponse($current, $chg, $pct, $candles, 'KIS 코스피 지수 (' . $timeframe . ')');
    }

    private function fetchDomesticPriceFromKis($ticker)
    {
        $code = preg_replace('/(\.KS|\.KQ)$/i', '', $ticker);
        
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        
        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }
        
        $lastSuccessKey = "kis_last_successful_price_{$ticker}";
        
        try {
            $accessToken = $this->getAccessToken();
            $client = new Client();
            $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHPST01010000' : 'FHPST01010000';
            
            $response = $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/inquire-price", [
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$accessToken}",
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                    'tr_id' => $trId,
                ],
                'query' => [
                    'FID_COND_MRKT_DIV_CODE' => 'J',
                    'FID_INPUT_ISCD' => $code,
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['output']['stck_prpr'])) {
                $price = (float)$data['output']['stck_prpr'];
                $change = (float)$data['output']['prdy_vrss'];
                $changePercent = (float)$data['output']['prdy_ctrt'];
                $sign = $data['output']['prdy_vrss_sign'] ?? '3';
                
                if ($sign === '4' || $sign === '5') {
                    $change = -$change;
                    $changePercent = -$changePercent;
                }
                
                $result = [
                    'price' => $price,
                    'change_amount' => $change,
                    'change_percent' => $changePercent,
                ];
                
                Cache::put($lastSuccessKey, $result, 86400); // Cache for 24 hours
                return $result;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch domestic price from KIS: " . $e->getMessage());
        }
        
        // Fallback to last successful price cache if current API call fails
        $fallback = Cache::get($lastSuccessKey);
        if ($fallback) {
            \Illuminate\Support\Facades\Log::info("Using fallback KIS price for {$ticker} due to API failure/rate limit");
            return $fallback;
        }
        
        return null;
    }

    private function getStockName($ticker)
    {
        $names = [
            'TSLA' => 'Tesla, Inc.',
            'AAPL' => 'Apple Inc.',
            'NVDA' => 'NVIDIA Corporation',
            'MSFT' => 'Microsoft Corporation',
            'AMZN' => 'Amazon.com, Inc.',
            'GOOGL' => 'Alphabet Inc.',
            'USDKRW=X' => '원/달러 환율',
            '0167A0.KS' => 'SOL AI반도체TOP2플러스',
            '0167A0' => 'SOL AI반도체TOP2플러스',
            '0167AO.KS' => 'SOL AI반도체TOP2플러스',
            '0167AO' => 'SOL AI반도체TOP2플러스',
            '005930.KS' => '삼성전자',
            '005930' => '삼성전자',
            '000660.KS' => 'SK하이닉스',
            '000660' => 'SK하이닉스',
            '035420.KS' => 'NAVER',
            '035420' => 'NAVER',
            '035720.KS' => '카카오',
            '035720' => '카카오',
        ];

        if (isset($names[$ticker])) {
            return $names[$ticker];
        }

        $cleanTicker = strtoupper($ticker);

        // 국내 종목: krx_stocks.json 에서 한글명 조회
        if (preg_match('/(\.KS|\.KQ)$/', $cleanTicker) || preg_match('/^\d{6}$/', $cleanTicker)) {
            $code = preg_replace('/(\.KS|\.KQ)$/', '', $cleanTicker);
            $filePath = storage_path('app/krx_stocks.json');
            if (file_exists($filePath)) {
                $stocks = json_decode(file_get_contents($filePath), true);
                if (is_array($stocks)) {
                    foreach ($stocks as $stock) {
                        if ($stock['code'] === $code) {
                            return $stock['name'];
                        }
                    }
                }
            }
        }

        // 미국 종목: us_stocks.json 에서 한글명 조회 (정적 캐시로 파일 중복 로드 방지)
        static $usStocksMap = null;
        if ($usStocksMap === null) {
            $usPath = storage_path('app/us_stocks.json');
            if (file_exists($usPath)) {
                $raw = json_decode(file_get_contents($usPath), true);
                $usStocksMap = [];
                if (is_array($raw)) {
                    foreach ($raw as $s) {
                        $usStocksMap[$s['symbol']] = $s;
                    }
                }
            } else {
                $usStocksMap = [];
            }
        }
        if (isset($usStocksMap[$cleanTicker])) {
            $s = $usStocksMap[$cleanTicker];
            // 한글명이 영문명과 다를 때만 한글명을 반환 (같으면 영문명만)
            return ($s['koName'] !== $s['enName']) ? $s['koName'] : $s['enName'];
        }

        return $ticker . ' Inc.';
    }

    public function searchStocks(Request $request)
    {
        $query = trim($request->query('q', ''));
        $type = $request->query('type', 'all'); // 'kr', 'us', 'all'
        
        if (empty($query)) {
            return response()->json([]);
        }

        // 1. 국내 주식의 경우: krx_stocks.json + DB stocks(KR) 합산 검색
        if ($type === 'kr') {
            $queryLower  = strtolower($query);
            $queryChosung = $this->getChosung($queryLower);

            // 1-a. krx_stocks.json 검색 (초성/한글/코드)
            $jsonResults = [];
            $seenCodes   = [];   // DB 추가분 중복 제거용

            $filePath = storage_path('app/krx_stocks.json');
            if (file_exists($filePath)) {
                $jsonContent = file_get_contents($filePath);
                $krxStocks   = json_decode($jsonContent, true);
                if (is_array($krxStocks)) {
                    foreach ($krxStocks as $stock) {
                        $name        = $stock['name'];
                        $code        = $stock['code'];
                        $nameLower   = strtolower($name);
                        $nameChosung = $this->getChosung($nameLower);

                        $match = (strpos($code, $queryLower) !== false)
                            || (strpos($nameLower, $queryLower) !== false)
                            || (strpos($nameChosung, $queryChosung) !== false);

                        if ($match) {
                            $jsonResults[] = [
                                'ticker'   => $stock['ticker'],
                                'name'     => $name,
                                'isKorean' => true,
                                'exchange' => $stock['market'],
                            ];
                            $seenCodes[$code] = true;
                        }

                        if (count($jsonResults) >= 20) {
                            break;
                        }
                    }
                }
            }

            // 1-b. DB stocks(KR) 검색 — krx_stocks.json 에 없는 종목(SOL ETF 등)을 보완
            // symbol 또는 name 에 검색어 포함 시 추가 (초성은 DB 레벨 불가 → 이름/코드 LIKE 만)
            $dbResults = [];
            try {
                $dbStocks = \App\Models\Stock::where('market', 'KR')
                    ->where(function ($q) use ($queryLower) {
                        $q->whereRaw('LOWER(symbol) LIKE ?', ['%' . $queryLower . '%'])
                          ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $queryLower . '%']);
                    })
                    ->limit(20)
                    ->get(['symbol', 'name', 'exchange']);

                foreach ($dbStocks as $dbStock) {
                    // krx_stocks.json 결과와 중복이면 건너뜀
                    if (isset($seenCodes[$dbStock->symbol])) {
                        continue;
                    }
                    $exchange      = $dbStock->exchange ?? 'KR';
                    $dbResults[]   = [
                        'ticker'   => $dbStock->symbol . '.KS',   // 프론트 일관성 유지
                        'name'     => $dbStock->name,
                        'isKorean' => true,
                        'exchange' => $exchange,
                    ];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[searchStocks] DB KR 검색 오류: ' . $e->getMessage());
            }

            // krx_stocks.json 결과 우선, DB 추가분을 뒤에 붙여 최대 20개
            $merged = array_slice(array_merge($jsonResults, $dbResults), 0, 20);
            return response()->json($merged);
        }

        // 2. 미국 주식: us_stocks.json(KIS 마스터) 우선 → Yahoo Finance 폴백(보완)
        //    - 한글/영문/티커 부분일치 검색
        //    - 한글 검색어면 us_stocks.json 매칭이 우선 노출
        //    - Yahoo 는 마스터에 없는 종목 보완용

        $queryUpper  = strtoupper($query);
        $queryLower  = strtolower($query);
        // 한글 포함 여부 판정 (U+AC00~U+D7A3 범위)
        $isKoreanQuery = (bool) preg_match('/[\x{AC00}-\x{D7A3}]/u', $query);

        // 2-a. us_stocks.json (KIS 마스터) 검색
        $masterResults = [];
        $seenSymbols   = [];   // Yahoo 결과와 중복 제거용

        $usStocksPath = storage_path('app/us_stocks.json');
        if (file_exists($usStocksPath)) {
            $usStocks = json_decode(file_get_contents($usStocksPath), true);
            if (is_array($usStocks)) {
                foreach ($usStocks as $stock) {
                    $symbol  = $stock['symbol'] ?? '';
                    $koName  = $stock['koName'] ?? '';
                    $enName  = $stock['enName'] ?? '';
                    $excd    = $stock['exchange'] ?? '';

                    $koLower = mb_strtolower($koName, 'UTF-8');
                    $enLower = strtolower($enName);

                    $match = (stripos($symbol, $queryUpper) !== false)
                        || (mb_strpos($koLower, $queryLower, 0, 'UTF-8') !== false)
                        || (strpos($enLower, $queryLower) !== false);

                    if ($match) {
                        // 한글명이 있으면 한글명 우선, 없으면 영문명
                        $displayName = ($koName !== '' && $koName !== $enName) ? "{$koName} ({$enName})" : $enName;

                        $masterResults[] = [
                            'ticker'   => $symbol,
                            'name'     => $displayName,
                            'isKorean' => false,
                            'exchange' => $excd,
                        ];
                        $seenSymbols[$symbol] = true;
                    }

                    if (count($masterResults) >= 20) {
                        break;
                    }
                }
            }
        }

        // 2-b. Yahoo Finance 폴백 — 한글 검색어면 스킵(Yahoo 는 한글 검색 불가),
        //      영문/티커 검색이고 마스터 결과가 부족할 때만 보완
        $yahooResults = [];
        if (! $isKoreanQuery) {
            try {
                $client = new Client();
                $url = "https://query1.finance.yahoo.com/v1/finance/search?q=" . urlencode($query) . "&quotesCount=20&newsCount=0";
                $response = $client->get($url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ],
                    'timeout' => 5,
                ]);

                $data   = json_decode($response->getBody()->getContents(), true);
                $quotes = $data['quotes'] ?? [];

                foreach ($quotes as $quote) {
                    $quoteType = $quote['quoteType'] ?? '';
                    if ($quoteType !== 'EQUITY' && $quoteType !== 'ETF') {
                        continue;
                    }

                    $symbol    = $quote['symbol'] ?? '';
                    $name      = $quote['longname'] ?? $quote['shortname'] ?? $symbol;
                    $exchange  = $quote['exchange'] ?? '';
                    $isDomestic = preg_match('/(\.KS|\.KQ)$/i', $symbol) || preg_match('/^\d{6}$/', $symbol);

                    // type='us' 면 국내 종목 제외
                    if ($type === 'us' && $isDomestic) {
                        continue;
                    }

                    // us_stocks.json 에서 이미 찾은 심볼은 중복 제거
                    if (isset($seenSymbols[$symbol])) {
                        continue;
                    }

                    $yahooResults[] = [
                        'ticker'   => $symbol,
                        'name'     => $name,
                        'isKorean' => (bool) $isDomestic,
                        'exchange' => $exchange,
                    ];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('[searchStocks] Yahoo 검색 오류(폴백 스킵): ' . $e->getMessage());
            }
        }

        // 한글 검색어면 마스터 결과 단독, 영문이면 마스터 우선 + Yahoo 보완
        $merged = array_slice(array_merge($masterResults, $yahooResults), 0, 20);
        return response()->json($merged);
    }

    private function getChosung($str)
    {
        $chosung = ["ㄱ", "ㄲ", "ㄴ", "ㄷ", "ㄸ", "ㄹ", "ㅁ", "ㅂ", "ㅃ", "ㅅ", "ㅆ", "ㅇ", "ㅈ", "ㅉ", "ㅊ", "ㅋ", "ㅌ", "ㅍ", "ㅎ"];
        $result = "";
        
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $code = $this->utf8Ord($char);
            
            if ($code >= 0xAC00 && $code <= 0xD7A3) {
                $temp = $code - 0xAC00;
                $choIdx = (int)($temp / 588);
                $result .= $chosung[$choIdx];
            } else {
                $result .= $char;
            }
        }
        return $result;
    }

    private function utf8Ord($ch)
    {
        $len = strlen($ch);
        if ($len <= 0) return 0;
        $h = ord($ch[0]);
        if ($h <= 0x7F) return $h;
        if ($h < 0xC2) return 0;
        if ($h <= 0xDF && $len > 1) return ($h & 0x1F) << 6 | (ord($ch[1]) & 0x3F);
        if ($h <= 0xEF && $len > 2) return ($h & 0x0F) << 12 | (ord($ch[1]) & 0x3F) << 6 | (ord($ch[2]) & 0x3F);
        if ($h <= 0xF4 && $len > 3) return ($h & 0x0F) << 18 | (ord($ch[1]) & 0x3F) << 12 | (ord($ch[2]) & 0x3F) << 6 | (ord($ch[3]) & 0x3F);
        return 0;
    }

    private function aggregateCandles($candles1m, $intervalSeconds)
    {
        if (empty($candles1m)) {
            return [];
        }

        $grouped = [];
        foreach ($candles1m as $c) {
            $timestamp = (int)$c['time'];
            $periodTime = $timestamp - ($timestamp % $intervalSeconds);
            
            if (!isset($grouped[$periodTime])) {
                $grouped[$periodTime] = [
                    'time' => $periodTime,
                    'open' => $c['open'],
                    'high' => $c['high'],
                    'low' => $c['low'],
                    'close' => $c['close'],
                    'volume' => $c['volume']
                ];
            } else {
                $grouped[$periodTime]['high'] = max($grouped[$periodTime]['high'], $c['high']);
                $grouped[$periodTime]['low'] = min($grouped[$periodTime]['low'], $c['low']);
                $grouped[$periodTime]['close'] = $c['close'];
                $grouped[$periodTime]['volume'] += $c['volume'];
            }
        }

        ksort($grouped);
        return array_values($grouped);
    }

    /**
     * 해당 시각이 KRX 개장일(거래일)인지 판정한다.
     * MarketSessionService 에 위임.
     */
    private function isKrTradingDay($timestamp)
    {
        return $this->sessionService->isKrTradingDay((int)$timestamp);
    }

    private function isKrxMarketOpen($timestamp)
    {
        // 공휴일·주말이면 개장 아님 (KIS API 기준)
        if (!$this->isKrTradingDay($timestamp)) {
            return false;
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int)$dt->format('Hi'); // e.g. 0900
        return ($timeVal >= 900 && $timeVal <= 1530);
    }

    private function accumulateRealTimePrice($ticker, $price, $lastYahooTime)
    {
        $cacheKey = "kis_accumulated_1m_{$ticker}";
        $accumulated = Cache::get($cacheKey, []);
        
        $now = time();
        if (!$this->isKrxMarketOpen($now)) {
            return $accumulated;
        }
        
        $currentPeriodTime = $now - ($now % 60);
        
        // Align last Yahoo time to 60s
        $alignedLastYahooTime = $lastYahooTime - ($lastYahooTime % 60);
        
        // If empty and we have a last Yahoo time, bootstrap the array to fill the delay gap
        if (empty($accumulated) && $lastYahooTime > 0) {
            $tempTime = $alignedLastYahooTime + 60;
            $lastClose = $price; // Fallback
            while ($tempTime <= $currentPeriodTime) {
                if ($this->isKrxMarketOpen($tempTime)) {
                    $accumulated[] = [
                        'time' => $tempTime,
                        'open' => $lastClose,
                        'high' => $lastClose,
                        'low' => $lastClose,
                        'close' => $lastClose,
                        'volume' => 0
                    ];
                }
                $tempTime += 60;
            }
        }
        
        if (empty($accumulated)) {
            $accumulated[] = [
                'time' => $currentPeriodTime,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => 0
            ];
        } else {
            $lastIdx = count($accumulated) - 1;
            $lastItem = $accumulated[$lastIdx];
            
            if ($lastItem['time'] === $currentPeriodTime) {
                $accumulated[$lastIdx]['close'] = $price;
                $accumulated[$lastIdx]['high'] = max($lastItem['high'], $price);
                $accumulated[$lastIdx]['low'] = min($lastItem['low'], $price);
            } else {
                $prevClose = $lastItem['close'];
                
                // Fill offline gap
                $gapTime = max($lastItem['time'] + 60, time() - 86400);
                $gapTime = $gapTime - ($gapTime % 60); // Align to 60s
                while ($gapTime < $currentPeriodTime) {
                    if ($this->isKrxMarketOpen($gapTime)) {
                        $accumulated[] = [
                            'time' => $gapTime,
                            'open' => $prevClose,
                            'high' => $prevClose,
                            'low' => $prevClose,
                            'close' => $prevClose,
                            'volume' => 0
                        ];
                    }
                    $gapTime += 60;
                }
                
                $accumulated[] = [
                    'time' => $currentPeriodTime,
                    'open' => $prevClose,
                    'high' => max($prevClose, $price),
                    'low' => min($prevClose, $price),
                    'close' => $price,
                    'volume' => 0
                ];
            }
        }
        
        if (count($accumulated) > 120) {
            $accumulated = array_slice($accumulated, -120);
        }
        
        Cache::put($cacheKey, $accumulated, 86400);
        return $accumulated;
    }

    /**
     * Yahoo Finance SPY meta 로 오늘(NY)이 미국 거래일인지 판정한다.
     * MarketSessionService 에 위임.
     */
    private function isUsMarketTradingToday(): bool
    {
        return $this->sessionService->isUsMarketTradingToday();
    }

    private function getUsMarketSessionInfo($timestamp)
    {
        return $this->sessionService->getUsSession((int)$timestamp);
    }

    private function isUsMarketOpen($timestamp)
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N');
        $hour = (int)$dt->format('H');
        $minute = (int)$dt->format('i');
        $timeVal = $hour * 100 + $minute;

        // 주말 휴장 경계(NY): 금 20:00 ~ 일 20:00
        if ($dayOfWeek === 6) { // Saturday
            return false;
        }
        if ($dayOfWeek === 7 && $timeVal < 2000) { // Sunday before 8 PM EST
            return false;
        }
        if ($dayOfWeek === 5 && $timeVal >= 2000) { // Friday after 8 PM EST
            return false;
        }

        // 주간거래(20:00~04:00)는 다음 거래일로 이어지는 세션 — 주말만 아니면 개장
        if ($timeVal >= 2000 || $timeVal < 400) {
            return true;
        }

        // 데이 세션(04:00~20:00)은 'NY 오늘'이 거래일일 때만 — 공휴일이면 미개장
        return $this->isUsMarketTradingToday();
    }

    private function getKrMarketSessionInfo($timestamp)
    {
        return $this->sessionService->getKrSession((int)$timestamp);
    }

    /**
     * 미국 주식 현재가를 KIS API 로 조회한다.
     *
     * 주간거래(Blue Ocean ATS, 20:00~04:00 ET) 시간대에는 정규장 EXCD(NAS/NYS/AMS)가
     * 전일 종가(고정값)를 반환하므로, 주간거래 전용 코드를 먼저 시도한다:
     *   - BAQ : Nasdaq Blue Ocean (나스닥 상장 주간거래)
     *   - BAY : NYSE Blue Ocean   (뉴욕거래소 상장 주간거래)
     *   - BAA : AMEX Blue Ocean   (아멕스 상장 주간거래)
     * 주간거래 코드로 값을 얻지 못하면 정규장 코드(NAS/NYS/AMS)로 폴백한다.
     * 정규장/프리/애프터 시간대에는 정규장 코드를 우선 사용한다.
     *
     * [성능 최적화] EXCD 프로빙 결과 캐시:
     *   처음 한 번 어느 거래소에서 성공했는지를 "세션 그룹 + 종목" 단위로 캐싱한다.
     *   캐시 히트 시 해당 거래소에 바로 1회만 호출 → 최대 6회 → 1~2회로 단축.
     *   세션 그룹: 정규/프리/애프터 = "regular", 주간거래 = "overnight"
     *   (주간거래 세션이 전환되면 EXCD 도 달라져야 하므로 그룹별 분리)
     *   캐시 TTL: 하루 (주간→정규 전환 시 폴백이 자동으로 재탐색 후 재캐싱)
     */
    private function fetchOverseasPriceFromKis($ticker)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }

        $lastSuccessKey = "kis_last_successful_overseas_price_{$ticker}";

        // 세션 판정 후 시도 순서 결정 — 주간거래/정규장별 EXCD 우선순위가 다르다
        $session = $this->getUsMarketSessionInfo(time());
        $sessionGroup = ($session === '주간거래') ? 'overnight' : 'regular';

        if ($session === '주간거래') {
            $allExchanges = ['BAQ', 'BAY', 'BAA', 'NAS', 'NYS', 'AMS'];
        } else {
            $allExchanges = ['NAS', 'NYS', 'AMS', 'BAQ', 'BAY', 'BAA'];
        }

        // 종목별·세션그룹별로 이전에 성공한 EXCD 캐시 조회
        // → 히트 시 해당 거래소를 맨 앞으로 배치해 1~2회 만에 조회 완료
        $excdCacheKey = "kis_excd_{$ticker}_{$sessionGroup}";
        $cachedExcd = Cache::get($excdCacheKey);
        if ($cachedExcd !== null) {
            // 성공 거래소를 맨 앞에 두되, 나머지 폴백도 그대로 유지
            $exchanges = array_merge(
                [$cachedExcd],
                array_values(array_filter($allExchanges, fn($e) => $e !== $cachedExcd))
            );
        } else {
            $exchanges = $allExchanges;
        }

        try {
            $accessToken = $this->getAccessToken();
            $client = new Client();

            foreach ($exchanges as $exchange) {
                try {
                    $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                        'headers' => [
                            'content-type' => 'application/json',
                            'authorization' => "Bearer {$accessToken}",
                            'appkey' => $appKey,
                            'appsecret' => $appSecret,
                            'tr_id' => 'HHDFS00000300',
                        ],
                        'query' => [
                            'AUTH' => '',
                            'EXCD' => $exchange,
                            'SYMB' => $ticker,
                        ]
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);
                    if (isset($data['output']['last']) && (float)$data['output']['last'] > 0) {
                        $output = $data['output'];
                        $result = [
                            'price' => (float)$output['last'],
                            'change_amount' => (float)$output['diff'],
                            'change_percent' => (float)$output['rate'],
                            'sign' => $output['sign'] ?? '3',
                            'excd' => $exchange, // 디버깅·로그용
                        ];

                        if ($result['sign'] === '4' || $result['sign'] === '5') {
                            $result['change_amount'] = -abs($result['change_amount']);
                            $result['change_percent'] = -abs($result['change_percent']);
                        } else {
                            $result['change_amount'] = abs($result['change_amount']);
                            $result['change_percent'] = abs($result['change_percent']);
                        }

                        // 성공한 EXCD 를 세션 그룹별로 캐싱 — 다음 사이클부터 즉시 1회 적중
                        if ($cachedExcd !== $exchange) {
                            Cache::put($excdCacheKey, $exchange, 86400); // 24시간
                        }
                        Cache::put($lastSuccessKey, $result, 86400); // 24 hours
                        return $result;
                    }
                } catch (\Exception $ex) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch overseas price from KIS: " . $e->getMessage());
        }

        $fallback = Cache::get($lastSuccessKey);
        if ($fallback) {
            return $fallback;
        }

        return null;
    }

    private function accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime)
    {
        $cacheKey = "kis_accumulated_us_1m_{$ticker}";
        $accumulated = Cache::get($cacheKey, []);

        $now = time();
        if (!$this->isUsMarketOpen($now)) {
            return $accumulated;
        }

        $currentPeriodTime = $now - ($now % 60);
        $alignedLastYahooTime = $lastYahooTime - ($lastYahooTime % 60);

        if (empty($accumulated) && $lastYahooTime > 0) {
            // bootstrap: Yahoo 마지막 봉 이후부터 지금까지 빈 분봉을 채운다.
            // 주간거래 시작 시 Yahoo 마지막 봉이 수 시간~수일 전일 수 있어
            // 최대 24시간 이내 구간만 채워 루프가 과도하게 길어지는 것을 방지한다.
            $bootstrapStart = max($alignedLastYahooTime + 60, $now - 86400);
            $bootstrapStart = $bootstrapStart - ($bootstrapStart % 60);
            $tempTime = $bootstrapStart;
            $lastClose = $price;
            while ($tempTime <= $currentPeriodTime) {
                if ($this->isUsMarketOpen($tempTime)) {
                    $accumulated[] = [
                        'time' => $tempTime,
                        'open' => $lastClose,
                        'high' => $lastClose,
                        'low' => $lastClose,
                        'close' => $lastClose,
                        'volume' => 0
                    ];
                }
                $tempTime += 60;
            }
        }
        
        if (empty($accumulated)) {
            $accumulated[] = [
                'time' => $currentPeriodTime,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => 0
            ];
        } else {
            $lastIdx = count($accumulated) - 1;
            $lastItem = $accumulated[$lastIdx];
            
            if ($lastItem['time'] === $currentPeriodTime) {
                $accumulated[$lastIdx]['close'] = $price;
                $accumulated[$lastIdx]['high'] = max($lastItem['high'], $price);
                $accumulated[$lastIdx]['low'] = min($lastItem['low'], $price);
            } else {
                $prevClose = $lastItem['close'];
                
                // Fill offline gap
                $gapTime = max($lastItem['time'] + 60, time() - 86400);
                $gapTime = $gapTime - ($gapTime % 60);
                while ($gapTime < $currentPeriodTime) {
                    if ($this->isUsMarketOpen($gapTime)) {
                        $accumulated[] = [
                            'time' => $gapTime,
                            'open' => $prevClose,
                            'high' => $prevClose,
                            'low' => $prevClose,
                            'close' => $prevClose,
                            'volume' => 0
                        ];
                    }
                    $gapTime += 60;
                }
                
                $accumulated[] = [
                    'time' => $currentPeriodTime,
                    'open' => $prevClose,
                    'high' => max($prevClose, $price),
                    'low' => min($prevClose, $price),
                    'close' => $price,
                    'volume' => 0
                ];
            }
        }
        
        if (count($accumulated) > 120) {
            $accumulated = array_slice($accumulated, -120);
        }
        
        Cache::put($cacheKey, $accumulated, 86400);
        return $accumulated;
    }

    public function getEarningsDate($ticker)
    {
        $ticker = strtoupper($ticker);
        
        $cacheKey = "earnings_date_{$ticker}";
        return Cache::remember($cacheKey, 14400, function () use ($ticker) {
            try {
                $client = new Client();
                $url = "https://finance.yahoo.com/quote/{$ticker}";
                
                $response = $client->get($url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
                    ],
                    'timeout' => 7
                ]);
                
                $html = $response->getBody()->getContents();
                
                // 1. JSON Regex (Escaped Quotes Support in state scripts)
                if (preg_match('/\\\\?"earningsDate\\\\?":\s*\[\s*\{\s*\\\\?"raw\\\\?":\s*(\d+)/i', $html, $matches)) {
                    $rawTime = (int)$matches[1];
                    if ($rawTime > 0) {
                        $dateTime = new \DateTime("@{$rawTime}");
                        $dateTime->setTimezone(new \DateTimeZone('Asia/Seoul'));
                        
                        $hour = (int)$dateTime->format('H');
                        $minute = (int)$dateTime->format('i');
                        if ($hour === 0 && $minute === 0) {
                            $formatted = $dateTime->format('Y-m-d');
                        } else {
                            $formatted = $dateTime->format('Y-m-d H:i');
                        }
                        
                        return response()->json([
                            'success' => true,
                            'earnings_date' => $formatted,
                            'raw' => $rawTime
                        ]);
                    }
                }
                
                // 2. Fallback: HTML Markup Regex (e.g. "Earnings Date </span> <span ...>Date</span>")
                if (preg_match('/title="Earnings Date">Earnings Date\s*<\/span>\s*<span[^>]*>(.*?)<\/span>/is', $html, $matches)) {
                    $rawText = trim($matches[1]);
                    if ($rawText && strtolower($rawText) !== '--') {
                        return response()->json([
                            'success' => true,
                            'earnings_date' => $rawText,
                            'raw' => null
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch HTML earnings for {$ticker}: " . $e->getMessage());
            }
            
            return response()->json([
                'success' => false,
                'message' => '실적발표일 정보가 없습니다.'
            ]);
        });
    }
}
