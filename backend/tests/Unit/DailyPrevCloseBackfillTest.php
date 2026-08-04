<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 일봉 결손 시 기준가 분봉 보강 — 회귀 테스트 (2026-08-04 장중 실측)
 *
 * 버그: Yahoo ^KS11 일봉이 8/3 만 끝내 null 인 채 8/4 봉이 채워졌다.
 *       일봉 배열에 8/3 이 없으니 기준가가 7/31(6595.45)로 하루 밀려
 *       1d·/api/indices 가 -3.70% 를 냈다(분봉 기준 정답 +1.37%).
 *       8/3 종가 6257.45 는 일봉엔 없지만 분봉에는 남아 있다.
 *
 * 수정: '오늘 이전 마지막 세션 날짜' != '오늘 이전 마지막 값 있는 날짜' 이면 결손 →
 *       그 세션 종가만 분봉(5m/5d)에서 보강. 정상 피드는 둘이 같아 외부 호출조차 하지 않는다.
 *
 * 외부 조회는 Cache::remember 를 통과하므로, 캐시를 미리 심어 네트워크 없이 검증한다.
 */
class DailyPrevCloseBackfillTest extends TestCase
{
    private const KST = 32400;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 핵심 케이스 — 기준가 후보 봉과 오늘 봉 사이에 null 결손이 있으면 분봉에서 보강
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_backfills_prev_close_when_missing_session_sits_between_candidate_and_today(): void
    {
        Cache::put('yahoo_session_close_^KS11_2026-08-03', 6257.45, 600);

        $backfilled = $this->backfill(
            [
                '2026-07-31' => 6595.4501953125,
                '2026-08-03' => null,        // ← 끝내 안 채워진 세션 (기준가가 되어야 할 날)
                '2026-08-04' => 6343.87,     // 오늘
            ],
            '^KS11',
            '2026-08-04'
        );

        $this->assertSame(
            6257.45,
            $backfilled,
            '8/3 일봉이 null 이면 기준가를 분봉의 8/3 종가로 보강해야 한다. ' .
            'null 을 걸러낸 배열의 직전 봉(7/31, 6595.45)을 쓰면 등락률이 하루 밀린다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 무회귀 — 정상 피드는 감지 자체가 안 걸리고 외부 조회도 하지 않는다
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_healthy_feed_needs_no_backfill_and_makes_no_lookup(): void
    {
        $backfilled = $this->backfill(
            [
                '2026-07-30' => 333.42,
                '2026-07-31' => 308.91,
                '2026-08-03' => 303.42,   // 오늘 (직전 세션 7/31 은 값이 있다)
            ],
            'AAPL',
            '2026-08-03'
        );

        $this->assertNull($backfilled, '정상 피드에서는 보강이 일어나면 안 된다(종전 기준가 유지).');
        $this->assertFalse(
            Cache::has('yahoo_session_close_AAPL_2026-07-31'),
            '정상 피드인데 분봉 조회를 시도했다 — 결손이 없으면 외부 호출이 늘어선 안 된다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 무회귀 — 기준가 후보보다 앞쪽의 오래된 결손은 무시한다 (불필요한 조회 방지)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_older_gap_before_the_candidate_does_not_trigger_backfill(): void
    {
        $backfilled = $this->backfill(
            [
                '2026-07-20' => null,     // 오래된 결손 — 기준가와 무관
                '2026-07-31' => 6595.45,  // 오늘 이전 마지막 세션 = 값 있음
                '2026-08-03' => 6257.45,  // 오늘
            ],
            '^KS11',
            '2026-08-03'
        );

        $this->assertNull($backfilled, '기준가 후보 뒤쪽에 결손이 없으면 보강하지 않는다.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 보강 실패 → 조용히 종전 동작 (본 경로를 죽이지 않는다)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_falls_back_silently_when_intraday_has_no_value(): void
    {
        // 분봉 조회 실패·해당 날짜 값 없음은 0.0 으로 캐시된다
        Cache::put('yahoo_session_close_^KS11_2026-08-03', 0.0, 600);

        $backfilled = $this->backfill(
            ['2026-07-31' => 6595.45, '2026-08-03' => null, '2026-08-04' => 6343.87],
            '^KS11',
            '2026-08-04'
        );

        $this->assertNull($backfilled, '보강 실패는 null 로 돌려 호출부가 종전 기준가를 그대로 쓰게 해야 한다.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 두 경로(1d · /api/indices) 모두에 배선돼 있어야 한다
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_both_daily_paths_are_wired_to_the_backfill(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/StockController.php');

        $this->assertSame(
            2,
            substr_count($src, '$this->backfillPrevCloseFromIntraday('),
            'backfillPrevCloseFromIntraday 호출이 2곳(getYahooChartData 1d · parseYahooFinanceChart)이 아니다 — ' .
            '한쪽만 배선하면 그 경로만 하루 밀린 등락률을 계속 낸다.'
        );

        // KRX 종가는 15:30 종가단일가로 결정되는데 Yahoo 정규장 신고는 09:00~15:00 이라
        // includePrePost 를 빼면 14:55 봉(6251.59)이 잡혀 실제 종가(6257.45)와 어긋난다.
        $this->assertMatchesRegularExpression(
            '/interval=5m&range=5d&includePrePost=true/',
            $src,
            '분봉 보강 URL 에 includePrePost=true 가 없다 — KRX 종가단일가(15:30) 봉이 빠져 종가가 어긋난다.'
        );
    }

    /**
     * @param  array<string, float|null>  $series  거래소 현지 날짜 => 일봉 close(널 포함)
     */
    private function backfill(array $series, string $symbol, string $today): ?float
    {
        $timestamps = [];
        foreach (array_keys($series) as $date) {
            // 09:00 KST 개장 시각 — 거래소 현지 날짜가 키와 같아지도록 만든다
            $timestamps[] = strtotime($date . ' 09:00:00 +0900');
        }

        $method = new ReflectionMethod(StockController::class, 'backfillPrevCloseFromIntraday');
        $method->setAccessible(true);

        return $method->invoke(
            app(StockController::class),
            $timestamps,
            array_values($series),
            $symbol,
            $today,
            self::KST
        );
    }
}
