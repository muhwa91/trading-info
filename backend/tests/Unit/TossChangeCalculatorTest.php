<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use Illuminate\Support\Facades\Cache;
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
class TossChangeCalculatorTest extends TestCase
{
    private $clientMock;
    private TossChangeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->calculator = new TossChangeCalculator($this->clientMock);

        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // calculate() — 등락 계산
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testCalculate_PositiveChange(): void
    {
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

        // prevClose = 70500 (index 1 = 오래된 봉), change = 71500 - 70500 = 1000
        $this->assertSame(1000.0, $result['change_amount']);
        $this->assertGreaterThan(0, $result['change_percent']);
        $this->assertSame(70500.0, $result['prev_close']);
    }

    /** @test */
    public function testCalculate_NegativeChange(): void
    {
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

        // prevClose = 71000 (index 1), change = 70000 - 71000 = -1000
        $this->assertSame(-1000.0, $result['change_amount']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    /** @test */
    public function testCalculate_NoPrevClose_ReturnsZero(): void
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

    /** @test */
    public function testGetPrevClose_CacheHit_NoApiCall(): void
    {
        Cache::put('toss_prev_close_005930', 70500.0, 3600);

        $this->clientMock->expects($this->never())->method('get');

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertSame(70500.0, $prevClose);
    }

    /** @test */
    public function testGetPrevClose_CacheMiss_CallsApiAndCaches(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/candles', $this->callback(function (array $q): bool {
                return $q['symbol'] === '005930'
                    && $q['interval'] === '1d'
                    && $q['count'] === 2;
            }))
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // index 1 (오래된 봉)의 closePrice = 70500
        $this->assertSame(70500.0, $prevClose);

        // 두 번째 호출은 캐시 히트 → API 미호출
        $prevClose2 = $this->calculator->getPrevClose('005930');
        $this->assertSame(70500.0, $prevClose2);
    }

    /** @test */
    public function testGetPrevClose_OnlyOneCandle_ReturnsNull(): void
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

    /** @test */
    public function testGetPrevClose_ReverseOrderCandles_CorrectlyPicksOldest(): void
    {
        // 실측과 동일: 최신 먼저 (index 0 = 최신, index 1 = 전일)
        // 정렬 후 index 0 = 최신, index 1 = 전일 → prevClose = index 1 closePrice
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // 정렬 후 index 1(오래된 봉) closePrice = 70500 이 prevClose
        $this->assertSame(70500.0, $prevClose);
    }

    /** @test */
    public function testGetPrevClose_EmptyApiResponse_ReturnsNull(): void
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

    /** @test */
    public function testCalculate_ChangePercentPrecision(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        // 현재가 71500, prevClose 71000 → change_percent = 500/71000*100 ≈ 0.7042
        $result = $this->calculator->calculate('005930', 71500.0);

        $expected = round((71500.0 - 71000.0) / 71000.0 * 100.0, 4);
        $this->assertSame($expected, $result['change_percent']);
    }

    /** @test */
    public function testCalculate_ZeroChange_WhenCurrentEqualsClose(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        $result = $this->calculator->calculate('005930', 71000.0);

        $this->assertSame(0.0, $result['change_amount']);
        $this->assertSame(0.0, $result['change_percent']);
    }
}
