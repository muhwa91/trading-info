<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\StockController;
use App\Services\Toss\TossStockMaster;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 지수 기준가 — 두 경로가 같은 Yahoo 응답에서 같은 기준가를 내는지 «값으로» 잠근다 (2026-10-05)
 *
 * 지수 가격 경로는 둘이다: `/api/indices`(parseYahooFinanceChart) · `/api/stocks/{지수}?timeframe=1d`(getYahooChartData).
 * 7/30 에 앞의 것만 고쳐 8/04 에 뒤의 것이 7/31 종가 +17.91% 를 냈다(함정 §5 · 작업일지 2026-08-04).
 * 그 뒤 1d 경로는 **소스 정규식 검사**(IndexFutureChangePercentTest)로만 지켜져 왔다 — 리팩터링에 깨지고,
 * 값이 틀려도 통과한다. 여기서는 같은 응답을 두 경로에 흘려 기준가·등락을 직접 비교한다.
 *
 * 시나리오(실측 픽스처):
 *   - 오늘 봉 null(^KS11 7/29) — 기준가 = 7/28 종가, 「뒤에서 두 번째」로 되돌리면 7/27 이 잡힌다
 *   - 오늘 봉 진행 중(NQ=F 7/29) — 기준가 = 7/28 종가 (위 수정이 정상 피드를 깨지 않는가)
 *   - 사이 세션 결손(^KS11 8/3 null · 8/4 진행) — 기준가 = 분봉 보강 8/3 종가(캐시로 주입, 네트워크 없음)
 *
 * ⚠️ 픽스처 meta 에 `symbol` 을 빼지 말 것 — /api/indices 경로는 분봉 보강 키를 meta.symbol 에서 읽는다
 *    (1d 경로는 인자 $symbol). 빼면 결손 시나리오에서 /api/indices 만 보강을 건너뛰어 가짜 불일치가 난다.
 */
class IndexPrevCloseParityTest extends TestCase
{
    private const KS_DAY0 = 1785110400; // 2026-07-27 00:00 UTC = 09:00 KST (^KS11 일봉 타임스탬프 계열)

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(TossStockMaster::class, fn ($m) => $m->shouldReceive('getName')->andReturn('지수'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, float|null>, 3: float, 4: float}>
     */
    public static function scenarios(): array
    {
        $d = fn (int $n) => self::KS_DAY0 + $n * 86400;

        return [
            '오늘 봉 null — ^KS11 7/29' => ['^KS11', [
                'symbol' => '^KS11', 'gmtoffset' => 32400, 'regularMarketTime' => 1785315940, 'regularMarketPrice' => 5663.24,
                'chartPreviousClose' => 6516.27,
            ], [$d(0) => 6755.75, $d(1) => 6023.66, $d(2) => null], 6023.66, -5.98],

            '오늘 봉 진행 중 — NQ=F 7/29' => ['NQ=F', [
                'symbol' => 'NQ=F', 'gmtoffset' => -14400, 'regularMarketTime' => 1785341361, 'regularMarketPrice' => 27448.25,
                'chartPreviousClose' => 29181.25,
            ], [1784865600 => 28282.25, 1785124800 => 28190.0, 1785211200 => 27922.0, 1785297600 => 27448.25], 27922.0, -1.7],

            '사이 세션 결손 — ^KS11 8/3 null · 8/4' => ['^KS11', [
                'symbol' => '^KS11', 'gmtoffset' => 32400, 'regularMarketTime' => $d(8) + 2280, 'regularMarketPrice' => 6343.87,
                'chartPreviousClose' => 6000.0,
            ], [$d(4) => 6595.45, $d(7) => null, $d(8) => 6343.87], 6257.45, 1.38],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, float|null>  $series  timestamp => close
     */
    #[Test]
    #[DataProvider('scenarios')]
    public function test_both_paths_pick_the_same_last_completed_close(
        string $symbol, array $meta, array $series, float $expectedBase, float $expectedPct
    ): void {
        Cache::put("yahoo_session_close_{$symbol}_2026-08-03", 6257.45, 600); // 결손 시나리오의 분봉 보강값
        $closes = array_values($series);
        $payload = ['chart' => ['result' => [[
            'meta' => $meta,
            'timestamp' => array_keys($series),
            'indicators' => ['quote' => [[
                'open' => $closes, 'high' => $closes, 'low' => $closes, 'close' => $closes,
                'volume' => array_fill(0, count($closes), 1000),
            ]]],
        ]]]];

        // 경로 1 — /api/indices
        $parser = new ReflectionMethod(StockController::class, 'parseYahooFinanceChart');
        $indices = $parser->invoke(app(StockController::class), $payload, '지수');

        // 경로 2 — /api/stocks/{지수}?timeframe=1d
        FakeYahooStockController::$body = json_encode($payload);
        $daily = app(FakeYahooStockController::class)->getYahooChartData($symbol, '1d')->getData(true);
        $this->assertSame('Yahoo Finance (1d)', $daily['source'], '1d 경로가 목업 폴백으로 빠졌다(예외)');

        $dailyBase = round($daily['current_price'] - $daily['change_amount'], 2);
        $this->assertSame($expectedBase, $dailyBase, '1d 경로 기준가');
        $this->assertSame($expectedPct, $daily['change_percent'], '1d 경로 등락률');

        // 두 경로의 일치 — 한쪽만 고치면 여기서 걸린다
        $this->assertSame($indices['change_percent'], $daily['change_percent'], '/api/indices 와 1d 의 등락률이 다르다');
        $this->assertSame(round($indices['change'], 2), $daily['change_amount'], '/api/indices 와 1d 의 등락폭이 다르다');
    }
}

/** getYahooChartData 의 Yahoo 응답을 바꿔 끼운 컨트롤러 — 네트워크 없음. */
class FakeYahooStockController extends StockController
{
    public static string $body = '';

    protected function yahooChartClient(): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], self::$body)]))]);
    }
}
