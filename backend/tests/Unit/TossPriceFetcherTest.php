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
}
