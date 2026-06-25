<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
class TossPriceFetcherTest extends TestCase
{
    private $clientMock;
    private $calculatorMock;
    private TossPriceFetcher $fetcher;
    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock     = $this->createMock(TossApiClient::class);
        $this->calculatorMock = $this->createMock(TossChangeCalculator::class);
        $this->mapper         = new TossSymbolMapper();

        $this->fetcher = new TossPriceFetcher(
            $this->clientMock,
            $this->mapper,
            $this->calculatorMock
        );

        // 매 테스트 전 캐시 초기화
        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchDomestic — 국내 종목 배치
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchDomestic_EmptyInput_ReturnsZeroCounts(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic([]);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, $result['cached']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    /** @test */
    public function testFetchDomestic_IndexSymbols_Skipped(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['NQ=F', 'KOSPI200', '^KS200', 'KOSPI_NIGHT']);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(4, $result['skipped']);
    }

    /** @test */
    public function testFetchDomestic_UsSymbols_IncludedInBatchPhase4(): void
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

    /** @test */
    public function testFetchDomestic_CachedTicker_NoApiCall(): void
    {
        // 캐시에 이미 값이 있는 경우
        Cache::put('kis_realtime_price_005930', ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['cached']);
        $this->assertSame(0, $result['fetched']);
    }

    /** @test */
    public function testFetchDomestic_DomesticTicker_CallsApiAndCaches(): void
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

    /** @test */
    public function testFetchDomestic_KsSymbol_NormalizesAndCachesWithAppSymbol(): void
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

    /** @test */
    public function testFetchDomestic_EmptyApiResponse_IncreasesFailed(): void
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

    /** @test */
    public function testFetchDomestic_MixedTickers_KrAndUsFetchedIndexSkipped(): void
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
                return !str_contains($symbols, 'KOSPI200')
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

    /** @test */
    public function testFetchSingle_IndexSymbol_ReturnsNull(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('KOSPI200');

        $this->assertNull($result);
    }

    /** @test */
    public function testFetchSingle_UsSymbol_DelegatesToOverseasSingle(): void
    {
        // Phase 4: US 종목은 fetchOverseasSingle 위임 (캐시 히트 시 API 호출 없음)
        $cached = [
            'price'          => 207.5,
            'change_amount'  => -2.5,
            'change_percent' => -1.2,
            'regular_close'  => 210.0,
        ];
        Cache::put('kis_realtime_price_us_TSLA', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('TSLA');

        // Phase 4: US 심볼은 null이 아니라 캐시 값 반환
        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
    }

    /** @test */
    public function testFetchSingle_CachedDomestic_ReturnsCacheWithoutApiCall(): void
    {
        $cached = ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7];
        Cache::put('kis_realtime_price_005930', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertSame(71000.0, $result['price']);
    }

    /** @test */
    public function testFetchSingle_DomesticMiss_CallsApiAndReturns(): void
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

    /** @test */
    public function testFetchSingle_ApiFailWithFallbackCache_ReturnsFallback(): void
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

    /** @test */
    public function testFetchSingle_ApiFailNoFallback_ReturnsNull(): void
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
     *
     * @test
     */
    public function testFetchDomestic_HotPath_HasNoBlockingYahooHttp(): void
    {
        $src       = $this->getFetcherSource();
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
     *
     * @test
     */
    public function testFetchDomestic_UsColdRegularClose_UsesFallbackCacheNotHttp(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        // yahoo_regular_close_TSLA 는 cold(없음) — 단, 폴백 캐시엔 직전 regular_close 가 있다
        Cache::put('kis_last_successful_overseas_price_TSLA', [
            'price'          => 200.0,
            'change_amount'  => 0.0,
            'change_percent' => 0.0,
            'regular_close'  => 333.0,
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
     *
     * @test
     */
    public function testFetchDomestic_UsWarmRegularCloseCache_IsIncluded(): void
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
     *
     * @test
     */
    public function testFetchDomestic_UsColdNoFallback_RegularCloseNull(): void
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
     *
     * @test
     */
    public function testWarmRegularCloses_WarmSymbol_Skipped(): void
    {
        Cache::put('yahoo_regular_close_TSLA', 381.61, 3600);

        // HTTP 페치가 발생하면 안 됨 — 따뜻하므로 skip. 값이 변하지 않아야 한다.
        $this->fetcher->warmRegularCloses(['TSLA']);

        $this->assertSame(381.61, (float) Cache::get('yahoo_regular_close_TSLA'));
    }

    /**
     * 워머는 지수·국내(US 외) 심볼을 처리하지 않는다 (HTTP 발생 안 함).
     * Yahoo 접속 없이도 캐시가 채워지지 않음을 검증.
     *
     * @test
     */
    public function testWarmRegularCloses_NonUsSymbols_Ignored(): void
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
     *
     * @test
     */
    public function testFetchDomestic_TossSuccess_TagsProviderToss(): void
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
     *
     * @test
     */
    public function testFetchOverseasSingle_TossSuccess_TagsProviderToss(): void
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
     *
     * @test
     */
    public function testFetchSingle_KrSuccess_TagsProviderToss(): void
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
     *
     * @test
     */
    public function testFetchOverseasSingle_TossFail_YahooFallbackTagsProviderYahoo(): void
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
     *
     * @test
     */
    public function testFetchOverseasSingle_CacheHit_PreservesStoredProvider(): void
    {
        Cache::put('kis_realtime_price_us_TSLA', [
            'price'          => 207.5,
            'change_amount'  => 0.0,
            'change_percent' => 0.0,
            'regular_close'  => 210.0,
            'provider'       => 'yahoo',
        ], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame('yahoo', $result['provider']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────

    private function getFetcherSource(): string
    {
        $path = __DIR__ . '/../../app/Services/Toss/TossPriceFetcher.php';
        $src  = file_get_contents($path);
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

        $depth  = 0;
        $end    = $openPos;
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
