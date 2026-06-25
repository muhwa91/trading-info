<?php

namespace App\Http\Controllers;

use App\Services\MarketSessionService;
use App\Services\Toss\TossCandleProvider;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossStockMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;

class StockController extends Controller
{
    private MarketSessionService $sessionService;
    private TossPriceFetcher $tossPriceFetcher;
    private TossCandleProvider $tossCandleProvider;
    private TossStockMaster $stockMaster;

    public function __construct(
        MarketSessionService $sessionService,
        TossPriceFetcher $tossPriceFetcher,
        TossCandleProvider $tossCandleProvider,
        TossStockMaster $stockMaster
    ) {
        $this->sessionService      = $sessionService;
        $this->tossPriceFetcher    = $tossPriceFetcher;
        $this->tossCandleProvider  = $tossCandleProvider;
        $this->stockMaster         = $stockMaster;
    }

    public function getStockData(Request $request, $ticker)
    {
        $ticker = strtoupper($ticker);
        $ticker = str_replace('0167AO', '0167A0', $ticker);
        $timeframe = strtolower($request->query('timeframe', '1d'));

        // WS 전송 경로는 stale 캐시 허용(케이던스 유지 목적).
        // REST 직접조회 경로는 허용하지 않아 만료 시 동기 fetch 로 갱신한다.
        $allowStale = (bool) $request->attributes->get('ws_allow_stale', false);

        // 코스피 지수(KOSPI200) — Yahoo Finance ^KS11 으로 이전 (KIS 제거)
        // 캐시 키를 yahoo_stock_data_^KS11_{timeframe} 으로 통일해 ^KS11 직접 요청과 공유한다.
        if ($ticker === 'KOSPI200') {
            // TTL 15초: Yahoo 가 ~30초마다 갱신하므로 이에 맞춰 단축.
            $cacheKey = "yahoo_stock_data_^KS11_{$timeframe}";
            if ($allowStale) {
                // WS 전송 경로: stale 캐시 우선 읽기(동기 fetch 금지 — 케이던스 유지)
                $cached = Cache::get($cacheKey) ?? Cache::get("{$cacheKey}_last");
                if ($cached === null) {
                    // cold-start: 캐시 없음 → 빈 캔들로 응답
                    return response()->json(['ticker' => $ticker, 'candles' => [], 'source' => 'cold-start']);
                }
                $response = $cached;
            } else {
                // REST 직접조회 경로: 만료 시 동기 갱신 허용
                $response = Cache::remember($cacheKey, 15, function () use ($timeframe) {
                    return $this->getYahooChartData('^KS11', $timeframe);
                });
            }

            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                // ticker 는 프론트가 KOSPI200 으로 식별하므로 유지
                $content['ticker'] = 'KOSPI200';
                $content['name']   = '코스피 지수';
                $content['session'] = $this->getKrMarketSessionInfo(time());
                // 프론트가 휴장일(주말·공휴일)에 코스피 칸을 숨기는 판단에 사용
                $content['is_trading_day'] = $this->isKrTradingDay(time());
                return response()->json($content);
            }
            return $response;
        }

        if ($ticker === 'NQ=F' || $ticker === '^KS200' || $ticker === 'USDKRW=X') {
            // 지수/환율 Yahoo 캔들: TTL 15초.
            // Yahoo 가 NQ 데이터를 ~30초마다 갱신하므로 TTL 을 단축해 차트가 자주 전진하도록 한다.
            // 개별주식의 KIS 오버레이(3초) 와 유사하게, 캔들 갱신 사이에도 현재가가 최신을 가리키도록
            // meta.regularMarketPrice 를 마지막 봉 close·current_price 에 반영한다.
            $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}";
            if ($allowStale) {
                // WS 전송 경로: stale 캐시 우선 읽기(동기 fetch 금지 — 케이던스 유지)
                $cached = Cache::get($cacheKey) ?? Cache::get("{$cacheKey}_last");
                if ($cached === null) {
                    // cold-start: 캐시 없음 → 빈 캔들로 응답
                    return response()->json(['ticker' => $ticker, 'candles' => [], 'source' => 'cold-start']);
                }
                $response = $cached;
            } else {
                // REST 직접조회 경로: 만료 시 동기 갱신 허용
                $response = Cache::remember($cacheKey, 15, function () use ($ticker, $timeframe) {
                    return $this->getYahooChartData($ticker, $timeframe);
                });
            }

            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                // ── meta.regularMarketPrice 보정 (분봉·1d 제외) ────────────────────────
                // Yahoo v8 API 의 meta.regularMarketPrice 는 캔들 캐시와 무관하게
                // ~30초마다 갱신되는 현재가다. 캔들 캐시(15초)가 살아있는 구간에도
                // 이 값으로 마지막 봉 close·current_price 를 보정해 체감 갱신 빈도를 높인다.
                // 조건: 분봉 계열(timeframe !== '1d')이고 regularMarketPrice 가 양수일 때만.
                // 등락률 계산(prevClose 기준)은 getYahooChartData() 내부에서 이미 완료됐으므로
                // change_amount / change_percent 는 건드리지 않는다.
                if (
                    $timeframe !== '1d'
                    && isset($content['candles'])
                    && count($content['candles']) > 0
                    && isset($content['meta']['regularMarketPrice'])
                    && (float)$content['meta']['regularMarketPrice'] > 0
                ) {
                    $livePrice = round((float)$content['meta']['regularMarketPrice'], 2);
                    $lastIdx   = count($content['candles']) - 1;

                    // 마지막 봉 close 를 현재가로 갱신 (high/low 는 봉의 실제 레인지이므로 유지)
                    $content['candles'][$lastIdx]['close'] = $livePrice;
                    // high/low 도 live price 기준으로 확장 (봉 범위가 좁아지지 않게)
                    if ($livePrice > $content['candles'][$lastIdx]['high']) {
                        $content['candles'][$lastIdx]['high'] = $livePrice;
                    }
                    if ($livePrice < $content['candles'][$lastIdx]['low']) {
                        $content['candles'][$lastIdx]['low'] = $livePrice;
                    }
                    $content['current_price'] = $livePrice;
                }
                // ────────────────────────────────────────────────────────────────────────

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
            if ($allowStale) {
                // WS 전송 경로: stale 캐시 우선 읽기(동기 fetch 금지 — 케이던스 유지)
                $cached = Cache::get($cacheKey) ?? Cache::get("{$cacheKey}_last");
                if ($cached === null) {
                    // cold-start: 캐시 없음 → 빈 캔들로 응답
                    return response()->json(['ticker' => $ticker, 'candles' => [], 'source' => 'cold-start']);
                }
                $response = $cached;
            } else {
                // REST 직접조회 경로: 만료 시 동기 갱신 허용
                $response = Cache::remember($cacheKey, 90, function () use ($timeframe) {
                    return $this->getKOSPINightChartData($timeframe);
                });
            }
            
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
            $dataResponse = Cache::remember($cacheKey, 90, function () use ($ticker, $yahooSymbol, $timeframe, $isDaily) {
                $tossData = $this->tossCandleProvider->getChartData($ticker, $timeframe, !$isDaily);
                if ($tossData !== null) {
                    return response()->json($tossData);
                }
                // 토스 실패 시 Yahoo 폴백
                return $this->getYahooChartData($yahooSymbol, $timeframe, !$isDaily);
            });

            // KIS 현재가(국내) — WS/REST 경로 분기:
            //   WS ($allowStale=true):
            //     1) primary 캐시 히트(병렬선조회 8초 TTL) → 즉시 반환
            //     2) 미스 → 폴백(24h) 반환, 동기 fetch 금지(케이던스 유지)
            //   REST ($allowStale=false):
            //     1) primary 캐시 히트 → 즉시 반환
            //     2) 미스 → 동기 fetch 로 갱신 후 primary(8초)에 저장
            //     3) fetch 실패 → 폴백(24h) 반환
            $cacheKeyKis     = "kis_realtime_price_{$ticker}";
            $fallbackKeyKis  = "kis_last_successful_price_{$ticker}";
            $kisPrice = Cache::get($cacheKeyKis);
            if ($kisPrice === null) {
                if ($allowStale) {
                    // WS 경로: 동기 fetch 없이 폴백 사용
                    $kisPrice = Cache::get($fallbackKeyKis);
                } else {
                    // REST 경로: 동기 fetch 로 갱신(라운드3 이전 동작 복원)
                    $fresh = $this->fetchDomesticPriceFromKis($ticker);
                    if ($fresh !== null) {
                        Cache::put($cacheKeyKis, $fresh, 8);
                        $kisPrice = $fresh;
                    } else {
                        $kisPrice = Cache::get($fallbackKeyKis);
                    }
                }
            }

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
                $content['name'] = $this->stockMaster->getName($ticker);
                return response()->json($content);
            }

            // candles 없는 경우에도 name 합류
            $fallbackContent = json_decode($dataResponse->getContent(), true);
            if (is_array($fallbackContent)) {
                $fallbackContent['name'] = $this->stockMaster->getName($ticker);
                return response()->json($fallbackContent);
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
            $tossData = $this->tossCandleProvider->getChartData($ticker, $timeframe, !$isDaily);
            if ($tossData !== null) {
                return response()->json($tossData);
            }
            // 토스 실패 시 Yahoo 폴백
            $yahooFallbackSymbol = preg_match('/(\.KS|\.KQ)$/i', $ticker) ? $ticker : (
                (preg_match('/^\d{4}[0-9A-Z]{2}$/', $ticker) || preg_match('/^\d+$/', $ticker))
                ? $ticker . '.KS' : $ticker
            );
            return $this->getYahooChartData($yahooFallbackSymbol, $timeframe, !$isDaily);
        });

        // 토스 현재가(미국) — Phase 4: KIS→토스+Yahoo 폴백으로 전환
        // 캐시 키는 하위호환 유지: `kis_realtime_price_us_{ticker}` / `kis_last_successful_overseas_price_{ticker}`
        //   WS ($allowStale=true):
        //     1) primary 캐시 히트(토스 배치 8초 TTL) → 즉시 반환
        //     2) 미스 → 폴백(24h) 반환, 동기 fetch 금지(케이던스 유지)
        //   REST ($allowStale=false):
        //     1) primary 캐시 히트 → 즉시 반환
        //     2) 미스 → 토스 단건 조회(→Yahoo 폴백) 후 primary(8초)에 저장
        //     3) 모두 실패 → 폴백(24h) 반환
        $cacheKeyKis      = "kis_realtime_price_us_{$ticker}";
        $fallbackKeyKisUs = "kis_last_successful_overseas_price_{$ticker}";
        $kisPrice         = Cache::get($cacheKeyKis);
        // 8초 TTL 캐시 히트 여부 — 폴백(24h) 사용 시 false 로 분봉 누적 스킵
        $isFreshKisPrice = ($kisPrice !== null);
        if ($kisPrice === null) {
            if ($allowStale) {
                // WS 경로: 동기 fetch 없이 폴백 사용(24h stale)
                $kisPrice = Cache::get($fallbackKeyKisUs);
                // 폴백이므로 $isFreshKisPrice 는 false 유지 → 분봉 누적 스킵
            } else {
                // REST 경로: 토스 단건 조회 (토스 실패 시 Yahoo 폴백 — TossPriceFetcher 내부)
                $fresh = $this->tossPriceFetcher->fetchOverseasSingle($ticker);
                if ($fresh !== null) {
                    // fetchOverseasSingle 내부에서 cacheKey·fallbackKey 모두 저장하므로
                    // 여기서는 $kisPrice 에만 할당 (이중 저장 불필요)
                    $kisPrice        = $fresh;
                    $isFreshKisPrice = true;
                } else {
                    $kisPrice = Cache::get($fallbackKeyKisUs);
                    // fetch 실패·폴백 → $isFreshKisPrice 는 false 유지
                }
            }
        }

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
                    $accumulated1m = $this->accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime, $isFreshKisPrice);

                    // Yahoo 마지막 봉 시각은 초 단위로 비정렬일 수 있다(예: 10:47:09).
                    // 실시간 누적 봉은 분 정렬(예: 10:47:00)이라, 단순히 '> lastYahooTime' 로
                    // 거르면 같은 분의 실시간 봉(:00)이 비정렬 Yahoo 시각(:09)보다 작아 버려진다.
                    // 그러면 그 분 동안 현재가는 움직이는데 차트 마지막 봉은 Yahoo 값에 고정돼
                    // 헤더 현재가 ≠ 봉 close 로 갈리고 봉이 멈칫한다.
                    // → Yahoo 시각을 분 단위로 내림 정렬해 같은 분의 실시간 봉을 보존한다.
                    //   (aggregateCandles 가 동일 버킷을 병합하고 close 는 뒤에 오는 누적값이 이긴다.)
                    $alignedLastYahooTime = $lastYahooTime - ($lastYahooTime % 60);

                    // Filter accumulated candles
                    $filteredAccumulated = array_filter($accumulated1m, function($c) use ($alignedLastYahooTime) {
                        return (int)$c['time'] >= $alignedLastYahooTime;
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
            // 차트 베이스(Toss/Yahoo)를 source 에 반영하되, 현재가의 실제 출처도 정직하게 표기한다.
            // $content['source'] 는 캐시된 차트 데이터가 이미 가지고 있는 베이스 라벨
            // ('Toss (1d)', 'Yahoo Finance (1d)' 등). $kisPrice['provider'] 와 $isFreshKisPrice 로
            // 현재가가 실제로 어디서 왔는지(토스 라이브 / Yahoo 폴백 / 24h 캐시-지연)를 구분한다.
            //   - provider=yahoo            → 'Yahoo 폴백 (현재가)'  (토스 불통 → Yahoo 폴백 중)
            //   - provider=toss & 신선      → 기존대로 차트 베이스 반영 ('Toss' / 'Yahoo + Toss (현재가)')
            //   - 신선 아님(24h stale 캐시) → '… 캐시(지연)' 로 지연 표기
            //   - $kisPrice 자체 없음        → 기존 동작 유지
            $baseSource     = $content['source'] ?? 'Yahoo Finance';
            $priceProvider  = is_array($kisPrice) ? ($kisPrice['provider'] ?? null) : null;
            $isTossBaseline = (strncmp($baseSource, 'Toss', 4) === 0);

            if ($kisPrice === null) {
                // 현재가 없음 — 기존 동작 유지
                $content['source'] = $isTossBaseline ? 'Toss' : 'Yahoo + Toss (현재가)';
            } elseif ($priceProvider === 'yahoo') {
                // 토스 불통 → Yahoo 폴백으로 현재가를 채우는 중임을 명시
                $content['source'] = $isFreshKisPrice ? 'Yahoo 폴백 (현재가)' : 'Yahoo 폴백 캐시(지연)';
            } elseif (!$isFreshKisPrice) {
                // 신선하지 않음 = 24h stale 폴백 캐시 사용 → 지연 표기
                // 저장된 provider 가 있으면 접두로 붙여 출처도 함께 드러낸다(없으면 출처 없이 '캐시(지연)').
                if ($priceProvider === 'toss') {
                    $content['source'] = 'Toss 캐시(지연)';
                } elseif ($priceProvider === 'yahoo') {
                    $content['source'] = 'Yahoo 캐시(지연)';
                } else {
                    $content['source'] = '캐시(지연)';
                }
            } else {
                // provider=toss & 신선 → 기존대로 차트 베이스를 반영
                $content['source'] = $isTossBaseline ? 'Toss' : 'Yahoo + Toss (현재가)';
            }
            $content['is_trading_day'] = ($session !== '장마감');
            $content['name'] = $this->stockMaster->getName($ticker);
            return response()->json($content);
        }

        // candles 없는 경우에도 name 합류
        $fallbackContent = json_decode($dataResponse->getContent(), true);
        if (is_array($fallbackContent)) {
            $fallbackContent['name'] = $this->stockMaster->getName($ticker);
            return response()->json($fallbackContent);
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
            $responseNq = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1d&range=7d', [
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

        // 2. 코스피 지수(^KS11=코스피 종합) 현재가 — Yahoo Finance v8 chart API
        try {
            $responseKs = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/^KS11?interval=1d&range=7d', [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            $dataKs = json_decode($responseKs->getBody()->getContents(), true);
            $kospiPriced = $this->parseYahooFinanceChart($dataKs, '코스피 지수');
            $kospiPriced['source'] = 'Yahoo Finance (^KS11)';
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Yahoo KOSPI (^KS11) Index Fetch Error: " . $e->getMessage());

            $kospiPriced = [
                'name' => '코스피 지수',
                'price' => 0.0,
                'change' => 0.0,
                'change_percent' => 0.0,
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
        // 전일종가: chartPreviousClose 는 range '시작 직전' 값이라 range=2d 면 '이틀 전'이 됨(한 칸 밀림 버그).
        // 따라서 close 배열의 직전 봉(closes[n-2])을 전일종가로 쓴다. 부족할 때만 meta 폴백.
        $prevClose = 0.0;

        if (isset($result['indicators']['quote'][0]['close'])) {
            $closes = array_values(array_filter($result['indicators']['quote'][0]['close'], function ($v) {
                return $v !== null;
            }));
            $n = count($closes);
            if ($n >= 1 && $price == 0.0) {
                $price = end($closes);
            }
            if ($n >= 2) {
                $prevClose = $closes[$n - 2];
            }
        }

        if ($prevClose == 0.0) {
            $prevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? $price;
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
            $prevClose = $candles[0]['close'] ?? $basePrice;
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

                $open  = round((float)$open, 2);
                $high  = round((float)$high, 2);
                $low   = round((float)$low, 2);
                $close = round((float)$close, 2);
                $volume = (int)$volume;

                // ── Yahoo Finance bad-tick 방어 (volume=0 봉 전용) ──────────────────
                // Yahoo는 프리마켓/애프터마켓(volume=0) 봉에 전일 정규장 세션 low/high를
                // 잘못 복사하거나 누적 세션 저가를 그대로 넣는 알려진 버그가 있다.
                //
                // 실측 근거 (MU 1분봉 2d 범위, 2026-06-22):
                //   - volume=0 봉에서 low가 봉 body min(min(open,close)) 대비 1.27%~4.38%
                //     낮게 튀는 bad tick 11건 확인.
                //   - volume>0(정규장) 봉에서는 동일 기준으로 false positive = 0건.
                //   - "같은 값이 반복"되는 원인: Yahoo가 이전 정규장 세션 저가(e.g. 1133.99)를
                //     이후 프리마켓 봉 여러 개의 low에 그대로 복사해 내려줌.
                //     이 값이 aggregateCandles() min() 집계로 3분봉 등 여러 버킷에 전파됨.
                //
                // 대응:
                //   - volume=0 봉에서만 low/high를 body 범위로 클램프.
                //   - volume>0(정규장) 봉은 신뢰하며 절대 건드리지 않는다.
                //   - 임계치 1.25%(0.9875): FP=0, 포착률=100% 검증 완료.
                //     (0.5% 기준은 vol>0 FP 1건 발생, 1.5% 기준은 1.27%짜리 미포착)
                [$low, $high] = $this->applyBadTickClamp($open, $close, $low, $high, $volume);
                // ────────────────────────────────────────────────────────────────────

                $timestamp = $timestamps[$i];
                if ($timeframe === '1d') {
                    $date = new \DateTime("@{$timestamp}");
                    $date->setTimezone(new \DateTimeZone('Asia/Seoul'));
                    $timeVal = $date->format('Y-m-d');
                } else {
                    $timeVal = $timestamp;
                }

                $candles[] = [
                    'time'   => $timeVal,
                    'open'   => $open,
                    'high'   => $high,
                    'low'    => $low,
                    'close'  => $close,
                    'volume' => $volume,
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
                // 전일종가 = 차트의 직전 봉 close (지수·개별 동일).
                // chartPreviousClose 는 조회 범위 시작 직전 값이라 전일종가가 한 칸 밀린다 → 사용 안 함.
                $prevCandle = prev($candles) ?: $latestCandle;
                $prevClose = $prevCandle['close'];
            } else {
                // Yahoo meta 전일종가 사용 (KIS 전일종가 조회 제거 후 Yahoo meta로 통일)
                $metaPrevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0.0;
                $prevClose = ($metaPrevClose > 0) ? $metaPrevClose : ($candles[0]['close'] ?? $current);
            }
            
            $changeAmount = $current - $prevClose;
            $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
            
            $displayName = $this->getStockName($ticker);
            if ($ticker === 'NQ=F') {
                $displayName = '나스닥100 선물';
            } elseif ($ticker === 'KOSPI200' || $ticker === '^KS200' || $ticker === '^KS11') {
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

        // 야간선물(코스피200 선물)의 베이스는 KOSPI200(~1,477 스케일)이어야 한다.
        // Yahoo Finance ^KS200 으로 직행 (KIS '2001' 제거).
        $kospiResponse = $this->getYahooChartData('^KS200', $timeframe);
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
     * iscd 값을 Yahoo Finance 심볼로 매핑한다.
     * '0001' (코스피 종합) → '^KS11', '2001' (코스피200) → '^KS200'.
     * 미지의 iscd 는 안전하게 '^KS11' 로 폴백.
     */
    private function kospiYahooSymbol(string $iscd): string
    {
        if ($iscd === '2001') {
            return '^KS200';  // 코스피200
        }
        return '^KS11';  // '0001'(코스피 종합) 및 기타 → 코스피 종합
    }

    /**
     * 코스피 지수 차트 데이터를 KIS 국내업종 API 로 구성.
     * $iscd: '0001'=코스피 종합(전체, 기본), '2001'=코스피200.
     * 1d=일봉, 그 외=분봉(종가 기반 OHLC 합성 후 집계). 실패·stale 시 Yahoo 폴백.
     *
     * 버그 A 수정 (2026-06-23):
     *   KIS FHPUP02120000 일봉 API 가 rt_cd=0 성공이지만 ~4개월 stale 데이터를 반환하는
     *   현상을 값-기반으로 감지한다. KIS output1.bstp_nmix_prpr(현재지수)와 캔들 최신
     *   close 의 괴리가 10% 초과면 stale 로 판정해 Yahoo 일봉으로 폴백한다.
     *   폴백 심볼: '0001'→'^KS11'(코스피 종합), '2001'→'^KS200'(코스피200).
     *
     * 버그 B 수정 (2026-06-23):
     *   분봉 intervalSeconds 블록에 1m 케이스가 없어 1m 이 3m 으로 집계되던 문제 수정.
     */
    /**
     * 코스피 지수 차트 데이터 — Yahoo Finance 직행 (KIS 코스피 경로 제거).
     * '0001' (코스피 종합) → ^KS11, '2001' (코스피200) → ^KS200.
     *
     * 이 메서드는 KOSPI_NIGHT 합성 등 내부에서만 호출된다.
     * getStockData('KOSPI200') 는 getYahooChartData('^KS11', ...) 를 직접 호출하므로
     * 이 함수를 거치지 않는다.
     */
    public function getKospiIndexData($timeframe, $iscd = '0001')
    {
        $yahooSymbol = $this->kospiYahooSymbol($iscd);
        return $this->getYahooChartData($yahooSymbol, $timeframe);
    }

    /**
     * 국내 종목 현재가 조회 — Phase 3: KIS → 토스 전환.
     *
     * TossPriceFetcher::fetchSingle() 으로 위임한다.
     * 캐시 키 · 폴백 키는 TossPriceFetcher 내부에서 기존과 동일하게 유지:
     *   `kis_realtime_price_{ticker}` (TTL 8s)
     *   `kis_last_successful_price_{ticker}` (TTL 86400s)
     * → getStockData 의 $cacheKeyKis / $fallbackKeyKis 코드 무변경.
     *
     * 미국 종목이 실수로 들어오면 null 반환 (TossPriceFetcher 내부 guard).
     *
     * @param  string  $ticker  앱 내부 심볼
     * @return array{price:float,change_amount:float,change_percent:float}|null
     */
    private function fetchDomesticPriceFromKis($ticker)
    {
        return $this->tossPriceFetcher->fetchSingle($ticker);
    }

    /**
     * 종목명 반환 — TossStockMaster 캐시 우선, 폴백 = 심볼 그대로.
     *
     * Phase 7: 하드코딩 배열·JSON 파일 직접 로드를 제거하고
     * TossStockMaster 에 위임한다. 캐시 TTL 1일이므로 빠름.
     *
     * 지수 심볼(NQ=F·KOSPI200 등)은 호출부에서 별도 처리하므로
     * 이 메서드는 폴백만 수행하면 된다.
     *
     * @param  string $ticker  앱 내부 심볼 (접미사 포함/미포함 모두 가능)
     * @return string
     */
    private function getStockName(string $ticker): string
    {
        return $this->stockMaster->getName($ticker);
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
            // Phase 7: name 컬럼 삭제됨 → symbol LIKE 만 쿼리. name 은 accessor 경유.
            $dbResults = [];
            try {
                $dbStocks = \App\Models\Stock::where('market', 'KR')
                    ->where(function ($q) use ($queryLower) {
                        $q->whereRaw('LOWER(symbol) LIKE ?', ['%' . $queryLower . '%']);
                    })
                    ->limit(20)
                    ->get(['id', 'symbol', 'exchange']);

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

    /**
     * Yahoo Finance bad-tick 클램프 (volume=0 봉 전용).
     *
     * volume=0(프리마켓·애프터마켓) 봉에서 low/high 가 봉 body 대비 1.25% 이상
     * 벗어나면 bad tick 으로 판단해 body 경계로 클램프한다.
     * volume>0(정규장) 봉은 신뢰하며 절대 건드리지 않는다.
     *
     * @param float $open
     * @param float $close
     * @param float $low
     * @param float $high
     * @param int   $volume
     * @return array{float, float}  [$low, $high] — 클램프 적용 후 값
     */
    private function applyBadTickClamp(float $open, float $close, float $low, float $high, int $volume): array
    {
        if ($volume !== 0) {
            return [$low, $high];
        }

        $bodyMin = min($open, $close);
        $bodyMax = max($open, $close);

        $originalLow  = $low;
        $originalHigh = $high;
        $clamped = false;

        // low가 body 최저보다 1.25% 이상 낮으면 bad tick — body 최저로 클램프
        if ($bodyMin > 0 && $low < $bodyMin * 0.9875) {
            $low     = $bodyMin;
            $clamped = true;
        }
        // high가 body 최고보다 1.25% 이상 높으면 bad tick — body 최고로 클램프
        if ($bodyMax > 0 && $high > $bodyMax * 1.0125) {
            $high    = $bodyMax;
            $clamped = true;
        }

        if ($clamped && \Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) {
            \Illuminate\Support\Facades\Log::info('bad-tick clamp applied', [
                'open'        => $open,
                'close'       => $close,
                'low_before'  => $originalLow,
                'low_after'   => $low,
                'high_before' => $originalHigh,
                'high_after'  => $high,
            ]);
        }

        return [$low, $high];
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

        // 주간거래(20:00~03:30 ET)는 다음 거래일로 이어지는 세션 — 주말만 아니면 개장.
        // 경계는 getUsSession()/resolveUsSession() 과 동일하게 330(03:30)으로 맞춘다.
        // 03:30~04:00 는 주간거래 종료~프리마켓 시작 공백 — 봉을 생성하지 않음(isUsMarketTradingToday 로 이관).
        if ($timeVal >= 2000 || $timeVal < 330) {
            return true;
        }

        // 데이 세션(04:00~20:00)은 'NY 오늘'이 거래일일 때만 — 공휴일이면 미개장
        return $this->isUsMarketTradingToday();
    }

    private function getKrMarketSessionInfo($timestamp)
    {
        return $this->sessionService->getKrSession((int)$timestamp);
    }

    private function accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime, bool $isFreshKisPrice = true)
    {
        $cacheKey = "kis_accumulated_us_1m_{$ticker}";
        $accumulated = Cache::get($cacheKey, []);

        $now = time();
        if (!$this->isUsMarketOpen($now)) {
            return $accumulated;
        }

        // KIS 8초 TTL 캐시 미스로 24h 폴백 사용 중 → stale 가격으로 평평봉이 연속 누적되는 것을 방지.
        // 폴백 가격은 실제 거래가 아니라 KIS 통신 단절 상태를 반영하므로, 새 분봉 누적을 스킵하고
        // 기존 accumulated 를 그대로 반환한다. 이미 시작된 현재 분봉의 close 도 갱신하지 않는다.
        if (!$isFreshKisPrice) {
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
