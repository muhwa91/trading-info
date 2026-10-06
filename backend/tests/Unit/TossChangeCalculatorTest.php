<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketSessionService;
use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossSymbolMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;

/**
 * TossChangeCalculator 단위 테스트.
 *
 * 검증 대상:
 *   - calculate(): lastPrice · prevClose 로 등락 계산 정확도
 *   - getPrevClose(): 캐시 hit → API 호출 없음
 *   - getPrevClose(): 캐시 miss → /candles 호출 → 캐시 저장
 *   - /candles 응답이 1봉 이하면 null 반환
 *   - 빈 응답 graceful → change=0
 *   - 봉 정렬 (시간 역순 응답도 정상 처리)
 */
// 공용 clientMock 을 일부 테스트만 expects 로 검증 — setUp 공용 대역이라 클래스 단위로 허용
#[AllowMockObjectsWithoutExpectations]
class TossChangeCalculatorTest extends TestCase
{
    private $clientMock;

    private $sessionMock;

    private TossChangeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->sessionMock = $this->createStub(MarketSessionService::class);
        $this->calculator = new TossChangeCalculator($this->clientMock, new TossSymbolMapper, $this->sessionMock);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();  // 시간 고정 해제 (날짜 경계 테스트 격리)
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    // KR 기준가 = Yahoo 정규장 종가 — 테스트 대역(hermetic)
    //   2026-07-17 계약: 기준가(D) == Yahoo {코드}.KS 일봉 종가(D−1).
    //   Client 를 주입하지 않으면 프로덕션이 진짜 Yahoo 를 때린다(실측: 005930 → 278000.0 을
    //   실제로 물어왔다). KR 경로를 타는 테스트는 반드시 아래 대역을 4번째 인자로 주입한다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Yahoo v8 chart 일봉 대역 Guzzle 클라이언트.
     *
     * MockHandler(고정 큐) 대신 콜러블 핸들러를 쓴다 — 스윕은 호출 수가 수백 회라 큐를 못 쓰고,
     * 프로덕션이 .KS → .KQ 순으로 재시도해 호출 수가 가변이기 때문. 요청 URI 와 무관하게 같은
     * 시계열을 주므로 .KS 에서 바로 히트한다(.KQ 는 호출되지 않음 — 코스피 종목 표본과 일치).
     *
     * 빈 맵([])을 주면 '해당일 봉 없음' → 프로덕션의 Yahoo 실패 폴백 경로가 열린다.
     *
     * @param  array<string,float>  $closesByDate  ['Y-m-d'(KST) => 정규장 종가]
     */
    private function krYahooClient(array $closesByDate): Client
    {
        $handler = function () use ($closesByDate): FulfilledPromise {
            $timestamps = [];
            $closes = [];
            foreach ($closesByDate as $date => $close) {
                // 프로덕션은 timestamp 를 KST 날짜로 환산해 매칭한다 → 그 날 장중 시각이면 무엇이든 무방.
                $timestamps[] = Carbon::parse("{$date} 15:30", 'Asia/Seoul')->getTimestamp();
                $closes[] = $close;
            }

            $body = json_encode(['chart' => ['result' => [[
                'timestamp' => $timestamps,
                'indicators' => ['quote' => [['close' => $closes]]],
            ]]]]);

            return new FulfilledPromise(new Response(200, [], $body));
        };

        return new Client(['handler' => $handler]);
    }

    /**
     * $this->calculator 를 Yahoo 대역이 주입된 인스턴스로 교체한다(KR 경로 테스트용 1줄 준비).
     *
     * @param  array<string,float>  $closesByDate  ['Y-m-d'(KST) => 정규장 종가] · [] = Yahoo 실패 재현
     */
    private function useKrYahoo(array $closesByDate): void
    {
        $this->calculator = new TossChangeCalculator(
            $this->clientMock,
            new TossSymbolMapper,
            $this->sessionMock,
            $this->krYahooClient($closesByDate)
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // calculate() — 등락 계산
    // ──────────────────────────────────────────────────────────────────

    /**
     * KR 상승: 기준가 = Yahoo 정규장 종가(6/23) — 토스 1d 봉 종가(70,500)가 아니다.
     * 토스 종가를 일부러 다른 값으로 둬 '어느 소스가 이겼는지'가 결과에 드러나게 한다.
     */
    #[Test]
    public function test_calculate_positive_change(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정 (분기 1: candles[1]=6/23 이 기준 거래일)
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // Yahoo 6/23 정규장 종가 70,000 (토스 1d 봉 종가 70,500 은 시간외 드리프트 오염값)
        $this->useKrYahoo(['2026-06-23' => 70000.0]);

        // 실측 응답 구조: result.candles 배열, 최신(index 0) → 오래된(index 1)
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '72000', 'lowPrice' => '70000', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'openPrice' => '70000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                    'nextBefore' => '2026-06-22T00:00:00.000+09:00',
                ],
            ]);

        $result = $this->calculator->calculate('005930', 71500.0);

        // 기준가 = Yahoo 70,000 (토스 70,500 아님) → change = 71,500 − 70,000 = 1,500
        $this->assertSame(70000.0, $result['prev_close']);
        $this->assertSame(1500.0, $result['change_amount']);
        $this->assertGreaterThan(0, $result['change_percent']);
    }

    /**
     * KR 하락: 기준가 = Yahoo 정규장 종가(6/23) → 부호가 토스 종가 기준과 갈린다.
     * 토스 1d(71,000) 기준이면 −1,000, Yahoo(71,500) 기준이면 −1,500 — 오염 소스 복귀 시 여기서 잡힌다.
     */
    #[Test]
    public function test_calculate_negative_change(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        $this->useKrYahoo(['2026-06-23' => 71500.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '70500', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '71000', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $result = $this->calculator->calculate('005930', 70000.0);

        // 기준가 = Yahoo 71,500 (토스 71,000 아님) → change = 70,000 − 71,500 = −1,500
        $this->assertSame(71500.0, $result['prev_close']);
        $this->assertSame(-1500.0, $result['change_amount']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    #[Test]
    public function test_calculate_no_prev_close_returns_zero(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]);  // 빈 응답

        $result = $this->calculator->calculate('005930', 71000.0);

        $this->assertSame(0.0, $result['change_amount']);
        $this->assertSame(0.0, $result['change_percent']);
        $this->assertNull($result['prev_close']);
    }

    // ──────────────────────────────────────────────────────────────────
    // getPrevClose() — 캐시 hit/miss
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_get_prev_close_cache_hit_no_api_call(): void
    {
        Cache::put('toss_prev_close_005930', 70500.0, 3600);

        $this->clientMock->expects($this->never())->method('get');

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertSame(70500.0, $prevClose);
    }

    /**
     * 캐시 miss → /candles + Yahoo 호출 → 캐시 저장. 두 번째 호출은 캐시 히트라 두 경로 모두 미호출.
     * (호출 횟수를 세어 '캐시가 실제로 먹었는지'를 못박는다 — 같은 값 반환만으론 증명되지 않는다.)
     */
    #[Test]
    public function test_get_prev_close_cache_miss_calls_api_and_caches(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정 (KR 오늘봉 존재 = 분기 1)
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // Yahoo 6/23 정규장 종가 71,000 (토스 1d 봉 종가 70,500 과 다른 값 → 소스 판별 가능)
        $yahooCalls = 0;
        $this->calculator = new TossChangeCalculator(
            $this->clientMock,
            new TossSymbolMapper,
            $this->sessionMock,
            new Client(['handler' => function () use (&$yahooCalls): FulfilledPromise {
                $yahooCalls++;
                $body = json_encode(['chart' => ['result' => [[
                    'timestamp' => [Carbon::parse('2026-06-23 15:30', 'Asia/Seoul')->getTimestamp()],
                    'indicators' => ['quote' => [['close' => [71000.0]]]],
                ]]]]);

                return new FulfilledPromise(new Response(200, [], $body));
            }])
        );

        $candleCalls = 0;
        $this->clientMock
            ->method('get')
            ->willReturnCallback(function () use (&$candleCalls) {
                $candleCalls++;

                return ['result' => ['candles' => [
                    ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '70800', 'volume' => '200', 'currency' => 'KRW'],
                    ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                ]]];
            });

        // 기준가 = Yahoo 6/23 종가 71,000 (candles[1]=70,500 아님)
        $this->assertSame(71000.0, $this->calculator->getPrevClose('005930'));
        $this->assertSame(1, $candleCalls, 'miss 1회 → /candles 1회');
        $this->assertSame(1, $yahooCalls, 'miss 1회 → Yahoo 1회');

        // 두 번째 호출은 캐시 히트 → 두 경로 모두 재호출 없음
        $this->assertSame(71000.0, $this->calculator->getPrevClose('005930'));
        $this->assertSame(1, $candleCalls, '캐시 히트인데 /candles 를 다시 호출했다');
        $this->assertSame(1, $yahooCalls, '캐시 히트인데 Yahoo 를 다시 호출했다');
    }

    // ──────────────────────────────────────────────────────────────────
    // 국내 기준가 = Yahoo 정규장 종가 (2026-07-17 드리프트 오염 수정)
    //   계약: 기준가(D) == Yahoo {코드}.KS 일봉 종가(D−1).
    //   토스 1d 봉 종가는 시간외 체결을 따라 재집계된 오염값이라 기준가로 쓸 수 없다
    //   (실측 000660 7/15: 정규장 2,082,000 vs 토스 1d봉 2,022,000 = −2.88%. 7/14 는 +1.46% 로 부호 반전).
    //   봉 선택 분기(1·2·3)가 고른 '기준 거래일'은 그대로 두고 close 만 Yahoo 값으로 교체된다.
    //
    //   삭제분(옛 price-limits 계약): 기준가를 (상한+하한)/2 로 잡던 경로와 그 롤오버·ε 가드
    //   (ROLLOVER_EPSILON·isPriceLimitRolledOver·fetchKrReferencePrice·PRICE_LIMITS_ENDPOINT)는
    //   프로덕션에서 제거됐다 — 기준가 소스가 Yahoo 하나가 되어 롤오버 노출 자체가 소멸했다.
    //   그 계약을 박아둔 테스트 9건은 지킬 자산이 없어 삭제하고, 고유 자산이 있는 2건만 아래로 옮겼다:
    //     · 기준가 소스 실패 시 candles 폴백(NULL 방지)  → KrYahooFails_FallsBackToCandleClose
    //     · KR 은 US 롤포워드에 무영향                   → KrSymbol_UnaffectedByUsRollForward(하단 US 섹션)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Yahoo 실패(해당일 봉 없음) → 토스 candles 기반 기준가로 graceful 폴백(캐시 기아·NULL 방지).
     * 옛 'price-limits 빈 응답 → candles 폴백' 테스트의 자산을 새 계약의 실패 소스로 옮긴 것.
     */
    #[Test]
    public function test_get_prev_close_kr_yahoo_fails_falls_back_to_candle_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->useKrYahoo([]);  // 빈 시계열 = .KS·.KQ 모두 해당일 봉 없음 → Yahoo 실패

        // 최신 봉 = 7/14(어제) → 오늘(7/15) 봉 없음 + 정규장 = 라이브 분기 2 → candles[0]
        $this->clientMock->method('get')->willReturn(['result' => ['candles' => [
            ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '71000', 'currency' => 'KRW'],
            ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '70500', 'currency' => 'KRW'],
        ]]]);

        // Yahoo 실패해도 NULL 이 아니라 candles[0](7/14) 종가로 폴백
        $this->assertSame(71000.0, $this->calculator->getPrevClose('005930'));
    }

    /**
     * Yahoo 실패분 TTL 은 짧게(120초) — 드리프트 오염된 폴백값을 장TTL 로 박으면 하루 종일 고착된다.
     * 정상 경로(자정·개장 TTL)와 갈라지는 값이라 리터럴로 못박는다.
     */
    #[Test]
    public function test_fetch_and_cache_kr_yahoo_failed_uses_short_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->useKrYahoo([]);  // Yahoo 실패 → 폴백값 캐싱

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock->method('get')->willReturn(['result' => ['candles' => [
            ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '71000', 'currency' => 'KRW'],
            ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '70500', 'currency' => 'KRW'],
        ]]]);

        $this->calculator->getPrevClose('005930');

        // 10:00 KST 정상분이면 KST 자정까지 50400초 — Yahoo 실패분은 120초여야 한다(자가치유)
        $this->assertSame(120, $capturedTtl, 'Yahoo 실패 폴백값은 짧은 TTL(120s)로만 캐싱해야 한다');
    }

    #[Test]
    public function test_get_prev_close_only_one_candle_returns_null(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertNull($prevClose);
    }

    /**
     * 봉이 역순(오래된 것 먼저)으로 와도 정렬 후 '기준 거래일'을 바로 고른다.
     *
     * 새 계약에선 정렬 결과가 close 가 아니라 '어느 날짜로 Yahoo 를 조회할지'를 정한다 →
     * 정렬이 깨지면 6/24(오늘) 종가를 기준가로 잡아 0% 로 뭉개진다. Yahoo 를 날짜별로 다른 값으로
     * 채워 어느 날짜를 골랐는지가 반환값에 드러나게 한다.
     *
     * (옛 픽스처는 이름과 달리 최신 먼저로 정렬돼 있어 정렬 로직을 실제로 태우지 못했다 → 진짜 역순으로 교정.)
     */
    #[Test]
    public function test_get_prev_close_reverse_order_candles_correctly_picks_oldest(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // 6/23 = 기준 거래일(정답 70,000) · 6/24 = 오늘(잘못 고르면 71,200)
        $this->useKrYahoo(['2026-06-23' => 70000.0, '2026-06-24' => 71200.0]);

        // 역순 응답: index 0 = 오래된 봉(6/23), index 1 = 최신 봉(6/24) → 정렬로 바로잡아야 한다
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // 정렬 후 오늘봉(6/24) 존재 → 기준 거래일 = 6/23 → Yahoo 6/23 종가 70,000
        $this->assertSame(70000.0, $prevClose);
    }

    #[Test]
    public function test_get_prev_close_empty_api_response_returns_null(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]);

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertNull($prevClose);
    }

    // ──────────────────────────────────────────────────────────────────
    // 등락률 계산 정확도
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_calculate_change_percent_precision(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        // 현재가 71500, prevClose 71000 → change_percent = 500/71000*100 ≈ 0.7042
        $result = $this->calculator->calculate('005930', 71500.0);

        $expected = round((71500.0 - 71000.0) / 71000.0 * 100.0, 4);
        $this->assertSame($expected, $result['change_percent']);
    }

    #[Test]
    public function test_calculate_zero_change_when_current_equals_close(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        $result = $this->calculator->calculate('005930', 71000.0);

        $this->assertSame(0.0, $result['change_amount']);
        $this->assertSame(0.0, $result['change_percent']);
    }

    // ──────────────────────────────────────────────────────────────────
    // "오늘 진행중 봉" 판별 → prevClose 선택 (부호 반전 버그 재발 방지)
    // ──────────────────────────────────────────────────────────────────

    /**
     * 미국 프리마켓~개장 직후: 토스에 오늘 일봉이 아직 없어 [전일, 전전일] 이 온다.
     * 최신 봉(index 0 = 전일)이 "오늘 봉"이 아니므로 prevClose = index 0(전일).
     *
     * 실제 버그(MU 7/10): 991.64(7/9)/948.80(7/8) 인데 무조건 index 1(948.80)을
     * 기준가로 잡아 등락 부호가 반전됐다. 이 케이스가 핵심 재발 방지.
     */
    #[Test]
    public function test_get_prev_close_us_no_today_bar_uses_latest_bar(): void
    {
        // NY 기준 오늘 = 2026-07-10 프리마켓(08:00 ET). 최신 봉은 7/9 (오늘 봉 없음).
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        // 미국 프리마켓 = 라이브 세션(lastPrice 진행) → candles[0](어제 종가) 기준 유지.
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // 오늘(7/10) 봉이 없으므로 최신 봉(7/9 = 991.64)이 prevClose
        $this->assertSame(991.64, $prevClose);
    }

    /**
     * 위 케이스의 계산 결과까지 — 현재가 < 991.64 이면 반드시 하락(음수) 이어야 한다.
     * 옛 로직(948.80 기준)이면 상승으로 반전됐을 값.
     */
    #[Test]
    public function test_calculate_us_no_today_bar_sign_not_flipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        // 현재가 969.03 (991.64 대비 약 -2.28%) — 옛 로직이면 948.80 기준 +2.13% 로 반전
        $result = $this->calculator->calculate('MU', 969.03);

        $this->assertSame(991.64, $result['prev_close']);
        $this->assertLessThan(0, $result['change_amount']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    /**
     * 미국 장중/마감 후: 오늘 봉이 존재해 [당일, 전일] 이 온다.
     * 최신 봉(index 0)이 "오늘 봉"이므로 prevClose = index 1(전일).
     */
    #[Test]
    public function test_get_prev_close_us_today_bar_present_uses_prev_bar(): void
    {
        // NY 기준 오늘 = 2026-07-10 장중(11:00 ET). 최신 봉이 7/10 = 오늘 봉.
        Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00', 'America/New_York'));

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '150', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // 오늘(7/10) 봉이 진행중 → 전일 봉(7/9 = 991.64)이 prevClose
        $this->assertSame(991.64, $prevClose);
    }

    /**
     * 국내 개장 전(장마감): 오늘 봉이 아직 없어 [전일, 전전일] 이 온다.
     * lastPrice 가 어제 종가에 고정된 구간이므로 candles[0](어제 종가)을 기준가로 쓰면 0.00% 로 초기화된다.
     * → 장마감이면 candles[1](전전일 종가) 기준 = "어제 하루 등락"이 유지되어야 한다(토스 앱 동일).
     */
    #[Test]
    public function test_get_prev_close_kr_pre_open_uses_prev_prev_bar_keeps_yesterday_change(): void
    {
        // KST 기준 오늘 = 2026-07-10 장전(08:00 KST, 정규장 아님). 최신 봉은 7/9.
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');

        // 기준 거래일 = 7/8(전전일) → Yahoo 70,000 (토스 1d 봉 70,500 은 오염값)
        $this->useKrYahoo(['2026-07-08' => 70000.0, '2026-07-09' => 71000.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        // 장마감(분기 3) → 기준 거래일 7/8 → Yahoo 종가 70,000 (candles[1]=70,500 아님)
        $prevClose = $this->calculator->getPrevClose('005930');
        $this->assertSame(70000.0, $prevClose);

        // 현재가 = 어제(7/9) 종가 71,000 에 고정 → 전전일 대비 = 어제 하루 등락(0% 초기화 아님)
        $result = $this->calculator->calculate('005930', 71000.0);
        $this->assertSame(1000.0, $result['change_amount']);
        $this->assertGreaterThan(0, $result['change_percent']);
    }

    /**
     * 국내 정규장 중인데 오늘 봉이 아직 안 생긴 순간(개장 직후) → candles[0](어제 종가) 기준 = 오늘 등락.
     */
    #[Test]
    public function test_get_prev_close_kr_regular_session_no_today_bar_uses_latest_bar(): void
    {
        // KST 09:05 정규장, 최신 봉은 아직 7/9(오늘 봉 미생성).
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:05:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        // 기준 거래일 = 7/9(어제) → Yahoo 70,800 (토스 1d 봉 71,000 은 오염값)
        $this->useKrYahoo(['2026-07-08' => 70000.0, '2026-07-09' => 70800.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        // 정규장 진행 중(분기 2) → 기준 거래일 7/9 → Yahoo 종가 70,800 (candles[0]=71,000 아님)
        $this->assertSame(70800.0, $this->calculator->getPrevClose('005930'));
    }

    /**
     * 미국 주말/휴장(장마감): 오늘 봉 없음 → candles[1](전전일) 기준 = 직전 거래일 하루 등락 유지.
     * 옛 로직(candles[0])이면 현재가=금요일 종가와 같아 0.00% 로 초기화됐다.
     */
    #[Test]
    public function test_get_prev_close_us_market_closed_uses_prev_prev_bar(): void
    {
        // NY 기준 토요일(2026-07-11) — 장마감. 최신 봉은 금(7/10).
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        // 장마감 → 전전일(7/9=991.64) 기준 → 현재가=금 종가 985 대비 = 금요일 하루 등락(음수)
        $this->assertSame(991.64, $this->calculator->getPrevClose('MU'));
        $result = $this->calculator->calculate('MU', 985.00);
        $this->assertLessThan(0, $result['change_amount']);
    }

    /**
     * 엣지: currency 누락 → KRW 기본값(Asia/Seoul) 으로 '오늘' 판별해야 한다.
     * 오늘 봉이 존재하면 전일 봉을 prevClose 로 선택.
     */
    #[Test]
    public function test_get_prev_close_currency_missing_defaults_to_krw_timezone(): void
    {
        // KST 기준 오늘 = 2026-07-10 장중(10:00 KST). 최신 봉이 7/10 = 오늘 봉.
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Seoul'));

        // 기준 거래일 = 7/9(전일) → Yahoo 70,800 (토스 1d 봉 71,000 은 오염값)
        $this->useKrYahoo(['2026-07-09' => 70800.0, '2026-07-10' => 71500.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000+09:00', 'closePrice' => '71500', 'volume' => '150'],
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // currency 없음 → KRW/Asia/Seoul 기본 → 오늘(7/10) 봉 진행중 → 기준 거래일 7/9 → Yahoo 70,800
        $this->assertSame(70800.0, $prevClose);
    }

    /**
     * 엣지: US 종목 + currency 누락 + 프리마켓 → 심볼로 시장 판별 폴백(NY TZ) → prevClose=전일.
     * currency 결측 시 서울TZ 로 폴백하면 오늘봉 판별이 어긋나 부호반전을 재발시킬 수 있어,
     * TossSymbolMapper::market() 로 US 를 판별해 America/New_York 로 날짜 비교해야 한다.
     */
    #[Test]
    public function test_get_prev_close_us_currency_missing_falls_back_to_symbol_market(): void
    {
        // NY 기준 오늘 = 2026-07-10 프리마켓(08:00 ET). 최신 봉은 7/9 (오늘 봉 없음).
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // currency 누락이어도 심볼(MU=US)로 NY TZ 판별 → 오늘(7/10) 봉 없음 → 전일(7/9 = 991.64)
        $this->assertSame(991.64, $prevClose);
    }

    // ──────────────────────────────────────────────────────────────────
    // 전일종가 캐시 TTL — KR 분기 (US 는 아래 '불변식 스윕' 섹션이 담당)
    //   KR: 'KST 자정' 만료(현행 유지). US 분기가 국내로 새지 않았는지만 여기서 가드한다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * KR 종목 TTL 은 여전히 KST 자정 만료인지 (US 분기가 국내로 새지 않았는지 회귀 가드).
     * secondsUntilKstMidnight() 는 Carbon::now('Asia/Seoul') 기반 → setTestNow 로 고정 가능.
     */
    #[Test]
    public function test_fetch_and_cache_kr_ttl_still_expires_at_kst_midnight(): void
    {
        // KST 10:00 고정 → 다음 자정(11일 00:00)까지 14시간 = 50400초
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Seoul'));

        // Yahoo 성공 경로여야 자정 TTL 이 나온다(실패하면 120초 분기) → 대역 주입 필수
        $this->useKrYahoo(['2026-07-09' => 70500.0]);

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('005930');

        // 10:00 → 다음 KST 자정까지 14h = 50400초 (KST 자정 만료, 16:05 ET 아님)
        $this->assertSame(50400, $capturedTtl, 'KR TTL 은 여전히 KST 자정 만료여야 한다');
    }

    // ──────────────────────────────────────────────────────────────────
    // 3번 분기(장마감 기준가) TTL — '다음 개장 시각까지'만 유효
    //   개장 순간 기준가가 어제 종가로 바뀌어야 하므로, 장마감 캐시가 개장 이후까지 살아남으면
    //   전전일 기준으로 stale → 부호반전(H-3 교훈). KR=다음 09:00 KST · US=다음 09:30 ET.
    // ──────────────────────────────────────────────────────────────────

    /**
     * KR 개장 전(장마감) 기준가 TTL 은 다음 09:00 KST 만료여야 한다(자정 아님).
     */
    #[Test]
    public function test_fetch_and_cache_kr_closed_ttl_expires_at_next_kr_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');

        // Yahoo 성공 경로여야 개장 TTL 이 나온다(실패하면 120초 분기) → 대역 주입 필수
        $this->useKrYahoo(['2026-07-08' => 70500.0]);

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('005930');

        // 16:00 KST → 다음 09:00 KST = 17h = 61200초 (KST 자정 TTL 28800 과 다르다 = 장마감 분기가
        // 1번 분기로 새지 않았다는 가드). 리터럴 — 프로덕션 공식을 재현하지 않는다.
        $this->assertSame(61200, $capturedTtl, 'KR 장마감 TTL 은 다음 09:00 KST(17h) 만료여야 한다');
    }

    /**
     * US 휴장(장마감) 기준가 TTL 도 '다음 경계'까지 — 토(7/11) 12:00 ET → 다음 경계 16:00 ET = 4h.
     * 장마감이라고 개장(09:30)까지 통으로 캐싱하면 그 사이 경계를 넘겨 stale 이 된다.
     */
    #[Test]
    public function test_fetch_and_cache_us_closed_ttl_stops_at_next_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'America/New_York'));  // 토요일 장마감
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('MU');

        // 12:00 ET → 다음 경계 16:00 ET = 4h. (다음 09:30 개장 기준이면 21.5h = 77400)
        $this->assertSame(14400, $capturedTtl, 'US 장마감 TTL 도 다음 경계(16:00 ET)에서 끊겨야 한다');
    }

    // ──────────────────────────────────────────────────────────────────
    // getKrRegularClose() — KR 정규장 마감 후 현재가 = 오늘 정규장 종가 고정
    //   시간외 lastPrice 무시, 토스 앱 종가 표시와 정합. 정규장 중·휴장·개장 전·US 는 미적용.
    //
    //   종가 = 마감(15:30) 직후 평탄 구간(15:31~15:40) 1m 분봉 close 의 최빈값.
    //   '지난 1분봉' close 는 확정 과거값이라 시간외 드리프트에도 불변 → 재시작·콜드스타트 재추출도 동일.
    //   15:30 이하(연속 체결 마지막가 ≠ 종가) · 15:41~(시간외단일가 드리프트)는 제외한다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * 국내 1m 분봉 응답 헬퍼: [HHMM(KST) => closePrice] 를 오늘(givenDate) 봉으로 만든다.
     *
     * @param  array<int,array{0:string,1:string}>  $bars  ['1531','2082000'] 형태 목록
     */
    private function krMinuteCandles(string $date, array $bars): array
    {
        $candles = [];
        foreach ($bars as [$hhmm, $close]) {
            $h = substr($hhmm, 0, 2);
            $m = substr($hhmm, 2, 2);
            $candles[] = [
                'timestamp' => "{$date}T{$h}:{$m}:00.000+09:00",
                'openPrice' => $close,
                'highPrice' => $close,
                'lowPrice' => $close,
                'closePrice' => $close,
                'volume' => '10',
                'currency' => 'KRW',
            ];
        }

        return ['result' => ['candles' => $candles]];
    }

    /**
     * KR 장마감(오늘 거래일) + 15:31~15:40 plateau → 정규장 종가 반환.
     * 실측(000660): 15:31~15:40 close = 2,082,000(마감 동시호가 체결가). 15:30 연속(2,093,000)·
     * 15:41~ 시간외단일가 드리프트(2,078,000→…)는 제외되어 시간외 lastPrice 대신 2,082,000 고정.
     */
    #[Test]
    public function test_get_kr_regular_close_minute_plateau_returns000660_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));  // 15:30 이후
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1528', '2095000'],  // 연속(≤15:30) — 제외
            ['1530', '2093000'],  // 연속 마지막가 — 제외
            ['1531', '2082000'],  // ← plateau
            ['1535', '2082000'],  // ← plateau
            ['1540', '2082000'],  // ← plateau(경계 포함)
            ['1541', '2078000'],  // 시간외단일가 드리프트 — 제외
            ['1550', '2070000'],  // 드리프트 — 제외
        ]));

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * 삼성전자(005930) 동일 패턴: 15:31~15:40 close = 279,500 → 종가 정확 추출.
     */
    #[Test]
    public function test_get_kr_regular_close_minute_plateau_returns005930_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:10:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1530', '279000'],   // 연속 — 제외
            ['1531', '279500'],   // plateau
            ['1535', '279500'],   // plateau
            ['1540', '279500'],   // plateau
            ['1541', '279200'],   // 드리프트 — 제외
        ]));

        $this->assertSame(279500.0, $this->calculator->getKrRegularClose('005930'));
    }

    /**
     * 평탄 구간에 이상 봉이 섞이고 시간외 드리프트 봉이 함께 와도 최빈값으로 종가 정확 추출.
     * (15:33 에 튄 2,085,000 한 봉이 있어도 mode = 2,082,000, 15:41~ 드리프트는 창 밖 제외.)
     */
    #[Test]
    public function test_get_kr_regular_close_drift_and_outlier_mixed_still_picks_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00', 'Asia/Seoul'));  // 저녁 콜드스타트 상황
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1530', '2093000'],  // 연속 — 제외
            ['1531', '2082000'],  // plateau
            ['1532', '2082000'],  // plateau
            ['1533', '2085000'],  // 창 안 이상 봉(단발) — mode 로 무시
            ['1540', '2082000'],  // plateau
            ['1541', '2078000'],  // 드리프트 — 제외
            ['1545', '2070000'],  // 드리프트 — 제외
            ['1730', '2065000'],  // 늦은 시간외 — 제외
        ]));

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * 폴백: 1m plateau 판정 불가(분봉 취득 실패) → 1d 오늘봉 close 로 graceful 폴백(NULL 방지).
     */
    #[Test]
    public function test_get_kr_regular_close_minute_fails_falls_back_to_daily_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturnCallback(function (string $path, array $query) {
            if (($query['interval'] ?? null) === '1m') {
                return [];  // 분봉 취득 실패 → 폴백 유도
            }

            // 1d 폴백: 오늘봉(7/15) close 존재
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-15T00:00:00.000+09:00', 'closePrice' => '2081000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
            ]]];
        });

        $this->assertSame(2081000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * KR 정규장 중 → null(라이브 lastPrice 유지). /candles 호출도 없어야 한다.
     */
    #[Test]
    public function test_get_kr_regular_close_regular_session_returns_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * KR 장마감 + 휴장일(거래일 아님) → null(현행 전일 마감 표시 유지). /candles 미호출.
     */
    #[Test]
    public function test_get_kr_regular_close_non_trading_day_returns_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 16:00:00', 'Asia/Seoul'));  // 토요일 가정
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(false);
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * KR 장마감 + 개장 전(오늘 15:31+ 봉 미생성, 최신 봉 = 어제) → null(현행 유지, 종가 고정 미적용).
     * 1m plateau 공집합 + 1d 폴백도 오늘봉 아님 → 최종 null.
     */
    #[Test]
    public function test_get_kr_regular_close_pre_open_no_today_bar_returns_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00:00', 'Asia/Seoul'));  // 개장 전
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        // 어제(7/14) 봉만 존재 — 1m·1d 어느 경로도 오늘(7/15) plateau/오늘봉 없음
        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => [
                ['timestamp' => '2026-07-14T15:31:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-14T15:35:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
            ]],
        ]);

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * US 종목 → null(미국은 프리/애프터 시간외를 그대로 표시 — 종가 고정 미적용).
     */
    #[Test]
    public function test_get_kr_regular_close_us_symbol_returns_null(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('TSLA'));
    }

    /**
     * 장마감·거래일에 종가 취득이 null(1m plateau + 1d 폴백 둘 다 빈응답=일시 실패)이면
     * sentinel(0) 을 장TTL 이 아니라 짧은 TTL(120초)로 저장해야 한다.
     * 장TTL 로 0 을 고착시키면 API 회복 후에도 다음 개장까지 종가 고정을 못 하는 자가치유 실패가 난다.
     */
    #[Test]
    public function test_get_kr_regular_close_fetch_fails_caches_sentinel_with_short_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        $this->clientMock->method('get')->willReturn([]);  // 1m·1d 둘 다 빈응답 → close=null(일시 실패)

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->assertNull($this->calculator->getKrRegularClose('000660'));

        // 장TTL(다음 09:00 KST, 최소 수시간) 이 아니라 단TTL 120초
        $this->assertSame(120, $capturedTtl, '취득 실패 sentinel 은 짧은 TTL(120초)로 저장돼 곧 재시도되어야 한다');
    }

    /**
     * 실값(분봉 plateau 성공)일 때는 다음 09:00 KST 장TTL 로 저장(드리프트 없는 확정 종가 → 저녁 내내 유지).
     */
    #[Test]
    public function test_get_kr_regular_close_fetch_succeeds_caches_with_long_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1531', '2082000'],
            ['1535', '2082000'],
            ['1540', '2082000'],
        ]));

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));

        // 16:00 KST → 다음 09:00 KST = 17h = 61200초. 리터럴로 못박는다(공식 재현 금지 — 재현하면
        // 프로덕션이 틀려도 테스트가 같이 틀려 원리적으로 실패하지 않는다). 단TTL(120)이 아님도 함께 가드.
        $this->assertSame(61200, $capturedTtl, '실값은 다음 09:00 KST 장TTL(17h) 로 저장돼야 한다');
    }

    /**
     * 캐시 hit → /candles 미호출(장마감·거래일 게이트 통과 후 캐시 우선). 재시작 시 동일값 재사용 근거.
     */
    #[Test]
    public function test_get_kr_regular_close_cache_hit_no_api_call(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        Cache::put('toss_kr_regclose_000660', 2082000.0, 3600);

        $this->clientMock->expects($this->never())->method('get');

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }

    // ──────────────────────────────────────────────────────────────────
    // US 시간외(애프터마켓) 기준가 롤포워드 (2026-07-15 버그수정 회귀 가드)
    //   정규장 마감 후엔 오늘 정규장 봉이 이미 완결(isTodayBar=true)이라 candles[1](어제 종가)이
    //   stale 기준가가 된다. 현재가는 시간외가 → 기준가를 '오늘 정규장 종가'(yahoo_regular_close_{ticker})로
    //   전진시켜야 정합. 정규장 중·프리마켓·KR 은 미발동(불변).
    //
    //   US 판정: candle currency='USD' → isUsMarket=true (mapper 불필요).
    //   readUsRegularClose 는 yahoo_regular_close_{tossSymbol} → kis_...regular_close → null 순.
    // ──────────────────────────────────────────────────────────────────

    /**
     * US 오늘봉(정규장 완결) + 어제봉 2봉 응답 헬퍼. currency=USD 로 isUsMarket 유도.
     *
     * @param  string  $today  오늘(정규장 완결) 종가
     * @param  string  $prev  직전 candle(candles[1]) 종가 = stale 후보
     * @param  string  $todayDate  오늘 NY 날짜
     * @param  string  $prevDate  직전 candle NY 날짜
     */
    private function usTodayBarCandles(string $today, string $prev, string $todayDate = '2026-07-14', string $prevDate = '2026-07-13'): array
    {
        return ['result' => ['candles' => [
            ['timestamp' => "{$todayDate}T00:00:00.000-04:00", 'closePrice' => $today, 'volume' => '200', 'currency' => 'USD'],
            ['timestamp' => "{$prevDate}T00:00:00.000-04:00", 'closePrice' => $prev, 'volume' => '100', 'currency' => 'USD'],
        ]]];
    }

    /**
     * MU 애프터마켓: isTodayBar=true(오늘 정규장봉 완결) + yahoo_regular_close 존재
     * → 기준가 = yahoo_regular_close(983.12), candles[1](937, 7/13 stale) 아님.
     * 실측: 기준가 937 이면 현재가 976.5 가 +4.22% 로 오표기 → 정답 983.12 대비 -0.67%.
     */
    #[Test]
    public function test_get_prev_close_us_after_market_uses_yahoo_regular_close(): void
    {
        // NY 2026-07-14 17:00 ET = 애프터마켓(16:00~20:00). 오늘봉(7/14) 완결.
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // 오늘 정규장 종가(yahoo 워머 정본) — candles[1] 937 은 stale(7/13)
        Cache::put('yahoo_regular_close_MU', 983.12, 3600);
        $this->clientMock->method('get')->willReturn($this->usTodayBarCandles('983.12', '937.00'));

        // 기준가 = yahoo_regular_close 983.12 (candles[1] 937 로 롤포워드 미적용이면 실패)
        $prevClose = $this->calculator->getPrevClose('MU');
        $this->assertSame(983.12, $prevClose);

        // 현재가 976.5 → 983.12 대비 -0.67% (stale 937 이면 +4.22% 로 부호 반전)
        $result = $this->calculator->calculate('MU', 976.5);
        $this->assertSame(983.12, $result['prev_close']);
        $this->assertLessThan(0, $result['change_amount']);
        $this->assertEqualsWithDelta(-0.67, $result['change_percent'], 0.05);
    }

    /**
     * 애프터 종료~KST 자정 사이(getUsSession='장마감')에도 오늘봉(isTodayBar=true)이면 롤포워드 발동.
     * #1(애프터마켓)과 동일 로직 — 세션만 '장마감'으로 바꾼 회귀 가드. 롤포워드 게이트는
     * "정규장 아님"이라 '장마감'도 포함 → 기준가 = yahoo_regular_close(오늘 종가), candles[1](어제) 아님.
     */
    #[Test]
    public function test_get_prev_close_us_market_closed_same_day_uses_yahoo_regular_close(): void
    {
        // NY 2026-07-14 20:30 ET = 애프터 종료(20:00) 직후 '장마감'. 오늘봉(7/14) 여전히 완결.
        Carbon::setTestNow(Carbon::parse('2026-07-14 20:30:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        // 오늘 정규장 종가(yahoo 워머 정본) — candles[1] 937 은 stale(7/13)
        Cache::put('yahoo_regular_close_MU', 983.12, 3600);
        $this->clientMock->method('get')->willReturn($this->usTodayBarCandles('983.12', '937.00'));

        // 기준가 = yahoo_regular_close 983.12 (candles[1] 937 로 롤포워드 미적용이면 실패)
        $this->assertSame(983.12, $this->calculator->getPrevClose('MU'));
    }

    /**
     * SKHY(저유동 ADR) 애프터마켓: candles[1] 이 수일 전 종가(152.35)인데 yahoo_regular_close 는
     * 오늘 종가(193.92) → 기준가 = 193.92 로 큰 stale gap(+27%) 방지.
     */
    #[Test]
    public function test_get_prev_close_us_after_market_low_liquidity_adr_gap_uses_yahoo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // candles[1] = 7/9(수일 전) 152.35 · yahoo_regular_close = 오늘(7/14) 193.92
        Cache::put('yahoo_regular_close_SKHY', 193.92, 3600);
        $this->clientMock->method('get')->willReturn(
            $this->usTodayBarCandles('193.92', '152.35', '2026-07-14', '2026-07-09')
        );

        // 롤포워드로 152.35(stale) 대신 193.92(오늘 종가) 기준 → 큰 gap 방지
        $this->assertSame(193.92, $this->calculator->getPrevClose('SKHY'));

        // 현재가 194.0 → 193.92 대비 거의 0%(정합). 152.35 기준이면 +27.3% 로 폭발.
        $result = $this->calculator->calculate('SKHY', 194.0);
        $this->assertSame(193.92, $result['prev_close']);
        $this->assertEqualsWithDelta(0.0, $result['change_percent'], 0.5);
    }

    /**
     * yahoo_regular_close cold + kis_...regular_close 폴백 존재 → kis 폴백값으로 롤포워드.
     * readUsRegularClose 2순위(kis_last_successful_overseas_price_{ticker}.regular_close) 검증.
     */
    #[Test]
    public function test_get_prev_close_us_after_market_yahoo_cold_uses_kis_fallback_regular_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // yahoo 캐시 없음 → kis 24h 폴백의 regular_close(983.12) 사용
        Cache::put('kis_last_successful_overseas_price_MU', ['regular_close' => 983.12], 3600);
        $this->clientMock->method('get')->willReturn($this->usTodayBarCandles('983.12', '937.00'));

        $this->assertSame(983.12, $this->calculator->getPrevClose('MU'));
    }

    /**
     * 캐시 cold 폴백: yahoo·kis 둘 다 없으면 롤포워드 미적용 → 기존 candles[1](어제 종가) 유지(graceful).
     */
    #[Test]
    public function test_get_prev_close_us_after_market_cache_cold_keeps_candle_prev_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // yahoo_regular_close·kis 폴백 둘 다 cold → readUsRegularClose null → candles[1] 유지
        $this->clientMock->method('get')->willReturn($this->usTodayBarCandles('983.12', '937.00'));

        // 롤포워드 skip → candles[1] = 937 (전일 종가) 그대로
        $this->assertSame(937.0, $this->calculator->getPrevClose('MU'));
    }

    /**
     * 불변: US 정규장 중(getUsSession='정규장')엔 롤포워드 미발동 → 기준가 = candles[1](어제 종가).
     * yahoo_regular_close 캐시가 있어도 무시해야 장중 등락(어제 종가 대비)이 보존된다.
     */
    #[Test]
    public function test_get_prev_close_us_regular_session_no_roll_forward_uses_candle_prev(): void
    {
        // NY 2026-07-14 11:00 ET = 정규장. 오늘봉(7/14) 진행중 → isTodayBar=true.
        Carbon::setTestNow(Carbon::parse('2026-07-14 11:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('정규장');

        // 롤포워드가 (잘못) 발동하면 995.00 이 잡힌다 — 아래 assert 로 미발동 확인
        Cache::put('yahoo_regular_close_MU', 995.00, 3600);
        $this->clientMock->method('get')->willReturn($this->usTodayBarCandles('983.12', '937.00'));

        // 정규장 → candles[1] 937 기준(오늘 장중 등락). yahoo 995 로 새지 않아야 함.
        $this->assertSame(937.0, $this->calculator->getPrevClose('MU'));
    }

    /**
     * 불변: US 프리마켓(isTodayBar=false, 오늘봉 미생성)엔 candles[0](어제 종가) 경로 그대로.
     * 롤포워드는 isTodayBar 조건이라 미발동 — yahoo_regular_close 캐시가 있어도 candles[0] 유지.
     */
    #[Test]
    public function test_get_prev_close_us_pre_market_no_roll_forward_uses_candle_zero(): void
    {
        // NY 2026-07-14 08:00 ET = 프리마켓. 오늘봉(7/14) 아직 없음 → 최신 봉 7/11(금).
        Carbon::setTestNow(Carbon::parse('2026-07-14 08:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        // 프리마켓에서 롤포워드가 새면 995 가 잡힌다 — 미발동 확인
        Cache::put('yahoo_regular_close_MU', 995.00, 3600);
        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => [
                ['timestamp' => '2026-07-11T00:00:00.000-04:00', 'closePrice' => '983.12', 'volume' => '200', 'currency' => 'USD'],
                ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '937.00', 'volume' => '100', 'currency' => 'USD'],
            ]],
        ]);

        // isTodayBar=false + 라이브(프리마켓) → candles[0] = 983.12(어제=금 종가). yahoo 995 무영향.
        $this->assertSame(983.12, $this->calculator->getPrevClose('MU'));
    }

    /**
     * 불변: KR 종목은 US 전용 롤포워드(yahoo_regular_close_{ticker} 캐시)에 무영향.
     * KR 기준가는 Yahoo '일봉 시계열'에서만 오고, US 롤포워드용 캐시는 쳐다보지 않아야 한다.
     */
    #[Test]
    public function test_get_prev_close_kr_symbol_unaffected_by_us_roll_forward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        // 기준 거래일 = 7/14(어제, 라이브 분기 2) → Yahoo 일봉 263,000
        $this->useKrYahoo(['2026-07-14' => 263000.0]);

        // KR 종목인데 (우연히) US 롤포워드 캐시가 있어도 US 게이트에 안 걸려야 함
        Cache::put('yahoo_regular_close_005930', 999999.0, 3600);

        $this->clientMock->method('get')->willReturn(['result' => ['candles' => [
            ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '270000', 'currency' => 'KRW'],
            ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '265000', 'currency' => 'KRW'],
        ]]]);

        // Yahoo 일봉 263,000 (US 롤포워드 캐시 999,999 무영향 · 토스 1d 270,000 아님)
        $this->assertSame(263000.0, $this->calculator->getPrevClose('005930'));
    }

    // ──────────────────────────────────────────────────────────────────
    // calculateUsSplit() — 연장세션 통합/정규장 등락 분리 (chart-regular-ext-split)
    //   통합    = (현재가[시간외] − 직전거래일 정규장 종가) / 직전거래일 종가 × 100
    //   정규장  = (당일 정규장 종가 − 직전거래일 정규장 종가) / 직전거래일 종가 × 100
    //   직전거래일 종가 = candles[1](오늘봉 존재) / candles[0](라이브 오늘봉 없음) — 롤포워드 이전 기준.
    // ──────────────────────────────────────────────────────────────────

    /** US 1d 2봉 응답 헬퍼(최신 먼저). */
    private function usDaily(string $latestDate, string $latestClose, string $prevDate, string $prevClose): array
    {
        return ['result' => ['candles' => [
            ['timestamp' => "{$latestDate}T00:00:00.000-04:00", 'closePrice' => $latestClose, 'currency' => 'USD'],
            ['timestamp' => "{$prevDate}T00:00:00.000-04:00", 'closePrice' => $prevClose, 'currency' => 'USD'],
        ]]];
    }

    /**
     * 애프터마켓: 오늘 정규장 봉 존재 → 헤드라인 기준가 = '당일 정규장 종가'(2026-07-23, 토스 정렬).
     * 실측(MU 애프터): 현재가 979.67, 당일 정규장 종가 959.48, 직전거래일 종가 candles[1]=970.82.
     *   헤드라인(통합) = (979.67 − 959.48)/959.48 = +2.10%  ← 토스 "애프터마켓에서 +2.10%"와 일치(당일 종가 대비 순수변동)
     *   정규장(보조)   = (959.48 − 970.82)/970.82 = −1.17%  ← 당일 정규장 하루 등락(prevRegular 기준, 무변경)
     * (2026-07-23 이전엔 헤드라인이 prevRegular 기준 '통합'이었다 — 토스와 기준가가 달라 어긋났다.)
     */
    #[Test]
    public function test_calculate_us_split_after_market_splits_unified_and_regular(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));  // 애프터
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // 오늘봉(7/14) 존재 → prevRegular = candles[1] = 970.82. latest close 는 무관(regularClose 는 인자로 주입).
        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-14', '959.48', '2026-07-13', '970.82'));

        $split = $this->calculator->calculateUsSplit('MU', 979.67, 959.48);

        $this->assertEqualsWithDelta(2.10, $split['change_percent'], 0.02);           // 헤드라인 = 당일 종가 대비
        $this->assertEqualsWithDelta(-1.17, $split['regular_change_percent'], 0.02);  // 정규장 = prevRegular 대비(무변경)
        $this->assertEqualsWithDelta(20.19, $split['change_amount'], 0.01);           // 979.67 − 959.48
        $this->assertEqualsWithDelta(-11.34, $split['regular_change_amount'], 0.01);  // 959.48 − 970.82
    }

    /**
     * 프리마켓: 오늘 정규장 봉 없음 + 라이브 → 직전거래일 종가 = candles[0](어제=991.64).
     * 통합 = (969.03 − 991.64)/991.64 = −2.28%.
     *
     * 당일 정규장 봉이 없으므로(프리마켓) 정규장 줄 = null(프론트 1줄). regularClose(Yahoo)와
     * prevRegular(candles[0])는 '같은 날 종가'라 둘을 빼면 크로스소스 잔차만 남기 때문.
     * → regularClose 를 prevRegular 와 '다른 값'(991.50)으로 주입해, 이 노이즈(≈−0.014%)가
     *   regular_* 로 새지 않고 null 로 차단됨을 검증한다(동일값 주입이던 기존 테스트는 이 결함을 못 잡음).
     */
    #[Test]
    public function test_calculate_us_split_pre_market_uses_latest_bar_base(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));  // 프리
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-09', '991.64', '2026-07-08', '948.80'));

        // regularClose=991.50 ≠ prevRegular=991.64 (크로스소스 잔차 주입)
        $split = $this->calculator->calculateUsSplit('MU', 969.03, 991.50);

        $this->assertLessThan(0, $split['change_percent']);
        $this->assertEqualsWithDelta(-2.28, $split['change_percent'], 0.02);          // 통합
        $this->assertNull($split['regular_change_percent'], '당일 정규장 봉 없음(프리마켓) → 정규장 줄 null(노이즈 차단)');
        $this->assertNull($split['regular_change_amount']);
    }

    /**
     * 프리마켓인데 토스가 오늘 정규장 일봉을 미리 생성한 경우($hasTodayRegularBar=true) — 세션 이중 게이트 검증.
     *
     * 배경(2026-07-21 수정): 토스가 프리마켓 도중 오늘 일봉을 선반영해 두면 isTodayBar=true 라
     *   $hasTodayRegularBar 게이트만으론 뚫린다(실측 KST 18:30~22:30 "정규장 ~0%" 2줄 오노출).
     *   프리마켓엔 오늘 정규장이 열리지도 않았으므로 regular_* 는 정의상 존재할 수 없다 →
     *   calculateUsSplit 이 `$session !== '프리마켓'` 로 세션 게이트를 하나 더 건다.
     *
     * 이 케이스가 그 세션 게이트를 짚는다(=뮤테이션 확인): 오늘봉 존재(isTodayBar=true) +
     *   regularClose(985.00) ≠ prevRegular(candles[1]=991.64) 를 주입한다. 세션 게이트를 지우면
     *   $hasTodayRegularBar 만 남아 regular_*(≈−0.67%)가 새어나가 이 단언이 깨진다.
     */
    #[Test]
    public function test_calculate_us_split_pre_market_with_today_regular_bar_still_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));  // 프리마켓
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        // 최신 봉 = 오늘(7/10) → 토스가 프리마켓 중 미리 생성 → isTodayBar=true → prevRegular=candles[1]=991.64
        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-10', '983.00', '2026-07-09', '991.64'));

        // regularClose=985.00 ≠ prevRegular=991.64 → 세션 게이트 없으면 regular_*(=(985−991.64)/991.64≈−0.67%)가 계산된다
        $split = $this->calculator->calculateUsSplit('MU', 969.03, 985.00);

        $this->assertEqualsWithDelta(-2.28, $split['change_percent'], 0.02);  // 통합 (969.03−991.64)/991.64
        $this->assertNull($split['regular_change_percent'], '프리마켓은 오늘봉이 있어도 세션 게이트로 정규장 줄 null');
        $this->assertNull($split['regular_change_amount']);
    }

    /**
     * 주간거래 자정후(NY 이른 새벽): 당일(7/15) 정규장 봉 없음 → candles[0](7/14=983.12) 기준.
     * 애프터·주간거래 자정전(오늘봉 존재)과 달리 정규장 줄 = null(프론트 1줄) — 자정후엔 regularClose 와
     * prevRegular 가 같은 날(7/14) 종가라 잔차·ET 자정 불연속만 남는다. 통합만 살린다.
     * regularClose(983.00)≠prevRegular(983.12) 주입으로 노이즈가 안 새는지 검증.
     */
    #[Test]
    public function test_calculate_us_split_overnight_after_midnight_no_today_bar_regular_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 02:00:00', 'America/New_York'));  // 주간거래 자정후
        $this->sessionMock->method('getUsSession')->willReturn('주간거래');

        // 최신 봉 = 7/14(직전 정규장 종가) → 오늘(7/15) 봉 없음 → isTodayBar=false → candles[0]=983.12
        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-14', '983.12', '2026-07-13', '937.00'));

        $split = $this->calculator->calculateUsSplit('MU', 990.0, 983.00);

        $this->assertNull($split['regular_change_percent'], '당일 정규장 봉 없음(자정후) → 정규장 줄 null');
        $this->assertNull($split['regular_change_amount']);
        $this->assertEqualsWithDelta(0.70, $split['change_percent'], 0.02);  // 통합 (990−983.12)/983.12
    }

    /**
     * 정규장: 연장세션 아님 → 기존 calculate() 값 사용(통합=정규장), regular_* 는 null(프론트 1줄).
     * 오늘(7/10) 봉 존재 → candles[1](7/9=991.64) 기준. 현재가 985 → 약 −0.67%.
     */
    #[Test]
    public function test_calculate_us_split_regular_session_falls_back_to_calculate_null_regular(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00', 'America/New_York'));  // 정규장
        $this->sessionMock->method('getUsSession')->willReturn('정규장');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-10', '985.00', '2026-07-09', '991.64'));

        $split = $this->calculator->calculateUsSplit('MU', 985.0, 985.0);

        $this->assertNull($split['regular_change_percent'], '정규장은 regular_* 를 null 로 둬 프론트 1줄 유지');
        $this->assertNull($split['regular_change_amount']);
        $this->assertLessThan(0, $split['change_percent']);  // 991.64 대비 하락
    }

    /**
     * 장마감: 연장세션 아님 → 기존 calculate() 값(직전거래일 하루 등락 유지), regular_* null.
     */
    #[Test]
    public function test_calculate_us_split_closed_falls_back_to_calculate_null_regular(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'America/New_York'));  // 토요일 장마감
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-10', '985.00', '2026-07-09', '991.64'));

        $split = $this->calculator->calculateUsSplit('MU', 985.0, null);

        $this->assertNull($split['regular_change_percent']);
        $this->assertLessThan(0, $split['change_amount']);  // 금 종가 985 vs 전전일 991.64
    }

    /**
     * 폴백 경로(calculate() 경유)도 change_percent 를 소수 2자리로 정규화해 계약(02_계약)과 통일한다.
     * calculate() 자체는 round(4) 를 반환하지만, calculateUsSplit 폴백 반환 지점에서 round(2) 로 정규화되어야 한다.
     * (985−991.64)/991.64×100 = −0.6696(round4) → 계약 −0.67(round2).
     */
    #[Test]
    public function test_calculate_us_split_fallback_normalizes_change_percent_to_two_decimals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'America/New_York'));  // 토요일 장마감
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-10', '985.00', '2026-07-09', '991.64'));

        $split = $this->calculator->calculateUsSplit('MU', 985.0, null);

        $this->assertSame(-0.67, $split['change_percent'], '폴백 change_percent 는 계약대로 소수 2자리여야 한다(round4 −0.6696 아님)');
    }

    /**
     * regularClose cold(null) + 연장세션: 통합은 계산하되 정규장 줄은 null(프론트 1줄 degrade).
     */
    #[Test]
    public function test_calculate_us_split_extended_but_regular_close_cold_regular_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-14', '983.12', '2026-07-13', '937.00'));

        $split = $this->calculator->calculateUsSplit('MU', 995.0, null);  // regularClose cold

        $this->assertEqualsWithDelta(6.19, $split['change_percent'], 0.02);  // 통합은 계산됨
        $this->assertNull($split['regular_change_percent']);                 // 정규장 줄 degrade
    }

    /**
     * getUsPrevRegularClose: KR·지수는 null (US 전용).
     */
    #[Test]
    public function test_get_us_prev_regular_close_non_us_returns_null(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getUsPrevRegularClose('005930'));
    }

    /**
     * getUsPrevRegularClose: 캐시 히트 시 API 미호출. 캐시 포맷 = ['close'=>float,'today_bar'=>bool].
     */
    #[Test]
    public function test_get_us_prev_regular_close_cache_hit_no_api_call(): void
    {
        Cache::put('toss_prev_regular_close_MU', ['close' => 937.0, 'today_bar' => true], 3600);
        $this->clientMock->expects($this->never())->method('get');

        $isTodayBar = false;
        $this->assertSame(937.0, $this->calculator->getUsPrevRegularClose('MU', $isTodayBar));
        $this->assertTrue($isTodayBar, '캐시된 today_bar 플래그가 참조로 반환되어야 한다(히트에도 유효)');
    }

    /**
     * getUsPrevRegularClose: 봉 부족(<2)이면 null 을 반환하고 짧은 TTL 로 sentinel 을 캐싱해,
     * 다음 사이클에 /candles 를 재호출하지 않는다(연장세션 핫패스 재유입 방지). get 은 정확히 1회.
     */
    #[Test]
    public function test_get_us_prev_regular_close_insufficient_candles_caches_sentinel(): void
    {
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // 봉 1개만 → <2 → null. sentinel 캐시 덕에 두 번째 호출은 API 를 다시 치지 않는다.
        $this->clientMock->expects($this->once())->method('get')->willReturn([
            'result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000-04:00', 'closePrice' => '983.12', 'currency' => 'USD'],
            ]],
        ]);

        $this->assertNull($this->calculator->getUsPrevRegularClose('MU'));
        $this->assertNull($this->calculator->getUsPrevRegularClose('MU'));  // sentinel 히트 → API 미호출
    }

    /**
     * 주간거래(EXT_NIGHT, 미국 야간=KR 저녁 세션)도 연장세션 → 통합/정규장 2줄로 분리해야 한다.
     * 애프터마켓과 동일 candle 선택(오늘 정규장봉 완결 → candles[1]=직전거래일 937).
     * 이 기능이 겨냥한 핵심 세션인데 기존 스위트엔 프리·애프터만 있어 회귀 사각지대였다.
     */
    #[Test]
    public function test_calculate_us_split_overnight_session_splits_unified_and_regular(): void
    {
        // NY 2026-07-14 22:00 ET = 주간거래(20:00~04:00). 오늘봉(7/14) 완결 → candles[1]=7/13.
        Carbon::setTestNow(Carbon::parse('2026-07-14 22:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('주간거래');

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-14', '983.12', '2026-07-13', '937.00'));

        $split = $this->calculator->calculateUsSplit('MU', 995.0, 983.12);

        // 헤드라인 기준가 = 당일 정규장 종가(983.12) → (995−983.12)/983.12 = +1.21% (2026-07-23, 토스 정렬)
        $this->assertEqualsWithDelta(1.21, $split['change_percent'], 0.02);          // 헤드라인 = 당일 종가 대비
        $this->assertEqualsWithDelta(4.92, $split['regular_change_percent'], 0.02);  // 정규장 (983.12−937)/937 (무변경)
        $this->assertNotNull($split['regular_change_percent'], '주간거래도 연장세션이라 2줄이어야 한다');
    }

    /**
     * getUsPrevRegularClose 캐시도 calculate() 와 같은 '다음 경계'까지만 산다(D3 회귀 가드).
     * 17:00 ET(애프터) → 다음 경계 19:50 ET = 2h50m. ET 자정만 보면 19:50·20:00 을 넘겨 stale 이 된다
     * — 이 캐시의 선택 규칙도 자정 말고 세션 경계에 함께 걸려 있기 때문.
     */
    #[Test]
    public function test_get_us_prev_regular_close_ttl_stops_at_next_us_boundary(): void
    {
        // 라이브(애프터) + 과거 날짜 봉 → isTodayBar=false → candles[0](991.64) 취득·캐시.
        Carbon::setTestNow(Carbon::parse('2026-07-10 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-09', '991.64', '2026-07-08', '948.80'));

        $this->assertSame(991.64, $this->calculator->getUsPrevRegularClose('MU'));

        // 17:00 ET → 다음 경계 19:50 ET(애프터 종료) = 2h50m. (ET 자정 기준이면 7h = 25200)
        $this->assertSame(10200, $capturedTtl, 'getUsPrevRegularClose TTL 도 다음 경계(19:50 ET)에서 끊겨야 한다');
    }

    /**
     * 연장세션이지만 직전거래일 종가 취득 실패(<2봉 → getUsPrevRegularClose null)면
     * 기존 calculate() 로 graceful 폴백 → regular_* null(프론트 1줄), 통합도 calculate() 경유.
     * getUsPrevRegularClose 의 '봉 부족 → null' 경로와 calculateUsSplit 의 cold 폴백을 함께 가드한다.
     */
    #[Test]
    public function test_calculate_us_split_extended_but_prev_regular_cold_falls_back_to_calculate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('애프터마켓');

        // 1봉만 반환 → getUsPrevRegularClose·calculate()의 getPrevClose 모두 <2봉이라 null
        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000-04:00', 'closePrice' => '983.12', 'currency' => 'USD'],
            ]],
        ]);

        $split = $this->calculator->calculateUsSplit('MU', 995.0, 983.12);

        $this->assertNull($split['regular_change_percent'], 'prevRegular cold 면 정규장 줄 없음(1줄)');
        $this->assertNull($split['regular_change_amount']);
        $this->assertSame(0.0, $split['change_percent'], 'prevClose 도 <2봉 → graceful 0');
    }

    // ──────────────────────────────────────────────────────────────────
    // US 기준가 캐시 불변식 — "캐시에서 읽은 값 == 그 시각 신규조회값" (2026-07-17)
    //
    //   같은 버그가 3번 산 이유: 테스트가 프로덕션 TTL 공식을 재현해 대조했다. 공식을 재현하면
    //   '누락된 경계'는 원리적으로 안 보인다 — 양쪽이 똑같이 빠뜨리니까. 그래서 공식을 버리고
    //   관측 가능한 성질만 단언한다:
    //     ∀t, ∀Δ:  t 에 캐싱한 값을 t+Δ 에 읽은 결과 === t+Δ 에 캐시를 비우고 새로 조회한 결과
    //   경계 목록을 몰라도 되고, 새 경계가 생겨도 자동으로 잡힌다. ★ 여기에 공식을 다시 들이지 말 것.
    //
    //   시나리오(실측 MU 재현): yahoo_regular_close 워머 cold → 애프터~주간거래가 candles[1]=983.12
    //   (7/14 종가)로 굳는다. ET 자정에 isTodayBar 가 뒤집혀 정답은 904.28(7/15)로 전진 →
    //   그때 캐시가 살아있으면 D1(KST 13:00~22:30, 9.5h stale) 재현.
    //
    //   세션은 **진짜 MarketSessionService** 로 판정한다(useRealUsSession). 예전엔 여기 세션 모형
    //   복제본(usScenarioSession)이 있었고 03:30·19:30 을 그대로 베껴 갖고 있어서, 경계가 틀렸을 때
    //   테스트도 같이 틀렸다 — 불변식이 아무리 강해도 **양쪽이 같은 전제를 공유하면 아무것도 못 잡는다.**
    // ──────────────────────────────────────────────────────────────────

    /** 시나리오 거래일별 정규장 종가(ET). 7/18·7/19 는 주말이라 봉 없음. */
    private const US_SCENARIO_CLOSES = [
        '2026-07-13' => '937.00',
        '2026-07-14' => '983.12',
        '2026-07-15' => '904.28',
        '2026-07-16' => '865.43',
        '2026-07-17' => '812.00',
        '2026-07-20' => '790.00',
    ];

    /**
     * 지금(ET) 시각 기준 토스 /candles 응답 — 최신 2봉. 오늘 봉은 09:30 ET 이후에만 존재한다.
     * (프로덕션 TTL 과 무관한 '외부 세계' 모형. 시각의 함수라 시간이동에 따라 저절로 바뀐다.)
     */
    private function usScenarioCandles(): array
    {
        $now = Carbon::now('America/New_York');
        $today = $now->toDateString();
        $bars = [];

        foreach (self::US_SCENARIO_CLOSES as $date => $close) {
            if ($date > $today || ($date === $today && (int) $now->format('Hi') < 930)) {
                continue;  // 미래 봉 · 개장 전(오늘 봉 미생성)
            }
            $bars[] = ['timestamp' => "{$date}T00:00:00.000-04:00", 'closePrice' => $close, 'currency' => 'USD'];
        }

        return ['result' => ['candles' => array_slice(array_reverse($bars), 0, 2)]];
    }

    /**
     * $this->calculator 의 세션 판정을 **진짜 MarketSessionService** 로 갈아끼운다.
     * (캘린더 대역이 `[]` → 프로덕션의 하드코딩 폴백 경로 = 실제 코드가 그대로 돈다.)
     *
     * ★ 프로덕션 세션 모형을 테스트 안에 복제하지 않는다.
     *   여기엔 원래 usScenarioSession() 이라는 복제본이 있었고 03:30·19:30 을 그대로 베껴 갖고 있었다.
     *   복제본은 **경계가 틀렸을 때 같이 틀린다** — 검증자가 피검증자의 전제를 공유하니 3연속 재발을
     *   한 번도 못 잡았다(~89,000분 스캔이 "코드의 경계"만 확증하고 "코드가 틀렸다"를 못 말한 것과 같은 함정).
     *   폴백 상수가 토스 캘린더(정본)와 일치하는지는 MarketSessionServiceTest 가 픽스처로 못박는다.
     */
    private function useRealUsSession(): void
    {
        $calendarClient = $this->createStub(TossApiClient::class);
        $calendarClient->method('get')->willReturn([]);   // 캘린더 미가용 → 프로덕션 하드코딩 폴백

        $this->calculator = new TossChangeCalculator(
            $this->clientMock,
            new TossSymbolMapper,
            new MarketSessionService($calendarClient)
        );
    }

    /**
     * 폴백의 거래일 게이트(us_trading_day_{Y-m-d})를 ET 날짜별로 채운다.
     *
     * ⚠️ 이 시딩이 없으면 MarketSessionService::isUsMarketTradingToday() 가 **진짜 Yahoo SPY 를 때린다**
     *   (데이 세션 04:00~20:00 구간에서만 게이트에 도달 → 스윕이 조용히 라이브를 물어온다).
     *   값은 '그 날이 거래일인가'라는 사실 표일 뿐 세션 경계와 무관하다 — 표본 기간(7/13~7/21)엔 공휴일이 없다.
     *
     * @param  array<int,string>  $times  ET 시각 목록
     * @return callable():void 매 Cache::flush() 뒤에 부르는 시더(날짜 파싱은 1회만)
     */
    private function usTradingDaySeeder(array $times): callable
    {
        $days = [];
        foreach ($times as $t) {
            $dt = Carbon::parse($t, 'America/New_York');
            $days[$dt->toDateString()] = (int) $dt->format('N') <= 5;
        }

        return function () use ($days): void {
            foreach ($days as $date => $isTradingDay) {
                Cache::put("us_trading_day_{$date}", $isTradingDay, 86400);
            }
        };
    }

    /**
     * 불변식 단언 — 시각열을 두 번 지나며 대조한다. 프로덕션 공식은 어디서도 재현하지 않는다.
     *   fresh    : 매 시각 캐시를 비우고 조회 = 그 시각의 정답
     *   observed : 캐시를 한 번만 비우고 시간만 흘려보냄 = 실제 운영 동작
     * 둘이 갈라지면 = TTL 이 기준가 의미가 바뀌는 경계를 넘겼다.
     *
     * @param  array<int,string>  $times  ET 시각 목록(오름차순)
     * @param  bool  $warmer  regular_close 워머 가동 여부 — 두 상태가 서로 다른 경계를 노출한다:
     *                        cold(false) = 롤포워드가 candles[1] 로 폴백 → 기준가가 ET 자정에 전진 → 00:00 경계가 살아있다.
     *                        warm(true)  = 16:05 에 오늘 종가로 롤포워드 → 16:05 경계가 살아나고 자정은 연속이 된다.
     *                        한쪽만 돌리면 반대쪽 경계가 표본에서 사라진다(실측: warm 만 돌리면 00:00 을 지워도 통과).
     */
    private function assertPrevCloseNeverStale(array $times, bool $warmer = false): void
    {
        $this->useRealUsSession();
        $this->clientMock->method('get')->willReturnCallback(function (): array {
            return $this->usScenarioCandles();
        });
        $seedTradingDays = $this->usTradingDaySeeder($times);

        // regular_close 워머 모형 — 16:05 ET 이후 '오늘 정규장 종가'가 yahoo_regular_close 에 반영된다.
        //   워머가 없으면 캐시가 항상 cold 라 롤포워드 분기(TossChangeCalculator:632-640)가 한 번도
        //   실행되지 않아 16:05 경계를 지워도 no-op 으로 보인다(뮤테이션 실측 확인).
        // ponytail: 16:05 전엔 forget — 실제론 어제 종가가 남아있지만, 그 구간은 candles[1](=어제 종가)과
        //   값이 같아 관측 동작이 동일하다. 날짜 넘김 잔존을 없애 모형을 시각의 순수 함수로 유지한다.
        $warm = function () use ($warmer): void {
            if (! $warmer) {
                return;  // 워머 cold — 롤포워드가 candles[1] 유지(실측 MU 시나리오의 절반)
            }

            $now = Carbon::now('America/New_York');
            $close = (int) $now->format('Hi') >= 1605
                ? (self::US_SCENARIO_CLOSES[$now->toDateString()] ?? null)
                : null;

            if ($close === null) {
                Cache::forget('yahoo_regular_close_MU');
            } else {
                Cache::put('yahoo_regular_close_MU', (float) $close, 86400);
            }
        };

        $fresh = [];
        foreach ($times as $t) {
            Cache::flush();
            $seedTradingDays();
            Carbon::setTestNow(Carbon::parse($t, 'America/New_York'));
            $warm();
            $fresh[$t] = $this->calculator->getPrevClose('MU');
        }

        Cache::flush();
        $seedTradingDays();
        $observed = [];
        foreach ($times as $t) {
            Carbon::setTestNow(Carbon::parse($t, 'America/New_York'));
            $warm();
            $observed[$t] = $this->calculator->getPrevClose('MU');
        }

        $this->assertSame($fresh, $observed,
            'ET 시각별 기준가: 캐시에서 읽은 값이 그 시각 신규조회값과 다르다 = TTL 이 경계를 넘겨 stale');
    }

    /**
     * 24시간 스윕 시각(ET) — 5분 격자 288포인트.
     *
     * 경계 목록을 여기 두지 않는다. 격자만으로 충분하다 — 기준가가 갈라지는 창이 격자 간격보다
     * 넓으면 반드시 두 표본(창 안 1 + 창 밖 1)이 잡힌다. 조준 블록(경계 ±1분)은 경계 목록의
     * 4번째 복사본이었고 실측상 검출력 기여가 0이라 삭제했다(격자 단독으로 전부 검출).
     * 5분 격자를 쓰는 이유: 16:00→16:05 처럼 폭이 5분인 창도 덮어야 한다(:02 와 :07 이 각각 창 안팎).
     *
     * ponytail: 경계 '정각 그 1초'는 일부러 표본에서 뺀다(격자 :07 출발 → :02·:07·:12… 로 정각 회피).
     *   Cache::put(ttl) 은 expiresAt = now+ttl 로 잡고 ArrayStore 는 currentTime() > expiresAt 일 때만
     *   만료시킨다 → 경계 정각 1초 동안은 옛 기준가가 그대로 서빙된다(실측: 00:00:00·04:00:00·16:05:00).
     *   프로덕션 경계 목록의 결함이 아니라 Laravel 캐시의 만료 경계 의미(초 단위, 만료는 '지난 뒤')다.
     *   영향 = 경계당 1초(WS 사이클 ~3초) → 무시. 실질적 회피책은 없다 — TTL 을 1초 당겨도 그대로고
     *   (경계 1초 전엔 이미 max(1-1,1)=1), ttl<=0 은 Laravel 이 즉시 forget 이라 더 나쁘다.
     *   정말 없애려면 만료 비교(>)를 바꿔야 하는데 그건 캐시 스토어 교체 영역이다.
     */
    private function etSweepTimes(string $startEt): array
    {
        $start = Carbon::parse($startEt, 'America/New_York');

        $times = [];
        for ($i = 0; $i < 288; $i++) {  // 24h / 5분
            $times[] = $start->copy()->addMinutes(5 * $i)->format('Y-m-d H:i:s');
        }

        return $times;
    }

    /**
     * 24시간 스윕(5분 격자 288포인트): 어느 시각에 읽어도 캐시값 == 신규조회값.
     *
     * 검출 범위(2026-07-17 뮤테이션 재실측 — 옛 주석의 "경계를 줄이면 즉시 FAIL"은 더 이상 참이 아니다):
     *   이 스윕이 잡는 것 = 세션 경계와 TTL 이 **어긋나는** 것(교정 전 03:30/19:30 조합에서 실제로 FAIL 했다).
     *   못 잡는 것 = [0,0]·[16,5] 단독 삭제. 롤포워드 실패분이 sentinel TTL(120s)로만 캐싱되면서
     *     (2026-07-17 워머 레이스 수정) 애프터~자정 구간에 장TTL 쓰기 자체가 없어졌기 때문이다.
     *     → [0,0] 은 주말 warm 스윕이, [16,5] 는 sentinel 케이스 1a~1c 가 각각 맡는다.
     *   ★ 경계 목록을 손대면 이 스윕만 믿지 말고 뮤테이션으로 어느 테스트가 잡는지 다시 확인할 것.
     */
    #[Test]
    public function test_get_prev_close_us_ttl_cached_value_never_diverges_from_fresh_lookup(): void
    {
        // ET 7/15 12:07(정규장) 출발 → 7/16 12:07 까지. 경계 7개가 창 안에 한 번씩 들어온다.
        $this->assertPrevCloseNeverStale($this->etSweepTimes('2026-07-15 12:07:00'));
    }

    /**
     * 같은 24시간 스윕을 regular_close 워머 warm 상태로 한 번 더 — 실측 MU 버그의 나머지 절반.
     *
     * 워머가 warm 이면 16:05 에 기준가가 '오늘 정규장 종가'로 롤포워드된다(:632-640). cold 스윕은
     * 이 분기를 아예 실행하지 않아 16:05 경계를 지워도 통과한다(뮤테이션 실측). 반대로 warm 스윕만
     * 두면 자정 전진이 사라져 00:00 을 놓친다 — 두 상태가 각각 다른 경계를 잡으므로 둘 다 돌린다.
     */
    #[Test]
    public function test_get_prev_close_us_ttl_warm_regular_close_cached_value_never_diverges(): void
    {
        $this->assertPrevCloseNeverStale($this->etSweepTimes('2026-07-15 12:07:00'), true);
    }

    /**
     * 주말 갭: 금 애프터 → 토·일 장마감 → 일 20:00 주간거래 개시 → 월 프리마켓 → 월 개장.
     * 거래일이 3일 건너뛰어도 캐시가 기준가 전진(865.43 → 812.00)을 놓치지 않아야 한다.
     *
     * 일 19:53 은 [20,0] 경계의 유일한 가드다 — 20:00 을 넘기는 캐시 쓰기는 **(19:50, 20:00)** 구간에서만
     * 발생한다(그 밖의 시각은 16:00·19:50 경계에서 이미 만료돼 20:01 에 어차피 재조회된다).
     * 이 표본이 없으면 [20,0] 을 지워도 스위트가 통과한다(뮤테이션 실측 확인).
     *
     * ⚠️ 이 표본은 **애프터 종료 경계에 종속**이다 — 2026-07-17 경계 교정(19:30 → 19:50) 전엔 19:47 이었고,
     *   교정 후 19:47 에 쓴 캐시는 19:50 에 만료돼 20:00 을 넘길 수 없게 되면서 [20,0] 가드가 조용히 무장해제됐다
     *   (뮤테이션 실측: [20,0] 을 지워도 통과). 애프터 종료를 또 옮기면 이 표본도 (새 종료, 20:00) 안으로 옮길 것.
     */
    #[Test]
    public function test_get_prev_close_us_ttl_weekend_gap_cached_value_never_diverges(): void
    {
        $this->assertPrevCloseNeverStale($this->weekendGapTimes());
    }

    /**
     * 같은 주말 갭을 워머 warm 으로 한 번 더 — **[0,0](ET 자정) 경계의 유일한 가드**.
     *
     * 금 23:53 warm 은 기준가가 '금 정규장 종가'(812.00)로 롤포워드된 상태다. ET 자정에 isTodayBar 가
     * 뒤집혀 정답이 865.43(목 종가, 주말이라 candles[1])로 전진하므로, 캐시가 자정을 넘기면 stale 이다.
     *
     * ⚠️ cold 로는 이 경계가 안 보인다 — 롤포워드 실패분이 sentinel TTL(120s)로만 캐싱되면서(2026-07-17
     *   워머 레이스 수정) 애프터~자정 구간의 장TTL 쓰기가 사라졌기 때문. 24h cold 스윕이 자정을 잡았다던
     *   기존 확증은 그 수정으로 무효가 됐다(뮤테이션 실측: [0,0] 을 지워도 cold 는 전부 통과).
     *   → 자정 가드는 이제 **이 warm 표본 하나뿐**이다. 지우지 말 것.
     */
    #[Test]
    public function test_get_prev_close_us_ttl_weekend_gap_warm_regular_close_cached_value_never_diverges(): void
    {
        $this->assertPrevCloseNeverStale($this->weekendGapTimes(), true);
    }

    /**
     * 주말 갭 표본(ET) — 금 애프터 → 토·일 장마감 → 일 20:00 주간거래 개시 → 월 개장.
     * cold/warm 두 스윕이 공유한다(표본이 갈라지면 한쪽 가드가 조용히 사라진다).
     */
    private function weekendGapTimes(): array
    {
        return [
            '2026-07-17 17:00:00',  // 금 애프터마켓
            '2026-07-17 23:53:00',  // 금 밤 — 여기 쓰인 캐시가 ET 자정을 넘기면 stale ([0,0] 가드, warm 에서만 관측)
            '2026-07-18 00:02:00',  // 토 자정 직후 — isTodayBar 반전 → 기준가 전진(812.00 → 865.43)
            '2026-07-18 12:00:00',  // 토 장마감
            '2026-07-19 12:00:00',  // 일 장마감
            '2026-07-19 19:53:00',  // 일 애프터종료~주간거래 공백 — 캐시가 20:00 을 넘기면 stale ([20,0] 가드)
            '2026-07-19 20:01:00',  // 일 주간거래 개시 — 기준가가 금(7/17) 종가로 전진
            '2026-07-20 02:00:00',  // 월 새벽 주간거래
            '2026-07-20 04:01:00',  // 월 프리마켓
            '2026-07-20 09:35:00',  // 월 개장 직후 — 기준가가 금(7/17) 종가로 전진
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // 교정된 세션 경계 — 기대값이 코드가 아니라 **backend 실측**에서 온 리터럴이다 (2026-07-17)
    //
    //   옛 상수(주간거래 종료 03:30 · 애프터 종료 19:30)의 출처는 토스 앱 안내 팝업 스크린샷
    //   (docs/거래시간.jpg)이었다 — 토스가 자기 앱과 자기 API 에서 다른 값을 말했고, 실측이 API 손을
    //   들어줬다. 아래 두 케이스는 세션을 **진짜 MarketSessionService** 로 판정하고 기대값만 실측
    //   리터럴로 박는다 → 상수를 옛값으로 되돌리면 세션이 '장마감'으로 뒤집혀 즉시 FAIL 한다.
    //   (폴백 상수 ↔ 토스 캘린더 정합은 MarketSessionServiceTest 가 픽스처로 별도 못박는다.)
    // ──────────────────────────────────────────────────────────────────

    /**
     * ★ 케이스 1 — 주간거래 종료는 04:00 ET: 03:29 · 03:30 · 04:00 기준가가 **전부 904.28**.
     *
     * 실측: ET 03:30~04:00 은 91/91분 전부 체결(무거래봉 0)이고 04:01 에 거래량이 169 → 44,397 로
     * 폭증한다(= 진짜 프리마켓 개시). 즉 03:30 은 허구였고 그 30분은 살아있는 주간거래다.
     * 경계를 03:30 으로 되돌리면 03:30 이 '장마감'으로 뒤집혀 전전일 983.12 를 답하며 FAIL 한다.
     */
    #[Test]
    public function test_get_prev_close_us_overnight_session_ends_at_four_am_et(): void
    {
        $this->useRealUsSession();
        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        foreach (['03:29:00', '03:30:00', '04:00:00'] as $et) {
            Cache::flush();
            Cache::put('us_trading_day_2026-07-16', true, 86400);  // 04:00 은 거래일 게이트를 탄다(Yahoo 차단)
            Carbon::setTestNow(Carbon::parse("2026-07-16 {$et}", 'America/New_York'));

            $this->assertSame(904.28, $this->calculator->getPrevClose('MU'),
                "ET 7/16 {$et} 기준가는 7/15 종가 904.28 (전전일 983.12 면 03:30 이 장마감으로 뒤집힌 것)");
        }
    }

    /**
     * ★ 케이스 2 — 애프터 종료는 19:50 ET: 19:29·19:31·19:49 는 2줄, 19:51 은 1줄, 20:01 은 다시 2줄.
     *
     * 실측: 19:30~20:00 도 전 분 체결(NVDA 19:51 15,249주). 캘린더 afterMarket.endTime = KST 08:50
     * = ET 19:50 과 정합(개발자 결정). 경계를 19:30 으로 되돌리면 19:31·19:49 가 '장마감'이 되어
     * 연장세션 분기를 못 타고 regular_change_percent 가 null(1줄)로 떨어져 FAIL 한다.
     *
     * 19:51(1줄)·20:01(2줄)은 과교정 가드다 — "전부 연장세션"으로 뭉개면 이 둘이 깨진다.
     */
    #[Test]
    public function test_calculate_us_split_after_market_session_ends_at_seventeen_fifty_et(): void
    {
        $this->useRealUsSession();
        $this->clientMock->method('get')->willReturn($this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        // ET 시각 => 2줄 여부(regular_change_percent 유무) — backend 실측 표
        $expected = [
            '19:29:00' => true,   // 애프터마켓
            '19:31:00' => true,   // 애프터마켓 (옛 상수면 장마감)
            '19:49:00' => true,   // 애프터마켓 (애프터 종료 1분 전)
            '19:51:00' => false,  // 장마감 (19:50~20:00 공백)
            '20:01:00' => true,   // 주간거래 개시
        ];

        foreach ($expected as $et => $twoLines) {
            Cache::flush();
            Cache::put('us_trading_day_2026-07-15', true, 86400);
            Carbon::setTestNow(Carbon::parse("2026-07-15 {$et}", 'America/New_York'));

            $split = $this->calculator->calculateUsSplit('MU', 900.0, 904.28);

            $twoLines
                ? $this->assertNotNull($split['regular_change_percent'], "ET 7/15 {$et} 는 연장세션 = 2줄")
                : $this->assertNull($split['regular_change_percent'], "ET 7/15 {$et} 는 장마감 = 1줄");
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // US 라이브 TTL 대표 케이스 — 세션별 첫 경계를 리터럴로 못박는다(공식 재현 금지).
    //   3연속 재발 이력은 프로덕션 secondsUntilNextUsBoundary() 주석이 정본.
    // ──────────────────────────────────────────────────────────────────

    /**
     * US 라이브 분기(1·2번)로 진입시켜 Cache::put 에 넘어간 TTL 을 잡아낸다.
     *
     * @param  string  $frozenEt  ET 고정 시각(분기 판별 + TTL 계산 기준)
     * @param  array  $candles  /candles 응답
     * @param  float|null  $warmRegularClose  yahoo_regular_close_MU 캐시값. null = 워머 cold(롤포워드 실패 경로).
     */
    private function captureUsLiveTtl(string $frozenEt, string $session, array $candles, ?float $warmRegularClose = null): ?int
    {
        Carbon::setTestNow(Carbon::parse($frozenEt, 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn($session);

        $capturedTtl = null;
        // prev_close 는 항상 cold(=매번 재조회). yahoo_regular_close 만 워머 상태에 따라 갈린다.
        Cache::shouldReceive('get')->andReturnUsing(function (string $key) use ($warmRegularClose) {
            return $key === 'yahoo_regular_close_MU' ? $warmRegularClose : null;
        });
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->clientMock->method('get')->willReturn($candles);
        $this->calculator->getPrevClose('MU');

        return $capturedTtl;
    }

    /**
     * 케이스 1a(핵심 재현) — 애프터마켓 + 워머 cold = 롤포워드 실패 → sentinel TTL(120s)로만 캐싱.
     *
     * WS 사이클은 step4(기준가 계산)가 step6a(워머)보다 먼저 돈다 → 워머가 아직 안 채운 순간엔
     * 롤포워드가 조용히 candles[1](7/14 = 983.12, 어제 종가)로 폴백한다. 그 '실패값'을 정상 경계
     * TTL(19:50 까지 = 9060s)로 박으면 워머가 3초 뒤 정답을 채워도 자가치유가 안 된다
     * (실측 MU 기준가 6.6% 오차가 최대 3h45m 고착 — 옛 테스트는 경계 TTL 을 정답으로 박아 이 결함을 계약화했다).
     */
    #[Test]
    public function test_fetch_and_cache_us_after_hours_ttl_rollforward_cold_uses_sentinel_ttl(): void
    {
        // ET 7/15 17:19 애프터 — 오늘봉(7/15) 완결, yahoo cold → candles[1](7/14) 유지 = 실측 stale 값
        $ttl = $this->captureUsLiveTtl('2026-07-15 17:19:00', '애프터마켓',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        $this->assertSame(120, $ttl, '롤포워드 실패값은 sentinel TTL(120s)로만 — 정상 경계 7860s 로 박으면 고착');
        $this->assertSame(983.12, $this->calculator->getPrevClose('MU'), '전제: cold 면 값은 candles[1] 로 graceful 폴백');
    }

    /**
     * 케이스 1b(과교정 가드) — 같은 애프터마켓이라도 워머 warm 이면 정상 경계 TTL(19:50 = 2h31m).
     *
     * 1a 의 sentinel 이 정상 경로까지 먹어치우면(항상 120s) 심볼당 /candles 가 하루 720회로 폭증한다.
     * cold/warm 한 쌍이 함께 있어야 "실패 경로에만 닿았다"가 고정된다.
     */
    #[Test]
    public function test_fetch_and_cache_us_after_hours_ttl_rollforward_warm_stops_at_next_boundary(): void
    {
        // 워머 정본 848.43 — candles[0](904.28)·candles[1](983.12) 어느 쪽과도 달라 소스가 결과에 드러난다
        $ttl = $this->captureUsLiveTtl('2026-07-15 17:19:00', '애프터마켓',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'), 848.43);

        $this->assertSame(9060, $ttl, '애프터 17:19 ET + 워머 warm → 다음 경계 19:50 ET (2h31m)');
        $this->assertSame(848.43, $this->calculator->getPrevClose('MU'), '전제: warm 이면 기준가 = 워머 정본(롤포워드 성립)');
    }

    /**
     * 케이스 1c(실측 최악 시나리오) — ET 16:05:00 정각 + 워머 미실행.
     *
     * 16:05 엔 yahoo_regular_close 와 toss_prev_close 가 동시 만료돼 100% 이 경로를 탄다.
     * sentinel 이 없으면 다음 경계 19:50 까지 13500s(3h45m) 동안 어제 종가 기준이 고착된다.
     */
    #[Test]
    public function test_fetch_and_cache_us_close_boundary_rollforward_cold_uses_sentinel_ttl(): void
    {
        $ttl = $this->captureUsLiveTtl('2026-07-15 16:05:00', '애프터마켓',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        $this->assertSame(120, $ttl, '16:05 ET 워머 미실행 → 120s(옛 12300s = 3h25m 고착)');
    }

    /**
     * 케이스 1d — 애프터 진입 직후(16:02 ET) + 워머 warm 작성분은 **16:05 경계**에서 끊긴다 = 3분.
     *
     * [16,5] 를 지켜주는 유일한 테스트다. 스윕은 이 경계를 못 잡는다 — 스윕의 워머 모형이 16:05 전엔
     * 캐시를 forget 해 (16:00, 16:05) 구간이 항상 롤포워드 cold(=sentinel 120s)가 되기 때문
     * (뮤테이션 실측: [16,5] 를 지워도 스윕 전부 통과). 실제 워머는 ET 자정 TTL + skip-if-warm 이라
     * 이 구간에 '오늘 종가가 아닌 값'이 이미 앉아있을 수 있고, 그게 16:05 에 오늘 종가로 교체된다.
     */
    #[Test]
    public function test_fetch_and_cache_us_after_hours_ttl_just_after_close_stops_at_regular_close_confirm_boundary(): void
    {
        $ttl = $this->captureUsLiveTtl('2026-07-15 16:02:00', '애프터마켓',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'), 848.43);

        $this->assertSame(180, $ttl, '애프터 16:02 ET + 워머 warm → 다음 경계 16:05 ET (3m)');
    }

    /**
     * 케이스 1e — 프리마켓(05:00 ET) 작성분은 **09:30(정규장 개시)** 경계에서 끊긴다 = 4h30m.
     *
     * [9,30] 를 지켜주는 유일한 테스트다. 스윕은 09:30 을 못 잡는다 — 프리마켓도 라이브 세션이라
     * 개장 전후로 기준가 '값'이 안 바뀌기 때문(프리 candles[0] == 개장후 candles[1], 뮤테이션 실측).
     * 값이 같아도 경계를 지우면 TTL 이 16:00 까지 늘어나므로 리터럴로 못박는다.
     */
    #[Test]
    public function test_fetch_and_cache_us_pre_market_ttl_stops_at_open_boundary(): void
    {
        // ET 7/16 05:00 프리마켓 — 오늘봉(7/16) 미생성 → 라이브 분기 candles[0](7/15) 기준
        $ttl = $this->captureUsLiveTtl('2026-07-16 05:00:00', '프리마켓',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        $this->assertSame(16200, $ttl, '프리마켓 05:00 ET → 다음 경계 09:30 ET (4h30m)');
    }

    /**
     * 케이스 2 — 정규장(10:42 ET) 작성분은 16:00(정규장 마감) 경계에서 끊긴다 = 5h18m.
     * 개장 경계를 무조건 우선하는 식으로 잘못 고치면 이 케이스가 깨진다(과교정 가드).
     */
    #[Test]
    public function test_fetch_and_cache_us_regular_ttl_keeps_close_boundary(): void
    {
        // ET 7/16 10:42 정규장 — 오늘봉(7/16) 진행중 → candles[1](7/15=904.28) 기준
        $ttl = $this->captureUsLiveTtl('2026-07-16 10:42:00', '정규장',
            $this->usDaily('2026-07-16', '865.43', '2026-07-15', '904.28'));

        $this->assertSame(19080, $ttl, '정규장 10:42 ET → 다음 경계 16:00 ET (5h18m)');
        // 10:42 ET = 23:42 KST → KST 자정 기준이면 1080초. 1차 버그(7/14) 재발 가드.
        $this->assertNotSame(1080, $ttl, 'US TTL 이 KST 자정(1080초) 기준이면 안 된다');
    }

    /**
     * 케이스 3 — 주간거래(02:00 ET) 작성분은 04:00(주간거래 종료·프리마켓 개시) 경계에서 끊긴다 = 2h.
     * 09:30 개장까지 통으로 잡으면 04:00 을 넘겨 stale(D1)이 된다.
     */
    #[Test]
    public function test_fetch_and_cache_us_overnight_ttl_stops_at_next_boundary(): void
    {
        // ET 7/16 02:00 주간거래 — 오늘봉(7/16) 미생성 → 라이브 분기 candles[0](7/15) 기준
        $ttl = $this->captureUsLiveTtl('2026-07-16 02:00:00', '주간거래',
            $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12'));

        $this->assertSame(7200, $ttl, '주간거래 02:00 ET → 다음 경계 04:00 ET (2h)');
    }

    /**
     * 케이스 4(핵심 E2E) — 애프터마켓에 롤포워드 cold 로 굳은 기준가가 다음 정규장까지 살아남지 않는다.
     *
     * 시나리오(실측 MU 재현):
     *   ET 7/15 17:19 애프터 + yahoo_regular_close cold → 롤포워드 실패 → candles[1] = 983.12(7/14 종가) 캐싱
     *   → ET 7/16 10:42 정규장으로 시간이동 → 캐시가 만료돼 있어야 기준가가 904.28(7/15 종가)로 전진
     *   → 현재가 865.43 → −4.30%. 캐시가 살아남으면 983.12 고착 → −11.97%(실제 버그 화면).
     *
     * 캐시 만료(array store)·TTL 계산 모두 Carbon::now(=setTestNow) 기준이라 실행 시각과 무관하다.
     */
    #[Test]
    public function test_get_prev_close_us_after_hours_base_expires_before_next_regular_session(): void
    {
        // 세션·캔들은 '지금이 언제인지'(setTestNow)에 따라 응답 — 시간이동을 한 테스트 안에서 재현
        $this->sessionMock->method('getUsSession')->willReturnCallback(function (): string {
            return (int) Carbon::now('America/New_York')->format('Hi') >= 1600 ? '애프터마켓' : '정규장';
        });
        $this->clientMock->method('get')->willReturnCallback(function (): array {
            return Carbon::now('America/New_York')->toDateString() === '2026-07-15'
                ? $this->usDaily('2026-07-15', '904.28', '2026-07-14', '983.12')   // 애프터: 오늘봉=7/15 완결
                : $this->usDaily('2026-07-16', '865.43', '2026-07-15', '904.28');  // 다음날 정규장: 오늘봉=7/16 진행중
        });

        // ① ET 7/15 17:19 애프터 — yahoo_regular_close cold → 롤포워드 실패 → candles[1] = 983.12 캐싱
        Carbon::setTestNow(Carbon::parse('2026-07-15 17:19:00', 'America/New_York'));
        $this->assertSame(983.12, $this->calculator->getPrevClose('MU'), '전제: 롤포워드 cold → 7/14 종가가 캐시에 굳는다');

        // ② ET 7/16 10:42 정규장으로 시간이동 — 캐시가 만료돼 기준가가 7/15 종가로 전진해야 한다
        Carbon::setTestNow(Carbon::parse('2026-07-16 10:42:00', 'America/New_York'));
        $result = $this->calculator->calculate('MU', 865.43);

        $this->assertSame(904.28, $result['prev_close'],
            '애프터 기준가(983.12)가 다음 정규장까지 살아남으면 안 된다 — TTL 이 09:30 ET 개장을 넘긴 것(MU 7/16)');
        $this->assertEqualsWithDelta(-4.30, $result['change_percent'], 0.05,
            '기준가 904.28 기준 −4.30% — stale 983.12 면 −11.97% 로 3배 부풀려진다');
    }

    // ──────────────────────────────────────────────────────────────────
    // ★ KR 기준가 불변식 스윕 — 24h 5분 격자 cold·warm 2패스 + 주말갭 (US 스윕의 KR 이식)
    //
    //   불변식 2개를 함께 건다. 하나만으론 이번 버그를 못 잡는다:
    //     (a) 시간정합  캐시값 === 그 시각 fresh 재조회값  → TTL 이 경계를 넘겼는지(stale)
    //     (b) 소스정합  기준가 ∈ Yahoo 정규장 종가 집합, 그리고 토스 1d 봉 종가는 절대 아님
    //                  → 드리프트 오염 소스로 되돌아갔는지
    //   왜 (b)가 필요한가: 토스 1d 봉 종가는 '과거 거래일 봉'도 시간외 체결로 재집계된 채 굳는다
    //   (000660 7/15 봉 = 2,022,000, 정규장 종가는 2,082,000). 즉 오염값도 '거래일의 함수'라
    //   시간에 대해 자기일관적이다 → (a)만으론 옛 계약으로 되돌려도 통과해버린다(뮤테이션 실측 확인).
    //
    //   세계모형 분리(핵심): Yahoo 정규장 종가 ≠ 토스 1d 봉 종가를 두 개의 서로소 표(表)로 둔다.
    //   같은 값으로 모형화하면 이번 버그가 원리적으로 안 잡힌다(US 에서 일요일을 통째로 장마감으로
    //   모형화해 20:00 경계를 놓친 사고와 동형).
    //
    //   표본 종목 = 000660(하이닉스) — 드리프트가 실측된 종목. 0167A0(ETF)처럼 드리프트 0 인 종목만
    //   넣으면 두 소스가 같은 값이라 전부 통과한다(7/15 에 실제로 버그를 가렸다).
    //
    //   세션은 대역을 쓰지 않고 실제 MarketSessionService 를 쓴다 — 토스 캘린더만 빈 응답으로 두면
    //   프로덕션의 '평일=거래일' 폴백이 그대로 돈다. 손으로 옮긴 세션 모형이 현실과 어긋나 사각이
    //   생기는 US 사고를 아예 차단한다.
    // ──────────────────────────────────────────────────────────────────

    /** 시나리오 거래일별 Yahoo(.KS) '정규장 종가' = KR 기준가의 정본. 7/18·7/19 는 주말이라 없음. */
    private const KR_SCENARIO_REGULAR = [
        '2026-07-14' => 1913000.0,
        '2026-07-15' => 2082000.0,
        '2026-07-16' => 1850000.0,
        '2026-07-17' => 1790000.0,
        '2026-07-20' => 1755000.0,
    ];

    /**
     * 같은 거래일의 '토스 1d 봉 종가' = 시간외 드리프트로 오염된 값. 위 표와 값이 겹치지 않는다(서로소).
     * 7/14·7/15 는 실측 드리프트: 1,913,000→1,941,000(+1.46%) · 2,082,000→2,022,000(−2.88%).
     * 부호가 양·음으로 갈려 있어 '오염 소스를 쓰면 등락 부호까지 뒤집힌다'는 성질이 모형에 남아 있다.
     */
    private const KR_SCENARIO_TOSS_1D = [
        '2026-07-14' => 1941000.0,
        '2026-07-15' => 2022000.0,
        '2026-07-16' => 1868000.0,
        '2026-07-17' => 1795000.0,
        '2026-07-20' => 1770000.0,
    ];

    /**
     * 지금(KST) 시각 기준 토스 /candles 응답 — 최신 2봉. 오늘 봉은 09:00 KST 이후에만 존재한다.
     * (프로덕션 TTL 과 무관한 '외부 세계' 모형. 시각의 함수라 시간이동에 따라 저절로 바뀐다.)
     */
    private function krScenarioCandles(): array
    {
        $now = Carbon::now('Asia/Seoul');
        $today = $now->toDateString();
        $bars = [];

        foreach (self::KR_SCENARIO_TOSS_1D as $date => $close) {
            if ($date > $today || ($date === $today && (int) $now->format('Hi') < 900)) {
                continue;  // 미래 봉 · 개장 전(오늘 봉 미생성)
            }
            $bars[] = ['timestamp' => "{$date}T00:00:00.000+09:00", 'closePrice' => (string) $close, 'currency' => 'KRW'];
        }

        return ['result' => ['candles' => array_slice(array_reverse($bars), 0, 2)]];
    }

    /**
     * 스윕용 계산기 — 실제 MarketSessionService + 시나리오 캔들 + Yahoo 대역.
     *
     * clientMock 한 곳에서 두 경로를 분기한다:
     *   /api/v1/market-calendar/KR → [](빈 응답) → 프로덕션이 '평일=거래일' 폴백으로 판정(7/18·7/19 주말=휴장)
     *   /api/v1/candles            → 시나리오 1d 봉
     */
    private function krSweepCalculator(): TossChangeCalculator
    {
        $this->clientMock->method('get')->willReturnCallback(function (string $path, array $query = []) {
            return $path === '/api/v1/market-calendar/KR' ? [] : $this->krScenarioCandles();
        });

        return new TossChangeCalculator(
            $this->clientMock,
            new TossSymbolMapper,
            new MarketSessionService($this->clientMock),   // 실제 세션 판정(모형 아님)
            $this->krYahooClient(self::KR_SCENARIO_REGULAR)
        );
    }

    /**
     * 불변식 단언 — 시각열을 두 번 지나며 대조한다. 프로덕션 공식은 어디서도 재현하지 않는다.
     *   fresh    : 매 시각 캐시를 비우고 조회 = 그 시각의 정답
     *   observed : 캐시를 한 번만 비우고 시간만 흘려보냄 = 실제 운영 동작
     *
     * @param  array<int,string>  $times  KST 시각 목록(오름차순)
     */
    private function assertKrPrevCloseNeverStale(array $times): void
    {
        $calc = $this->krSweepCalculator();

        $fresh = [];
        foreach ($times as $t) {
            Cache::flush();
            Carbon::setTestNow(Carbon::parse($t, 'Asia/Seoul'));
            $fresh[$t] = $calc->getPrevClose('000660');
        }

        Cache::flush();
        $observed = [];
        foreach ($times as $t) {
            Carbon::setTestNow(Carbon::parse($t, 'Asia/Seoul'));
            $observed[$t] = $calc->getPrevClose('000660');
        }

        // (a) 시간정합 — 캐시가 기준가 의미가 바뀌는 경계를 넘겨 살아남지 않았는가
        $this->assertSame($fresh, $observed,
            'KST 시각별 기준가: 캐시에서 읽은 값이 그 시각 신규조회값과 다르다 = TTL 이 경계를 넘겨 stale');

        // (b) 소스정합 — 기준가가 Yahoo 정규장 종가인가(토스 1d 드리프트 오염값이 아닌가)
        $seen = array_values(array_unique(array_filter($observed, fn ($v) => $v !== null)));
        $this->assertNotEmpty($seen, '표본이 전부 null 이면 스윕이 아무것도 검증하지 못한다');
        $this->assertSame([], array_values(array_intersect($seen, array_values(self::KR_SCENARIO_TOSS_1D))),
            '기준가에 토스 1d 봉 종가(시간외 드리프트 오염값)가 섞였다 — 기준가 소스가 Yahoo 정규장 종가에서 벗어났다');
        $this->assertSame([], array_values(array_diff($seen, array_values(self::KR_SCENARIO_REGULAR))),
            '기준가가 Yahoo 정규장 종가 집합 밖의 값이다');
    }

    /**
     * 24시간 스윕 시각(KST) — 5분 격자 288포인트.
     *
     * :07 출발로 경계 정각(09:00:00·00:00:00)을 표본에서 비켜간다 — Laravel 캐시는 만료를
     * currentTime() > expiresAt 로 판정해 경계 정각 1초는 옛 값이 서빙된다(US 스윕 주석과 동일 사유,
     * 프로덕션 결함 아님 · 영향 = 경계당 1초, WS 사이클 ~3초).
     */
    private function kstSweepTimes(string $startKst): array
    {
        $start = Carbon::parse($startKst, 'Asia/Seoul');

        $times = [];
        for ($i = 0; $i < 288; $i++) {  // 24h / 5분
            $times[] = $start->copy()->addMinutes(5 * $i)->format('Y-m-d H:i:s');
        }

        return $times;
    }

    /**
     * 24시간 스윕(5분 격자 288포인트): 어느 시각에 읽어도 캐시값 == 신규조회값 == Yahoo 정규장 종가.
     * 09:00 개장(유일한 진짜 경계)과 00:00 KST 자정(값 동일해야 함)이 창 안에 들어온다.
     */
    #[Test]
    public function test_get_prev_close_kr_ttl_cached_value_never_diverges_from_fresh_lookup(): void
    {
        // KST 7/16 12:07(정규장) 출발 → 7/17 12:07. 09:00 개장·00:00 자정이 한 번씩 들어온다.
        $this->assertKrPrevCloseNeverStale($this->kstSweepTimes('2026-07-16 12:07:00'));
    }

    /**
     * 주말 갭: 금 장마감 → 토·일 휴장 → 월 개장. 거래일이 3일 건너뛰어도 캐시가 기준가 전진
     * (7/16 종가 1,850,000 → 7/17 종가 1,790,000)을 놓치지 않아야 한다.
     * 휴장일 판정은 실제 MarketSessionService 의 주말 폴백이 담당한다.
     */
    #[Test]
    public function test_get_prev_close_kr_ttl_weekend_gap_cached_value_never_diverges(): void
    {
        $this->assertKrPrevCloseNeverStale([
            '2026-07-17 16:30:00',  // 금 장마감(오늘봉 존재) — 기준가 = 7/16 종가
            '2026-07-18 12:00:00',  // 토 휴장
            '2026-07-19 12:00:00',  // 일 휴장
            '2026-07-19 23:50:00',  // 일 심야 — 여기 쓰인 캐시가 월 09:00 을 넘기면 stale
            '2026-07-20 08:50:00',  // 월 개장 직전 — 아직 7/16 종가 기준
            '2026-07-20 09:35:00',  // 월 개장 직후 — 기준가가 금(7/17) 종가로 전진
            '2026-07-20 14:00:00',  // 월 정규장
        ]);
    }

    /**
     * 09:00 개장 직전 콜드 캐시가 개장을 넘겨 살아남지 않는다 — secondsUntilNextKrOpen() 하한의 유일한 가드.
     *
     * 격자 스윕만으론 이 결함을 못 잡는다: 개장 전 구간의 캐시는 00:0x 에 '09:00 까지'로 한 번 쓰이고
     * 그 뒤론 히트만 하므로, (08:55, 09:00) 안에서 캐시 쓰기가 일어나지 않아 하한이 개입할 기회가 없다.
     * 여기서만 그 창에서 콜드 조회를 강제한다(US 의 일 19:47 가드와 동형).
     * 하한을 300 초로 되돌리면 08:57 작성분이 09:02 까지 살아 stale → 이 테스트가 FAIL 한다.
     */
    #[Test]
    public function test_get_prev_close_kr_ttl_cold_before_open_does_not_survive_open(): void
    {
        $this->assertKrPrevCloseNeverStale([
            '2026-07-16 08:57:00',  // 개장 3분 전 콜드 — TTL 하한이 있으면 여기 작성분이 09:00 을 넘긴다
            '2026-07-16 09:02:00',  // 개장 직후 — 기준가가 7/15 종가로 전진해야 한다
        ]);
    }

    /**
     * KR 기준가 경계 전수 — 09:00 개장 '하나만' 살아있고 옛 오염 경계(15:31·19:01·20:00·자정)는 전부 소멸했다.
     *
     * 기준가가 '시계의 함수'(시간외 드리프트를 따라감)에서 '거래일의 함수'(Yahoo 정규장 종가)로 바뀐 결과다.
     * 고정 시각이라 리터럴 상수로 못박는다 — 프로덕션 공식을 재현하지 않는다.
     */
    #[Test]
    public function test_get_prev_close_kr_open_is_the_only_reference_boundary(): void
    {
        $calc = $this->krSweepCalculator();

        $at = function (string $kst) use ($calc): ?float {
            Cache::flush();
            Carbon::setTestNow(Carbon::parse($kst, 'Asia/Seoul'));

            return $calc->getPrevClose('000660');
        };

        // 개장 전 = 7/14 종가 기준(어제 하루 등락 유지)
        $this->assertSame(1913000.0, $at('2026-07-16 08:58:00'), '개장 전(장마감) → 기준가 = 7/14 정규장 종가');

        // ★ 09:00 개장 = 유일한 진짜 경계 — 기준가가 7/15 종가로 전진
        $this->assertSame(2082000.0, $at('2026-07-16 09:02:00'), '개장 → 기준가가 7/15 정규장 종가로 전진');

        // 옛 오염 경계들 — 전부 소멸해야 한다(개장 후 값 그대로 유지)
        $this->assertSame(2082000.0, $at('2026-07-16 15:31:00'), '15:31(옛 종가확정 경계) → 소멸: 값 불변');
        $this->assertSame(2082000.0, $at('2026-07-16 19:01:00'), '19:01(옛 시간외 경계) → 소멸: 값 불변');
        $this->assertSame(2082000.0, $at('2026-07-16 20:00:00'), '20:00(옛 롤오버 경계) → 소멸: 값 불변');
        $this->assertSame(2082000.0, $at('2026-07-17 00:30:00'), 'KST 자정 → 값 동일(양쪽 다 Yahoo 7/15 종가)');

        // 다음 개장에서 다시 한 칸 전진 = 경계가 09:00 에만 있다는 증거
        $this->assertSame(1850000.0, $at('2026-07-17 09:02:00'), '다음 개장 → 기준가가 7/16 정규장 종가로 전진');
    }
}
