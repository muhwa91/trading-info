<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;

/**
 * TossPriceFetcher 단위 테스트.
 *
 * 검증 대상:
 *   - 국내 종목만 배치 조회 (미국·지수 skip)
 *   - 캐시 키 하위호환 (`kis_realtime_price_{ticker}`, `kis_last_successful_price_{ticker}`)
 *   - 빈 응답 graceful 처리
 *   - 캐시 히트 시 API 호출 없음
 *   - fetchSingle 국내/미국 분기
 */
// 공용 clientMock 을 일부 테스트만 expects 로 검증 — setUp 공용 대역이라 클래스 단위로 허용
#[AllowMockObjectsWithoutExpectations]
class TossPriceFetcherTest extends TestCase
{
    private $clientMock;

    private $calculatorMock;

    private TossPriceFetcher $fetcher;

    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->calculatorMock = $this->createStub(TossChangeCalculator::class);
        $this->mapper = new TossSymbolMapper;

        $this->fetcher = new TossPriceFetcher(
            $this->clientMock,
            $this->mapper,
            $this->calculatorMock
        );

        // US 경로는 calculateUsSplit 로 등락을 계산한다(정규장/장마감엔 calculate() 위임).
        // 기본 스텁(0 등락)을 깔아 US 배치 테스트가 통과하도록 한다 — 개별 테스트는 필요 시 재정의.
        $this->calculatorMock
            ->method('calculateUsSplit')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'regular_change_amount' => null, 'regular_change_percent' => null]);

        // 매 테스트 전 캐시 초기화
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();  // 시간 고정 해제 (TTL 경계 테스트 격리)
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchDomestic — 국내 종목 배치
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_fetch_domestic_empty_input_returns_zero_counts(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic([]);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, $result['cached']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    #[Test]
    public function test_fetch_domestic_index_symbols_skipped(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['NQ=F', 'KOSPI200', '^KS200', 'KOSPI_NIGHT']);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(4, $result['skipped']);
    }

    #[Test]
    public function test_fetch_domestic_us_symbols_included_in_batch_phase4(): void
    {
        // Phase 4: 미국 종목도 배치에 포함돼야 한다 (Phase 3에서는 skipped)
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                    ['symbol' => 'MU',   'lastPrice' => 101.0, 'currency' => 'USD'],
                    ['symbol' => 'SOXL', 'lastPrice' => 30.0,  'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['TSLA', 'MU', 'SOXL']);

        $this->assertSame(3, $result['fetched']);
        $this->assertSame(0, $result['skipped']);
    }

    #[Test]
    public function test_fetch_domestic_cached_ticker_no_api_call(): void
    {
        // 캐시에 이미 값이 있는 경우
        Cache::put('kis_realtime_price_005930', ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['cached']);
        $this->assertSame(0, $result['fetched']);
    }

    #[Test]
    public function test_fetch_domestic_domestic_ticker_calls_api_and_caches(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                return isset($q['symbols']) && str_contains($q['symbols'], '005930');
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['failed']);

        // 캐시 키 하위호환 확인
        $cached = Cache::get('kis_realtime_price_005930');
        $this->assertNotNull($cached);
        $this->assertSame(71000.0, $cached['price']);
        $this->assertSame(500.0, $cached['change_amount']);

        // 폴백 캐시도 저장
        $fallback = Cache::get('kis_last_successful_price_005930');
        $this->assertNotNull($fallback);
        $this->assertSame(71000.0, $fallback['price']);
    }

    #[Test]
    public function test_fetch_domestic_ks_symbol_normalizes_and_caches_with_app_symbol(): void
    {
        // .KS 접미사 포함 앱 심볼로 요청 → 토스엔 005930 으로 보내고 캐시는 원래 앱 심볼 키로
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 100.0, 'change_percent' => 0.1, 'prev_close' => 71000.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71100.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930.KS']);

        $this->assertSame(1, $result['fetched']);

        // 앱 심볼 키로 캐시됨 (하위호환)
        $cached = Cache::get('kis_realtime_price_005930.KS');
        $this->assertNotNull($cached);
        $this->assertSame(71100.0, $cached['price']);
    }

    #[Test]
    public function test_fetch_domestic_empty_api_response_increases_failed(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['fetched']);
        $this->assertNull(Cache::get('kis_realtime_price_005930'));
    }

    #[Test]
    public function test_fetch_domestic_mixed_tickers_kr_and_us_fetched_index_skipped(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 100.0, 'change_percent' => 0.1, 'prev_close' => 71000.0]);

        // Phase 4: KR(005930, 000660) + US(TSLA) 모두 배치 포함, 지수(KOSPI200)만 skip
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                $symbols = $q['symbols'];

                // 지수는 포함되지 않아야 함, KR+US 모두 포함
                return ! str_contains($symbols, 'KOSPI200')
                    && str_contains($symbols, '005930')
                    && str_contains($symbols, '000660')
                    && str_contains($symbols, 'TSLA');
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                    ['symbol' => '000660', 'lastPrice' => 180000.0, 'currency' => 'KRW'],
                    ['symbol' => 'TSLA',   'lastPrice' => 207.5,   'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930', '000660', 'TSLA', 'KOSPI200']);

        $this->assertSame(3, $result['fetched']);  // KR 2 + US 1
        $this->assertSame(1, $result['skipped']);  // KOSPI200 만 skip
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchSingle — 단건 조회
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_fetch_single_index_symbol_returns_null(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('KOSPI200');

        $this->assertNull($result);
    }

    #[Test]
    public function test_fetch_single_us_symbol_delegates_to_overseas_single(): void
    {
        // Phase 4: US 종목은 fetchOverseasSingle 위임 (캐시 히트 시 API 호출 없음)
        $cached = [
            'price' => 207.5,
            'change_amount' => -2.5,
            'change_percent' => -1.2,
            'regular_close' => 210.0,
        ];
        Cache::put('kis_realtime_price_us_TSLA', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('TSLA');

        // Phase 4: US 심볼은 null이 아니라 캐시 값 반환
        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
    }

    #[Test]
    public function test_fetch_single_cached_domestic_returns_cache_without_api_call(): void
    {
        $cached = ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7];
        Cache::put('kis_realtime_price_005930', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertSame(71000.0, $result['price']);
    }

    #[Test]
    public function test_fetch_single_domestic_miss_calls_api_and_returns(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame(71000.0, $result['price']);
        $this->assertSame(500.0, $result['change_amount']);
        $this->assertSame(0.7, $result['change_percent']);
    }

    #[Test]
    public function test_fetch_single_api_fail_with_fallback_cache_returns_fallback(): void
    {
        $fallback = ['price' => 70000.0, 'change_amount' => -500.0, 'change_percent' => -0.7];
        Cache::put('kis_last_successful_price_005930', $fallback, 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);  // 빈 응답 = 실패

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame(70000.0, $result['price']);
    }

    #[Test]
    public function test_fetch_single_api_fail_no_fallback_returns_null(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────────
    // H-2 회귀 가드 — regular_close 가 현재가 핫패스를 블로킹하지 않는다
    // ──────────────────────────────────────────────────────────────────

    /**
     * [정적 가드 — 핵심] fetchDomestic() 의 US 분기가 Yahoo HTTP 동기 페치를
     * 직접 호출하지 않는다. 캐시 읽기(readRegularCloseCache)만 사용해야 한다.
     *
     * 이게 H-2 의 결정적 회귀 가드 — 누군가 다시 fetchYahooRegularClose() /
     * new Client() / Yahoo URL 을 핫패스에 넣으면 즉시 실패한다.
     * (Guzzle new Client() 를 직접 쓰는 코드라 Http::fake 로는 가로챌 수 없어,
     *  소스 정적 검사로 호출 0회를 보장한다 — RefreshYahooCacheMockGuardTest 와 동일 전략.)
     */
    #[Test]
    public function test_fetch_domestic_hot_path_has_no_blocking_yahoo_http(): void
    {
        $src = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($src, 'fetchDomestic');

        $this->assertNotEmpty($methodSrc, 'fetchDomestic 메서드를 찾을 수 없음');

        // 핫패스가 캐시 읽기 헬퍼를 쓰는지 확인 (있어야 함)
        $this->assertStringContainsString(
            'readRegularCloseCache',
            $methodSrc,
            'fetchDomestic 이 readRegularCloseCache(캐시 전용)를 사용하지 않음'
        );

        // 핫패스가 동기 Yahoo HTTP 페치를 호출하지 않는지 확인 (없어야 함)
        $this->assertStringNotContainsString(
            'fetchYahooRegularClose',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)이 fetchYahooRegularClose(블로킹 Yahoo HTTP)를 직접 호출함 — H-2 재발'
        );
        $this->assertStringNotContainsString(
            'new Client',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)이 Guzzle Client 를 직접 생성함 — HTTP 블로킹 위험'
        );
        $this->assertStringNotContainsString(
            'YAHOO_CHART_URL',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)에 Yahoo chart URL 참조가 있음 — HTTP 블로킹 위험'
        );
    }

    /**
     * [동작 가드] cold(캐시 없음) 상태에서 US 종목을 fetchDomestic 하면,
     * 현재가는 즉시 캐시에 써지고 regular_close 는 폴백 캐시 값으로 채워진다.
     * (핫패스에서 Yahoo HTTP 를 호출하지 않으므로 cold 면 폴백/null 이 된다.)
     */
    #[Test]
    public function test_fetch_domestic_us_cold_regular_close_uses_fallback_cache_not_http(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        // yahoo_regular_close_TSLA 는 cold(없음) — 단, 폴백 캐시엔 직전 regular_close 가 있다
        Cache::put('kis_last_successful_overseas_price_TSLA', [
            'price' => 200.0,
            'change_amount' => 0.0,
            'change_percent' => 0.0,
            'regular_close' => 333.0,
        ], 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['TSLA']);

        $this->assertSame(1, $result['fetched']);

        // 현재가는 즉시 써짐
        $cached = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($cached);
        $this->assertSame(207.5, $cached['price']);

        // regular_close 는 폴백 캐시(333.0)에서 채워짐 (HTTP 페치 아님)
        $this->assertSame(333.0, $cached['regular_close']);
    }

    /**
     * [동작 가드] yahoo_regular_close_{symbol} 캐시가 따뜻하면 그 값을 우선 사용한다.
     */
    #[Test]
    public function test_fetch_domestic_us_warm_regular_close_cache_is_included(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        // 워머가 채운 정본 캐시
        Cache::put('yahoo_regular_close_TSLA', 381.61, 3600);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['TSLA']);

        $cached = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($cached);
        $this->assertSame(381.61, $cached['regular_close']);
    }

    /**
     * [동작 가드] cold 이고 폴백도 없으면 regular_close 는 null 로 즉시 써진다(블로킹 없이).
     */
    #[Test]
    public function test_fetch_domestic_us_cold_no_fallback_regular_close_null(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'MU', 'lastPrice' => 101.0, 'currency' => 'USD'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['MU']);

        $cached = Cache::get('kis_realtime_price_us_MU');
        $this->assertNotNull($cached);
        $this->assertSame(101.0, $cached['price']);
        $this->assertNull($cached['regular_close']);
    }

    // ──────────────────────────────────────────────────────────────────
    // warmRegularCloses — out-of-band 워머
    // ──────────────────────────────────────────────────────────────────

    /**
     * 워머는 이미 따뜻한 심볼은 skip 한다 (불필요한 HTTP 회피).
     * 이미 캐시가 있는 US 심볼만 줬을 때, 캐시 값은 그대로 유지된다(덮어쓰지 않음).
     */
    #[Test]
    public function test_warm_regular_closes_warm_symbol_skipped(): void
    {
        Cache::put('yahoo_regular_close_TSLA', 381.61, 3600);

        // HTTP 페치가 발생하면 안 됨 — 따뜻하므로 skip. 값이 변하지 않아야 한다.
        $this->fetcher->warmRegularCloses(['TSLA']);

        $this->assertSame(381.61, (float) Cache::get('yahoo_regular_close_TSLA'));
    }

    /**
     * 워머는 지수·국내(US 외) 심볼을 처리하지 않는다 (HTTP 발생 안 함).
     * Yahoo 접속 없이도 캐시가 채워지지 않음을 검증.
     */
    #[Test]
    public function test_warm_regular_closes_non_us_symbols_ignored(): void
    {
        $this->fetcher->warmRegularCloses(['005930', 'KOSPI200', 'NQ=F']);

        // 어떤 yahoo_regular_close_* 캐시도 만들어지지 않아야 한다
        $this->assertNull(Cache::get('yahoo_regular_close_005930'));
        $this->assertNull(Cache::get('yahoo_regular_close_KOSPI200'));
        $this->assertNull(Cache::get('yahoo_regular_close_NQ=F'));
    }

    // ──────────────────────────────────────────────────────────────────
    // provider 태깅 — 현재가 실제 출처(B-1)
    // ──────────────────────────────────────────────────────────────────

    /**
     * [B-1] fetchDomestic 의 토스 배치 성공 분기는 provider=toss 로 태깅한다 (US·KR 공통).
     */
    #[Test]
    public function test_fetch_domestic_toss_success_tags_provider_toss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA',   'lastPrice' => 207.5,   'currency' => 'USD'],
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['TSLA', '005930']);

        $us = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($us);
        $this->assertSame('toss', $us['provider']);

        $kr = Cache::get('kis_realtime_price_005930');
        $this->assertNotNull($kr);
        $this->assertSame('toss', $kr['provider']);

        // 폴백 캐시에도 provider 가 따라가야 한다(stale 재사용 시 출처 보존)
        $usFallback = Cache::get('kis_last_successful_overseas_price_TSLA');
        $this->assertSame('toss', $usFallback['provider']);
    }

    /**
     * [B-1] fetchOverseasSingle 의 토스 단건 성공 분기는 provider=toss 로 태깅한다.
     */
    #[Test]
    public function test_fetch_overseas_single_toss_success_tags_provider_toss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 1.0, 'change_percent' => 0.5, 'prev_close' => 200.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame('toss', $result['provider']);
        $this->assertSame('toss', Cache::get('kis_realtime_price_us_TSLA')['provider']);
    }

    /**
     * [B-1] fetchSingle 국내 단건 성공 분기는 provider=toss 로 태깅한다.
     */
    #[Test]
    public function test_fetch_single_kr_success_tags_provider_toss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame('toss', $result['provider']);
    }

    /**
     * [B-1] 토스 실패 시 Yahoo 폴백 분기는 provider=yahoo 로 태깅한다.
     *
     * 토스 /prices 가 빈응답 → fetchYahooCurrentPrice 가 폴백.
     * (Yahoo 는 new Client() 직접 호출이라 Http::fake 로 못 막으므로, 실제 네트워크가
     *  없으면 fetchYahooCurrentPrice 가 null 을 반환할 수 있다. 이 머신은 Yahoo 가 되므로
     *  성공 시 provider 검증, 환경상 null 이면 최소한 회귀 없음만 확인한다.)
     */
    #[Test]
    public function test_fetch_overseas_single_toss_fail_yahoo_fallback_tags_provider_yahoo(): void
    {
        // 토스 빈응답 → Yahoo 폴백 경로 진입
        $this->clientMock
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        if ($result !== null && ($result['provider'] ?? null) !== null) {
            // Yahoo 가 응답한 경우: provider 는 반드시 yahoo
            $this->assertSame('yahoo', $result['provider']);
        } else {
            // Yahoo 불통(네트워크 없음) — 폴백 캐시도 없으니 null. 회귀 없음만 확인.
            $this->assertNull($result);
        }
    }

    /**
     * [B-1] 캐시 히트 시 저장된 provider 가 그대로 반환된다(출처 보존).
     */
    #[Test]
    public function test_fetch_overseas_single_cache_hit_preserves_stored_provider(): void
    {
        Cache::put('kis_realtime_price_us_TSLA', [
            'price' => 207.5,
            'change_amount' => 0.0,
            'change_percent' => 0.0,
            'regular_close' => 210.0,
            'provider' => 'yahoo',
        ], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame('yahoo', $result['provider']);
    }

    // ──────────────────────────────────────────────────────────────────
    // regular_close TTL — regularMarketPrice 의 '의미가 바뀌는 다음 경계'에 만료 (2026-07-17)
    //
    //   Yahoo meta.regularMarketPrice 는 세션별로 의미가 다르다: PRE=어제 종가 · REGULAR=라이브가 ·
    //   POST/CLOSED=오늘 확정 종가. 즉 의미가 뒤집히는 시각은 두 곳(09:30 개시 · 16:05 종가확정)이다.
    //   16:05 만 보면 PRE 에 기록한 '어제 종가'가 09:30 을 넘겨 최대 8h 고착한다 — 워머는 Cache::has 면
    //   skip, fetchYahooRegularClose 는 cache-first 라 그 사이 아무도 안 고친다. 파급: DashboardController
    //   의 US 평가(regular_close)가 미국 정규장 내내 어제 종가에 묶인다(아래 회귀 가드 참조).
    //
    //   ★ 이 섹션은 '동작'만 본다. 옛 테스트는 (1) 제품 TTL 산식을 테스트에 복제해 대조하고
    //     (2) 소스 문자열('today 16:05'·max(...,300))을 grep 했다 — 둘 다 이번 결함을 원리적으로 못 잡는다
    //     (미러는 양쪽이 똑같이 틀리고, grep 은 문자열이 바뀌면 그저 실패할 뿐 stale 을 모른다).
    //     이제 ?Client $http 선택 주입 + Carbon 전환으로 marketState 강제 + setTestNow 가 가능하니,
    //     대역을 주입해 '실제로 저장되는 TTL'을 리터럴로 못박는다. ★ 여기에 공식·소스검사를 다시 들이지 말 것.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Yahoo meta 대역이 주입된 TossPriceFetcher.
     *
     * ⚠️ $http 를 주입하지 않으면 기본값이 new Client() 라 테스트가 조용히 실제 Yahoo 를 때린다
     *    (그렇게 '틀린 이유로 통과'한 전례가 있다). 이 경로를 타는 테스트는 반드시 이 헬퍼를 쓴다.
     *
     * @param  string|null  $marketState  Yahoo meta.marketState (null = 필드 누락 = 파싱 실패 재현)
     */
    private function fetcherWithYahooMeta(?string $marketState, float $regularMarketPrice): TossPriceFetcher
    {
        $meta = ['regularMarketPrice' => $regularMarketPrice];
        if ($marketState !== null) {
            $meta['marketState'] = $marketState;
        }

        $body = json_encode(['chart' => ['result' => [['meta' => $meta]]]]);
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));

        return new TossPriceFetcher(
            $this->clientMock,
            $this->mapper,
            $this->calculatorMock,
            new Client(['handler' => $handler])
        );
    }

    /**
     * 워머(cold 심볼 → Yahoo 페치 → Cache::put)를 태워 실제로 저장된 TTL 을 잡아낸다.
     *
     * ⚠️ 테스트당 1회만 호출한다 — Mockery 는 먼저 등록된 expectation 을 먼저 매칭하므로,
     *    한 테스트에서 두 번 부르면 두 번째 put 이 첫 번째 클로저(이미 죽은 지역변수)에 잡힌다(실측).
     *
     * @param  string  $frozenEt  ET 고정 시각 — 제품이 Carbon::now('America/New_York') 를 쓰므로 setTestNow 가 먹는다
     */
    private function captureRegularCloseTtl(string $frozenEt, ?string $marketState, float $price = 900.0): ?int
    {
        Carbon::setTestNow(Carbon::parse($frozenEt, 'America/New_York'));

        $capturedTtl = null;
        Cache::shouldReceive('has')->andReturnFalse();   // 워머 게이트: cold → 페치
        Cache::shouldReceive('get')->andReturnNull();    // cache-first 게이트: cold → 페치
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

        $this->fetcherWithYahooMeta($marketState, $price)->warmRegularCloses(['MU']);

        return $capturedTtl;
    }

    /**
     * PRE(05:00 ET) 기록분은 09:30(정규장 개시) 정각에 만료 — regularMarketPrice 가 어제 종가→라이브가로
     * 뒤집히는 시각이다. 옛 구현은 오늘 16:05 까지(최대 8h) 잡아 정규장 내내 어제 종가를 서빙했다.
     */
    #[Test]
    public function test_regular_close_ttl_pre_market_expires_at_regular_open(): void
    {
        // 05:00 ET → 09:30 ET = 4h30m. (옛 '오늘 16:05' 기준이면 11h05m = 39900)
        $this->assertSame(16200, $this->captureRegularCloseTtl('2026-07-17 05:00:00', 'PRE'),
            'PRE 05:00 ET → 09:30 ET 정각 만료(4h30m)');
    }

    /**
     * PRE 09:29 ET → 60초. 300초 하한이 남아 있으면 09:34 까지 살아남아 개장 경계를 4분 넘긴다.
     */
    #[Test]
    public function test_regular_close_ttl_pre_market_just_before_open_has_no_floor(): void
    {
        $this->assertSame(60, $this->captureRegularCloseTtl('2026-07-17 09:29:00', 'PRE'),
            'PRE 09:29 ET → 60s. 300 하한이 살아있으면 개장을 넘겨 stale');
    }

    /**
     * POST(17:19 ET) 기록분 = 오늘 확정 종가 → 다음 경계는 익일 09:30(16:05 는 이미 지났다).
     */
    #[Test]
    public function test_regular_close_ttl_post_market_expires_at_next_regular_open(): void
    {
        // 17:19 ET → 익일 09:30 = 16h11m
        $this->assertSame(58260, $this->captureRegularCloseTtl('2026-07-17 17:19:00', 'POST'),
            'POST 17:19 ET → 익일 09:30 ET(16h11m)');
    }

    /**
     * CLOSED(22:00 ET)도 동일 — 익일 09:30 만료.
     */
    #[Test]
    public function test_regular_close_ttl_closed_expires_at_next_regular_open(): void
    {
        // 22:00 ET → 익일 09:30 = 11h30m
        $this->assertSame(41400, $this->captureRegularCloseTtl('2026-07-17 22:00:00', 'CLOSED'),
            'CLOSED 22:00 ET → 익일 09:30 ET(11h30m)');
    }

    /**
     * REGULAR(14:20 ET) = 미완성 진행가 → 300초만(무변경).
     * 09:30/16:05 경계 로직이 정규장 분기까지 먹어치우면(예: 16:05 까지 = 6300s) 여기서 잡힌다.
     */
    #[Test]
    public function test_regular_close_ttl_regular_session_stays_short(): void
    {
        $this->assertSame(300, $this->captureRegularCloseTtl('2026-07-17 14:20:00', 'REGULAR'),
            'REGULAR = 진행가 → 300s 고정');
    }

    /**
     * marketState 누락(파싱 실패)도 보수적으로 300초 — 긴 TTL 로 잘못된 값을 박는 것보다 안전.
     */
    #[Test]
    public function test_regular_close_ttl_null_state_stays_short(): void
    {
        $this->assertSame(300, $this->captureRegularCloseTtl('2026-07-17 14:20:00', null),
            'marketState 누락 → 보수적으로 300s');
    }

    /**
     * [회귀 가드 — 파급 A] PRE 에 채운 '어제 종가'가 09:30 개장을 넘겨 살아남지 않는다.
     *
     * DashboardController:215-219 는 US 보유의 평가가격으로 regular_close 를 읽는다
     * (readRegularCloseCache → yahoo_regular_close_{ticker}). PRE 엔트리가 개장을 넘기면
     * 프리마켓에 앱을 켠 사용자의 포트폴리오 평가손익이 미국 정규장 내내(KST 22:30~05:05)
     * 어제 종가에 고정된다 — TTL 단언과 달리 이 테스트는 '실제 캐시 만료 + 소비 경로'까지 태운다.
     *
     * (ArrayStore 만료·TTL 계산 모두 Carbon::now(=setTestNow) 기준이라 실행 시각과 무관하다.)
     */
    #[Test]
    public function test_regular_close_cache_pre_entry_does_not_survive_into_regular_session(): void
    {
        // ① ET 05:00 프리마켓 — 워머가 regularMarketPrice(=어제 종가 900.0)를 캐싱
        Carbon::setTestNow(Carbon::parse('2026-07-17 05:00:00', 'America/New_York'));
        $fetcher = $this->fetcherWithYahooMeta('PRE', 900.0);
        $fetcher->warmRegularCloses(['MU']);
        $this->assertSame(900.0, (float) Cache::get('yahoo_regular_close_MU'), '전제: PRE 워밍이 어제 종가를 캐싱');

        // ② ET 09:31 정규장 — 900.0 은 더 이상 '오늘 정규장 기준가'가 아니다 → 만료돼 있어야 한다
        Carbon::setTestNow(Carbon::parse('2026-07-17 09:31:00', 'America/New_York'));
        $this->assertNull(Cache::get('yahoo_regular_close_MU'),
            'PRE 캐시가 09:30 개장을 넘겨 살아남았다 — 워머는 Cache::has 면 skip 이라 아무도 안 고친다');

        // ③ 파급 A: 그 결과 DashboardController 가 읽는 regular_close 가 어제 종가(900.0)면 안 된다.
        $this->clientMock->method('get')->willReturn([
            'result' => [['symbol' => 'MU', 'lastPrice' => 950.0, 'currency' => 'USD']],
        ]);
        $fetcher->fetchDomestic(['MU']);

        $priceData = Cache::get('kis_realtime_price_us_MU');
        $this->assertNotNull($priceData);
        $this->assertNotSame(900.0, $priceData['regular_close'],
            'US 평가가격(regular_close)이 어제 종가에 고착 — 프리마켓에 앱을 켜면 정규장 내내 평가손익이 얼어붙는다');
        $this->assertNull($priceData['regular_close'], '만료 후 cold → null(다음 워머 사이클이 라이브가로 채운다)');
    }

    // ──────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────

    private function getFetcherSource(): string
    {
        $path = __DIR__ . '/../../app/Services/Toss/TossPriceFetcher.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'TossPriceFetcher.php 읽기 실패');

        return (string) $src;
    }

    /**
     * 소스 파일에서 특정 메서드 블록을 중괄호 카운팅으로 추출한다.
     */
    private function extractMethodSource(string $source, string $methodName): string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($source, '{', $pos);
        if ($openPos === false) {
            return '';
        }

        $depth = 0;
        $end = $openPos;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($source, $pos, $end - $pos + 1);
    }
}
