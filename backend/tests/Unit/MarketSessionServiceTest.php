<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketSessionService;
use App\Services\Toss\TossApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MarketSessionService::getUsSession — US 세션 경계 계약.
 *
 * ★ 이 파일의 정본은 **토스 US 캘린더 응답 픽스처**다. 우리 ET 상수가 아니다.
 *
 *   2026-07-17 이전 상수(주간거래 종료 03:30·애프터 종료 19:30)는 토스 앱 안내 팝업 스크린샷
 *   (docs/거래시간.jpg, bc7db22 동봉)을 전사한 값이었고, 토스 자신의 API·캘린더와 어긋나 있었다.
 *   그런데도 3번 연속 못 잡았다 — 검증자(테스트·스캔)가 피검증자(getUsSession)를 기준으로 삼았기 때문이다.
 *   ~89,000분 스캔조차 "코드의 경계"는 정확히 찾아냈지만 "코드가 틀렸다"는 원리적으로 말할 수 없었다.
 *   그래서 여기서는 getUsSession 을 기준으로 아무것도 계산하지 않는다. **픽스처가 말하고 코드가 답한다.**
 *
 * 픽스처 스키마 출처 = 실측(docs/기능/토스_API_이관/03_구현_검증.md §토스 US 캘린더 스키마).
 * 4창이 전부 KST 절대시각이라 ET 환산·DST 수학을 여기 옮겨 적지 않는다(옮겨 적는 순간 복제본이 된다).
 */
class MarketSessionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    // 캘린더 응답 픽스처 (정본)
    // ──────────────────────────────────────────────────────────────────

    /**
     * 토스 US 캘린더 대역이 물린 MarketSessionService.
     *
     * 응답은 from/to 를 무시하고 previousBusinessDay·today·nextBusinessDay 3노드만 준다(실측).
     * 노드 3개를 넘기면 그 순서로 매핑된다. `[]` = 캘린더 전면 실패(허용 IP 미등록 등).
     *
     * @param  list<array<string,mixed>>  $nodes
     */
    private function sessionService(array $nodes): MarketSessionService
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn(
            $nodes === []
                ? []
                : ['result' => array_combine(['previousBusinessDay', 'today', 'nextBusinessDay'], $nodes)]
        );

        return new MarketSessionService($client);
    }

    /**
     * 정상 영업일 D 노드 — 4창 전부 KST 절대시각(실측 스키마 그대로).
     *   dayMarket D 09:00~17:00 · preMarket D 17:00~22:30 ·
     *   regularMarket D 22:30~D+1 05:00 · afterMarket D+1 05:00~08:50
     */
    private function usDayNode(string $date): array
    {
        $next = Carbon::parse($date, 'Asia/Seoul')->addDay()->toDateString();

        return [
            'date' => $date,
            'dayMarket' => $this->kstWindow("{$date} 09:00", "{$date} 17:00"),
            'preMarket' => $this->kstWindow("{$date} 17:00", "{$date} 22:30"),
            'regularMarket' => $this->kstWindow("{$date} 22:30", "{$next} 05:00"),
            'afterMarket' => $this->kstWindow("{$next} 05:00", "{$next} 08:50"),
        ];
    }

    /**
     * 휴장일 노드.
     *
     * ⚠️ 실측 함정: 휴장일은 "키가 존재하고 값이 null" 이다 — array_key_exists() 는 **true** 를 준다.
     *   (isset() 은 null 에 false 라 우연히 맞지만, 프로덕션은 의도가 드러나는 is_array() 로 판정한다.)
     *   이 픽스처가 그 함정을 그대로 재현하므로, 키 유무로 거르는 구현으로 바뀌면 아래 노동절 케이스가 깨진다.
     */
    private function usHolidayNode(string $date): array
    {
        return [
            'date' => $date,
            'dayMarket' => null,
            'preMarket' => null,
            'regularMarket' => null,
            'afterMarket' => null,
        ];
    }

    /** @return array{startTime:string,endTime:string} KST 절대시각(ISO8601 +09:00) */
    private function kstWindow(string $start, string $end): array
    {
        return [
            'startTime' => Carbon::parse($start, 'Asia/Seoul')->format('Y-m-d\TH:i:s.vP'),
            'endTime' => Carbon::parse($end, 'Asia/Seoul')->format('Y-m-d\TH:i:s.vP'),
        ];
    }

    /** ET 시각 문자열 → unix timestamp */
    private function et(string $time): int
    {
        return Carbon::parse($time, 'America/New_York')->getTimestamp();
    }

    /**
     * 폴백의 거래일 게이트(us_trading_day_{Y-m-d})를 미리 채운다.
     *
     * ⚠️ 이 시딩이 없으면 isUsMarketTradingToday() 가 **진짜 Yahoo SPY 를 때린다**(라이브 호출).
     *   값은 '그 날이 거래일인가'라는 사실 표일 뿐 세션 경계와 무관하다.
     */
    private function seedTradingDays(array $dates): void
    {
        foreach ($dates as $date) {
            Cache::put("us_trading_day_{$date}", true, 86400);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 하드코딩 폴백 == 캘린더 (옛 상수 03:30·19:30 을 잡는 유일한 지점)
    // ──────────────────────────────────────────────────────────────────

    /**
     * 평시 거래일: 하드코딩 폴백이 토스 캘린더와 **한 시각도 갈리지 않는다**.
     *
     * 폴백은 캘린더의 거울이다(토스 IP 미등록 시 유일한 생명선). 거울이 원본과 다르면 그건 폴백 결함이다 —
     * 캘린더가 정본이므로 언제나 폴백 쪽이 틀린 것으로 판정한다.
     *
     * ★ 옛 상수가 3번 살아남은 지점이 정확히 여기다. 되돌리면:
     *   ET 03:30~03:59(주간거래 30분) · 19:30~19:49(애프터 20분) 이 '장마감'으로 뒤집혀 FAIL 한다.
     *   (라이브 피해 실측: KST 16:30~16:59 매 평일 30분, 기준가 7.9pp 오차.)
     */
    #[Test]
    public function test_get_us_session_hardcoded_fallback_matches_calendar_on_normal_trading_day(): void
    {
        $dates = ['2026-07-15', '2026-07-16', '2026-07-17'];   // 수·목·금
        $nodes = array_map(fn (string $d): array => $this->usDayNode($d), $dates);

        // ET 7/15 12:07 → 7/16 12:07, 5분 격자 288포인트(24h). 경계를 여기 열거하지 않는다 — 격자면 잡힌다.
        $times = [];
        for ($i = 0; $i < 288; $i++) {
            $times[] = $this->et('2026-07-15 12:07:00') + $i * 300;
        }

        // 캘린더 pass. 두 서비스가 같은 Cache(toss_us_session_windows_*)를 공유하므로 반드시 분리 실행한다 —
        // 같이 돌리면 폴백이 캘린더의 캐시를 읽어 양쪽이 자동 일치(= tautology)가 된다.
        $calendar = $this->sessionService($nodes);
        $expected = [];
        foreach ($times as $ts) {
            $expected[$this->etKey($ts)] = $calendar->getUsSession($ts);
        }

        Cache::flush();
        $this->seedTradingDays($dates);

        $fallback = $this->sessionService([]);
        $actual = [];
        foreach ($times as $ts) {
            $actual[$this->etKey($ts)] = $fallback->getUsSession($ts);
        }

        $this->assertSame($expected, $actual, '하드코딩 폴백이 토스 캘린더와 갈린다 — 캘린더가 정본이다');
    }

    /** ET 'Y-m-d H:i' — 실패 diff 를 사람이 읽을 수 있게 하는 표시용 키 */
    private function etKey(int $ts): string
    {
        return Carbon::createFromTimestamp($ts, 'America/New_York')->format('Y-m-d H:i');
    }

    // ──────────────────────────────────────────────────────────────────
    // 캘린더만 답할 수 있는 날 — 상수로는 표현 자체가 불가능하다
    // ──────────────────────────────────────────────────────────────────

    /**
     * 케이스 3 — 노동절 2026-09-07: **주간거래 창 전체가 '장마감'**.
     *
     * 옛 코드는 주간거래 분기가 거래일 게이트보다 먼저 return 해 미 공휴일에 450분을 '주간거래'로
     * 누출했다(실측: KST 09/07 09:00~16:29 우리='주간거래' vs 토스='장마감').
     *
     * 게이트를 앞으로 옮기는 단순 수정은 깨진다 — 도메인이 비대칭이라서다:
     *   휴장 **전야**는 미개장(9/07 dayMarket=null) · 휴장일 **당일 저녁**은 개장(9/08 dayMarket 존재).
     * 이 비대칭은 캘린더만 안다.
     */
    #[Test]
    public function test_get_us_session_labor_day_day_market_window_is_closed(): void
    {
        $service = $this->sessionService([
            $this->usDayNode('2026-09-04'),      // 금 (직전 영업일)
            $this->usHolidayNode('2026-09-07'),  // 월 — 노동절
            $this->usDayNode('2026-09-08'),      // 화 (다음 영업일)
        ]);

        // 주간거래 창(KST 9/07 09:00~17:00 = ET 9/06 20:00 ~ 9/07 04:00) 전 구간
        foreach (['2026-09-06 20:01:00', '2026-09-06 23:59:00', '2026-09-07 00:01:00', '2026-09-07 03:59:00'] as $et) {
            $this->assertSame('장마감', $service->getUsSession($this->et($et)), "노동절 주간거래 창 {$et} ET");
        }

        // 데이 세션도 전부 휴장
        $this->assertSame('장마감', $service->getUsSession($this->et('2026-09-07 10:00:00')), '노동절 정규장 시간대');

        // 비대칭 — 휴장일 '저녁'은 개장한다(9/08 dayMarket = ET 9/07 20:00)
        $this->assertSame('주간거래', $service->getUsSession($this->et('2026-09-07 20:01:00')), '휴장일 저녁은 개장');
    }

    /**
     * 케이스 4 — 블랙프라이데이 2026-11-27 조기폐장: 정규장이 **ET 13:00** 에 끝난다.
     *
     * ★ 상수로는 표현 자체가 불가능한 날이다. 이 케이스의 통과가 곧 '캘린더 경로가 살아있다'는
     *   유일한 증거다 — 캘린더를 무력화하면 폴백이 13:01 을 '정규장'이라 답해 FAIL 한다.
     *
     * 창 리터럴 출처 = 실측(regularMarket.endTime = KST 03:00 · afterMarket = KST 03:00~07:00).
     */
    #[Test]
    public function test_get_us_session_black_friday_early_close_regular_ends_at1300_et(): void
    {
        $blackFriday = [
            'date' => '2026-11-27',
            'dayMarket' => $this->kstWindow('2026-11-27 09:00', '2026-11-27 17:00'),
            'preMarket' => $this->kstWindow('2026-11-27 17:00', '2026-11-27 22:30'),
            'regularMarket' => $this->kstWindow('2026-11-27 22:30', '2026-11-28 03:00'),  // 조기폐장 = ET 13:00
            'afterMarket' => $this->kstWindow('2026-11-28 03:00', '2026-11-28 07:00'),  // ET 13:00~17:00
        ];

        // 11/26 은 추수감사절 → 직전 영업일은 11/25. 사이(11/26)·주말(11/28·29)은 프로덕션이 빈 창으로 채운다.
        $service = $this->sessionService([
            $this->usDayNode('2026-11-25'),
            $blackFriday,
            $this->usDayNode('2026-11-30'),
        ]);

        $expected = [
            '2026-11-27 12:59:00' => '정규장',
            '2026-11-27 13:01:00' => '애프터마켓',   // 구모형은 '정규장' (2h59m 어긋남)
            '2026-11-27 16:06:00' => '애프터마켓',
            '2026-11-27 17:01:00' => '장마감',       // 구모형은 '애프터마켓'
        ];

        foreach ($expected as $et => $session) {
            $this->assertSame($session, $service->getUsSession($this->et($et)), "블프 {$et} ET");
        }
    }

    /**
     * 케이스 5 — 캘린더 `[]`(토스 허용 IP 미등록 시 전 호출이 이렇게 된다) → 하드코딩 폴백으로 graceful.
     * 폴백이 유일한 생명선이라 예외·null 없이 세션명을 계속 답해야 한다.
     */
    #[Test]
    public function test_get_us_session_calendar_unavailable_falls_back_gracefully(): void
    {
        $service = $this->sessionService([]);
        $this->seedTradingDays(['2026-07-16']);

        $expected = [
            '2026-07-16 03:45:00' => '주간거래',
            '2026-07-16 04:01:00' => '프리마켓',
            '2026-07-16 10:00:00' => '정규장',
            '2026-07-16 17:00:00' => '애프터마켓',
            '2026-07-16 19:55:00' => '장마감',
            '2026-07-16 20:01:00' => '주간거래',
        ];

        foreach ($expected as $et => $session) {
            $this->assertSame($session, $service->getUsSession($this->et($et)), "캘린더 [] 폴백 {$et} ET");
        }
    }
}
