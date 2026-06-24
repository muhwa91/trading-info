<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossCandleProvider;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TossCandleProvider 단위 테스트.
 */
class TossCandleProviderTest extends TestCase
{
    private $clientMock;
    private $mapperMock;
    private $changeMock;
    private TossCandleProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->mapperMock = $this->createMock(TossSymbolMapper::class);
        $this->changeMock = $this->createMock(TossChangeCalculator::class);

        $this->provider = new TossCandleProvider(
            $this->clientMock,
            $this->mapperMock,
            $this->changeMock
        );

        Cache::flush();
    }

    // ─── 지수 skip ───────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_Index_ReturnsNull(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(true);
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->provider->getChartData('NQ=F', '1m'));
        $this->assertNull($this->provider->getChartData('KOSPI200', '1d'));
        $this->assertNull($this->provider->getChartData('KOSPI_NIGHT', '1m'));
    }

    // ─── 일봉 파싱 ───────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_Daily_ParsesCorrectly(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('005930');

        $this->clientMock->method('get')->willReturn([
            'result' => [
                'candles' => [
                    [
                        'timestamp'  => '2026-06-24T00:00:00.000+09:00',
                        'openPrice'  => '75000',
                        'highPrice'  => '76000',
                        'lowPrice'   => '74000',
                        'closePrice' => '75500',
                        'volume'     => '1000',
                    ],
                    [
                        'timestamp'  => '2026-06-23T00:00:00.000+09:00',
                        'openPrice'  => '74000',
                        'highPrice'  => '75000',
                        'lowPrice'   => '73500',
                        'closePrice' => '74800',
                        'volume'     => '900',
                    ],
                ],
                'nextBefore' => null,
            ],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount'  => 700.0,
            'change_percent' => 0.9358,
            'prev_close'     => 74800.0,
        ]);

        $result = $this->provider->getChartData('005930.KS', '1d');

        $this->assertNotNull($result);
        $this->assertSame('005930.KS', $result['ticker']);
        // 일봉 time = 'Y-m-d' 문자열
        $this->assertIsString($result['candles'][0]['time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['candles'][0]['time']);
        // OHLCV float 변환
        $this->assertIsFloat($result['candles'][0]['open']);
        $this->assertIsFloat($result['candles'][0]['close']);
        $this->assertIsInt($result['candles'][0]['volume']);
    }

    // ─── 분봉 파싱 ───────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_Minute_ParsesCorrectly(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('005930');

        $this->clientMock->method('get')->willReturn([
            'result' => [
                'candles' => [
                    [
                        'timestamp'  => '2026-06-24T10:31:00.000+09:00',
                        'openPrice'  => '75000',
                        'highPrice'  => '75100',
                        'lowPrice'   => '74900',
                        'closePrice' => '75050',
                        'volume'     => '200',
                    ],
                ],
                'nextBefore' => null,
            ],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('005930.KS', '1m');

        $this->assertNotNull($result);
        // 분봉 time = Unix timestamp (int)
        $this->assertIsInt($result['candles'][0]['time']);
        $this->assertGreaterThan(0, $result['candles'][0]['time']);
    }

    // ─── 5m 집계 ────────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_AggregateCandles_5m(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('TSLA');

        // 1분봉 6개: 10:00~10:05 (300초 버킷 = 10:00, 다음 버킷 = 10:05)
        $base = mktime(10, 0, 0, 6, 24, 2026);
        $candles1m = [];
        for ($i = 0; $i < 6; $i++) {
            $candles1m[] = [
                'timestamp'  => date('c', $base + $i * 60),
                'openPrice'  => (string)(100 + $i),
                'highPrice'  => (string)(110 + $i),
                'lowPrice'   => (string)(90 + $i),
                'closePrice' => (string)(105 + $i),
                'volume'     => '100',
            ];
        }

        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => $candles1m, 'nextBefore' => null],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('TSLA', '5m');

        $this->assertNotNull($result);
        // 6개 1m봉 → 300초 버킷으로 집계 → 2개 버킷
        $this->assertCount(2, $result['candles']);

        $bucket0 = $result['candles'][0];
        // open = 첫 봉 open = 100
        $this->assertSame(100.0, $bucket0['open']);
        // high = max(110,111,112,113,114) first bucket(0~4 if base%300==0 → first 5 in bucket0)
        // volume = sum
    }

    // ─── OHLCV 집계 규칙 상세 ────────────────────────────────────────

    /** @test */
    public function testAggregateCandles_OHLCV_Rules(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('TSLA');

        // 정확히 1개 버킷에 들어가는 3개 봉 (300초 = 5분 버킷)
        $bucketStart = mktime(10, 0, 0, 6, 24, 2026);
        // 버킷 시작이 300의 배수인지 보장
        $bucketStart = $bucketStart - ($bucketStart % 300);

        $candles1m = [
            [
                'timestamp'  => date('c', $bucketStart),
                'openPrice'  => '100',
                'highPrice'  => '115',
                'lowPrice'   => '95',
                'closePrice' => '108',
                'volume'     => '300',
            ],
            [
                'timestamp'  => date('c', $bucketStart + 60),
                'openPrice'  => '108',
                'highPrice'  => '120',  // max high
                'lowPrice'   => '90',   // min low
                'closePrice' => '112',
                'volume'     => '200',
            ],
            [
                'timestamp'  => date('c', $bucketStart + 120),
                'openPrice'  => '112',
                'highPrice'  => '118',
                'lowPrice'   => '105',
                'closePrice' => '116',  // last close
                'volume'     => '400',
            ],
        ];

        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => $candles1m, 'nextBefore' => null],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('TSLA', '5m');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['candles']);

        $bucket = $result['candles'][0];
        $this->assertSame(100.0,  $bucket['open']);    // 첫 봉 open
        $this->assertSame(120.0,  $bucket['high']);    // max high
        $this->assertSame(90.0,   $bucket['low']);     // min low
        $this->assertSame(116.0,  $bucket['close']);   // 마지막 봉 close
        $this->assertSame(900,    $bucket['volume']);  // sum
    }

    // ─── 페이지네이션 ─────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_Pagination(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('005930');

        $makeCandle = function (int $offset): array {
            return [
                'timestamp'  => date('c', mktime(10, 0, 0, 6, 24, 2026) + $offset * 60),
                'openPrice'  => '75000',
                'highPrice'  => '75100',
                'lowPrice'   => '74900',
                'closePrice' => '75050',
                'volume'     => '100',
            ];
        };

        // 1차: 2봉 + nextBefore 있음, 2차: 1봉 + nextBefore 없음
        $page1Candles = [$makeCandle(2), $makeCandle(1)];
        $page2Candles = [$makeCandle(0)];

        $callCount = 0;
        $this->clientMock->method('get')->willReturnCallback(
            function () use (&$callCount, $page1Candles, $page2Candles): array {
                $callCount++;
                if ($callCount === 1) {
                    return ['result' => ['candles' => $page1Candles, 'nextBefore' => '2026-06-24T09:00:00.000+09:00']];
                }
                return ['result' => ['candles' => $page2Candles, 'nextBefore' => null]];
            }
        );

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('005930.KS', '1m');

        $this->assertNotNull($result);
        $this->assertSame(2, $callCount); // API 2회 호출
        $this->assertCount(3, $result['candles']); // 합산 3봉
    }

    // ─── 빈 응답 ─────────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_EmptyResponse_ReturnsNull(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('005930');

        $this->clientMock->method('get')->willReturn([]);

        $result = $this->provider->getChartData('005930.KS', '1d');

        $this->assertNull($result);
    }

    // ─── 봉 0개 ──────────────────────────────────────────────────────

    /** @test */
    public function testGetChartData_InsufficientCandles_ReturnsNull(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock->method('toTossSymbol')->willReturn('005930');

        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => [], 'nextBefore' => null],
        ]);

        $result = $this->provider->getChartData('005930.KS', '1d');

        $this->assertNull($result);
    }

    // ─── 국내 심볼 toTossSymbol 호출 확인 ────────────────────────────

    /** @test */
    public function testGetChartData_DomesticSymbol_MapsCorrectly(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock
            ->expects($this->atLeastOnce())
            ->method('toTossSymbol')
            ->with('005930.KS')
            ->willReturn('005930');

        $this->clientMock->method('get')->willReturn([
            'result' => [
                'candles' => [
                    [
                        'timestamp'  => '2026-06-24T00:00:00.000+09:00',
                        'openPrice'  => '75000',
                        'highPrice'  => '76000',
                        'lowPrice'   => '74000',
                        'closePrice' => '75500',
                        'volume'     => '1000',
                    ],
                ],
                'nextBefore' => null,
            ],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('005930.KS', '1d');
        $this->assertNotNull($result);
    }

    // ─── 미국 심볼 toTossSymbol 호출 확인 ────────────────────────────

    /** @test */
    public function testGetChartData_UsSymbol_MapsCorrectly(): void
    {
        $this->mapperMock->method('shouldSkip')->willReturn(false);
        $this->mapperMock
            ->expects($this->atLeastOnce())
            ->method('toTossSymbol')
            ->with('TSLA')
            ->willReturn('TSLA');

        $this->clientMock->method('get')->willReturn([
            'result' => [
                'candles' => [
                    [
                        'timestamp'  => '2026-06-24T10:30:00.000+09:00',
                        'openPrice'  => '310000',
                        'highPrice'  => '311000',
                        'lowPrice'   => '309000',
                        'closePrice' => '310500',
                        'volume'     => '12345',
                    ],
                ],
                'nextBefore' => null,
            ],
        ]);

        $this->changeMock->method('calculate')->willReturn([
            'change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => null,
        ]);

        $result = $this->provider->getChartData('TSLA', '1m');
        $this->assertNotNull($result);
        $this->assertSame('TSLA', $result['ticker']);
    }
}
