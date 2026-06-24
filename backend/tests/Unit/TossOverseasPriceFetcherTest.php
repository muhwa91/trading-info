<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TossPriceFetcher Phase 4 — 미국 종목 현재가 전환 단위 테스트.
 *
 * 검증 대상:
 *   - fetchDomestic: US 종목도 배치 포함 (Phase 4)
 *   - fetchDomestic: KR/US 캐시 키 분리 하위호환
 *   - fetchOverseasSingle: 토스 성공 경로
 *   - fetchOverseasSingle: 토스 실패 → 24h 폴백 반환
 *   - fetchSingle: US 종목은 fetchOverseasSingle 위임
 */
class TossOverseasPriceFetcherTest extends TestCase
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

        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchDomestic — 미국 종목 포함 배치
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchDomestic_UsSymbols_NowIncludedInBatch(): void
    {
        // Phase 4: US 종목도 배치에 포함돼야 한다 (Phase 3: skipped)
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => -2.5, 'change_percent' => -1.2, 'prev_close' => 210.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                return isset($q['symbols']) && str_contains($q['symbols'], 'TSLA');
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['TSLA']);

        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    /** @test */
    public function testFetchDomestic_UsSymbol_UsesCorrectCacheKeys(): void
    {
        // US 캐시 키는 `kis_realtime_price_us_{ticker}` 여야 한다
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 1.0, 'change_percent' => 0.5, 'prev_close' => 100.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'MU', 'lastPrice' => 101.0, 'currency' => 'USD'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['MU']);

        // US 캐시 키 확인
        $usCache = Cache::get('kis_realtime_price_us_MU');
        $this->assertNotNull($usCache, 'US 캐시 키 kis_realtime_price_us_MU 가 존재해야 함');
        $this->assertSame(101.0, $usCache['price']);

        // Phase 4: US 캐시에 regular_close 키가 포함돼야 한다
        // (Yahoo 네트워크 없는 테스트 환경에선 null 허용, 키 존재 여부만 검증)
        $this->assertArrayHasKey('regular_close', $usCache, 'fetchDomestic US 결과에 regular_close 키가 있어야 함');

        // US 폴백 캐시 키 확인
        $usFallback = Cache::get('kis_last_successful_overseas_price_MU');
        $this->assertNotNull($usFallback, 'US 폴백 캐시 키가 존재해야 함');
        $this->assertSame(101.0, $usFallback['price']);
        $this->assertArrayHasKey('regular_close', $usFallback, 'US 폴백 캐시에도 regular_close 키가 있어야 함');

        // KR 캐시 키는 존재하지 않아야 함
        $this->assertNull(Cache::get('kis_realtime_price_MU'), 'KR 캐시 키가 존재하면 안 됨');
    }

    /** @test */
    public function testFetchDomestic_KrSymbol_UsesKrCacheKeys(): void
    {
        // KR 캐시 키는 `kis_realtime_price_{ticker}` 여야 한다
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['005930']);

        $krCache = Cache::get('kis_realtime_price_005930');
        $this->assertNotNull($krCache);
        $this->assertSame(71000.0, $krCache['price']);

        // US 캐시 키는 존재하지 않아야 함
        $this->assertNull(Cache::get('kis_realtime_price_us_005930'), 'KR 종목에 US 캐시 키가 생기면 안 됨');
    }

    /** @test */
    public function testFetchDomestic_MixedKrAndUs_BothFetched(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                $symbols = $q['symbols'];
                return str_contains($symbols, '005930')
                    && str_contains($symbols, 'AAPL')
                    && !str_contains($symbols, 'KOSPI200'); // 지수 제외
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                    ['symbol' => 'AAPL',   'lastPrice' => 195.0,   'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930', 'AAPL', 'KOSPI200']);

        $this->assertSame(2, $result['fetched']);
        $this->assertSame(1, $result['skipped']);  // KOSPI200
    }

    /** @test */
    public function testFetchDomestic_UsCachedTicker_NoApiCall(): void
    {
        // US 캐시 히트 시 API 호출 없어야 함
        Cache::put('kis_realtime_price_us_TSLA', ['price' => 207.0, 'change_amount' => -1.0, 'change_percent' => -0.5], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['TSLA']);

        $this->assertSame(1, $result['cached']);
        $this->assertSame(0, $result['fetched']);
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchOverseasSingle — 미국 단건 조회
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchOverseasSingle_IndexSymbol_ReturnsNull(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('NQ=F');

        $this->assertNull($result);
    }

    /** @test */
    public function testFetchOverseasSingle_KrSymbol_ReturnsNull(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('005930');

        $this->assertNull($result);
    }

    /** @test */
    public function testFetchOverseasSingle_CacheHit_ReturnsWithoutApiCall(): void
    {
        $cached = [
            'price'          => 207.5,
            'change_amount'  => -2.5,
            'change_percent' => -1.2,
            'regular_close'  => 210.0,
        ];
        Cache::put('kis_realtime_price_us_TSLA', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
    }

    /** @test */
    public function testFetchOverseasSingle_TossSuccess_CachesAndReturns(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => -2.5, 'change_percent' => -1.2, 'prev_close' => 210.0]);

        // Toss API 응답 (regular_close Yahoo 조회는 내부적으로 시도하나 네트워크 없으므로 null 반환)
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', ['symbols' => 'TSLA'])
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
        $this->assertSame(-2.5, $result['change_amount']);
        $this->assertArrayHasKey('regular_close', $result);

        // primary 캐시 저장 확인
        $primaryCache = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($primaryCache);
        $this->assertSame(207.5, $primaryCache['price']);
    }

    /** @test */
    public function testFetchOverseasSingle_TossEmptyResponse_AttemptsFallback(): void
    {
        // 토스 실패 시 Yahoo 폴백 또는 24h 캐시를 시도해야 한다.
        // Yahoo가 실제 네트워크로 응답하거나 24h 캐시를 사용 — 어느 쪽이든 non-null 이면 성공.
        // 이 테스트는 "토스 실패 후 null을 반환하지 않는다"는 동작을 검증한다.
        $fallback = [
            'price'          => 200.0,
            'change_amount'  => -5.0,
            'change_percent' => -2.4,
            'regular_close'  => 205.0,
        ];
        Cache::put('kis_last_successful_overseas_price_TSLA', $fallback, 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);  // 빈 응답 = 토스 실패

        // Yahoo 또는 24h 캐시 중 하나라도 반환하면 non-null
        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result, '토스 실패 후 Yahoo 또는 24h 캐시를 반환해야 함');
        $this->assertArrayHasKey('price', $result);
        $this->assertGreaterThan(0, $result['price']);
    }

    /** @test */
    public function testFetchOverseasSingle_TossEmptyResponse_24hCacheUsedWhenYahooFails(): void
    {
        // 존재하지 않는 심볼로 Yahoo 실패를 유도, 24h 캐시로 폴백 확인
        // 실제 존재하지 않는 심볼을 사용 — Yahoo가 빈 응답이나 에러를 반환
        $fallback = [
            'price'          => 123.45,
            'change_amount'  => 0.5,
            'change_percent' => 0.4,
            'regular_close'  => 123.0,
        ];
        // 존재하지 않을 법한 심볼로 Yahoo 실패 유도
        $fakeSymbol = 'ZZZZINVALID99999';
        Cache::put("kis_last_successful_overseas_price_{$fakeSymbol}", $fallback, 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);  // 토스 실패

        $result = $this->fetcher->fetchOverseasSingle($fakeSymbol);

        // Yahoo 실패 + 24h 캐시 존재 → 24h 캐시 반환
        $this->assertNotNull($result, 'Yahoo 실패 후 24h 캐시를 반환해야 함');
        $this->assertSame(123.45, $result['price']);
    }

    /** @test */
    public function testFetchOverseasSingle_NoFallbackCache_YahooFailure_ReturnsNull(): void
    {
        // 존재하지 않는 심볼 + 폴백 캐시 없음 = null 반환
        $fakeSymbol = 'ZZZNOTEXIST12345';
        // 캐시 비워진 상태 확인
        Cache::forget("kis_last_successful_overseas_price_{$fakeSymbol}");

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);  // 토스 실패

        $result = $this->fetcher->fetchOverseasSingle($fakeSymbol);

        // Yahoo도 실패(존재하지 않는 심볼), 24h 캐시도 없음 → null
        $this->assertNull($result, '토스 실패 + Yahoo 실패 + 캐시 없음 = null 반환해야 함');
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchSingle — US 위임 확인
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchSingle_UsSymbol_DelegatesToFetchOverseasSingle(): void
    {
        // fetchSingle('TSLA') 가 fetchOverseasSingle 로 위임돼야 함
        // 캐시에 US 값을 넣으면 API 호출 없이 반환 확인
        $cached = [
            'price'          => 207.5,
            'change_amount'  => -2.5,
            'change_percent' => -1.2,
            'regular_close'  => 210.0,
        ];
        Cache::put('kis_realtime_price_us_TSLA', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
    }

    /** @test */
    public function testFetchSingle_KrSymbol_UsesKrPath(): void
    {
        // KR 종목은 기존 KR 경로 사용 (캐시 키: kis_realtime_price_{ticker})
        Cache::put('kis_realtime_price_005930', ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame(71000.0, $result['price']);
    }
}
