<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\StockController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 야간선물 세션 판정 회귀 가드 — NQ=F(나스닥100, CME) · KOSPI_NIGHT(KRX 야간선물) 양쪽.
 *
 * NQ=F: CME 실경계(ET) 판정.
 * KOSPI_NIGHT: 거래일 18:00 ~ 익일 05:00. 새벽은 **전일 저녁 세션의 연장**이라
 *              거래일 판정을 전일 기준으로 소급해야 한다(isKrNightFuturesActive).
 *
 * 계약(동결):
 *   · 금 17:00 ET 마감 → 일 18:00 ET 재개 = 주말 휴장
 *   · 평일(월~목) 17:00~18:00 ET = 일일 유지보수(정지)
 *
 * 배경 (버그):
 *   옛 코드는 휴장창을 KST 리터럴(토 06:00~월 07:00)로 굳혔다. 그 값은 **여름(EDT) 한정**이고
 *   겨울(EST)엔 토 07:00~월 08:00 KST 라 양 끝이 1시간씩 어긋난다(연 ~36h 오판).
 *   1년의 7.5개월은 우연히 맞아 발견이 늦었다 — 그래서 이 파일은 **EDT·EST 양 체제를 모두** 태운다.
 *   EDT 만 검증하면 옛 KST 하드코딩도 통과해 회귀를 놓친다.
 *
 * 검증 방식:
 *   Carbon::setTestNow 로 시각을 고정하고 **실제 StockController::getStockData() 경로**를 태워
 *   응답의 session/is_trading_day 를 단언한다(소스 문자열 검사 아님).
 *   WS 전송 경로(ws_allow_stale=true)로 태워 캐시에 심은 캔들을 쓰게 하므로 Yahoo HTTP 를 타지 않는다.
 */
class StockControllerNightFuturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // 시간 고정 해제 (다른 테스트 오염 방지)
        parent::tearDown();
    }

    /**
     * 주어진 시각으로 시계를 고정하고 실제 컨트롤러 경로를 태워 NQ=F 응답을 얻는다.
     */
    private function nqPayloadAt(string $literal, string $tz): array
    {
        Carbon::setTestNow(Carbon::parse($literal, $tz));

        // WS 전송 경로(allowStale=true)는 캐시된 캔들을 그대로 쓴다 → 외부 HTTP 없음.
        Cache::put('yahoo_stock_data_NQ=F_1d', response()->json([
            'ticker' => 'NQ=F',
            'candles' => [['time' => 1, 'open' => 23000, 'high' => 23010, 'low' => 22990, 'close' => 23000]],
        ]));

        $request = Request::create('/api/stock/NQ=F', 'GET');
        $request->attributes->set('ws_allow_stale', true);

        $response = app(StockController::class)->getStockData($request, 'NQ=F');

        return json_decode($response->getContent(), true);
    }

    /**
     * CME 실경계(ET) 전수 — EDT·EST 양 체제에서 동일 ET 시각은 동일 판정이어야 한다.
     */
    #[Test]
    #[DataProvider('etBoundaryProvider')]
    public function test_nq_session_follows_cme_et_boundaries(string $etLiteral, string $expected, string $why): void
    {
        $data = $this->nqPayloadAt($etLiteral, 'America/New_York');

        $this->assertSame($expected, $data['session'], $why);
        // is_trading_day 는 session 과 같은 판정의 두 얼굴 — 프론트가 이 필드로 NQ 칸을 숨긴다.
        $this->assertSame($expected === '거래중', $data['is_trading_day'], $why . ' (is_trading_day 불일치)');
    }

    /**
     * ET 리터럴 고정값. 2026-07-17=금(EDT) / 2026-01-16=금(EST) 기준.
     */
    public static function etBoundaryProvider(): array
    {
        return [
            // ── 여름 EDT ────────────────────────────────────────────────
            'EDT 금 16:59 → 거래중(마감 직전)' => ['2026-07-17 16:59', '거래중', 'EDT 금 17:00 마감 직전은 거래중'],
            'EDT 금 17:01 → 장마감(주말 휴장 시작)' => ['2026-07-17 17:01', '장마감', 'EDT 금 17:00 마감 후는 장마감'],
            'EDT 토 12:00 → 장마감(종일 휴장)' => ['2026-07-18 12:00', '장마감', 'EDT 토요일은 종일 휴장'],
            'EDT 일 17:59 → 장마감(재개 직전)' => ['2026-07-19 17:59', '장마감', 'EDT 일 18:00 재개 직전은 장마감'],
            'EDT 일 18:01 → 거래중(재개)' => ['2026-07-19 18:01', '거래중', 'EDT 일 18:00 재개 후는 거래중'],
            'EDT 화 17:30 → 장마감(유지보수)' => ['2026-07-21 17:30', '장마감', 'EDT 평일 17:00~18:00 은 일일 유지보수(정지)'],
            'EDT 화 18:01 → 거래중(유지보수 종료)' => ['2026-07-21 18:01', '거래중', 'EDT 평일 18:00 유지보수 종료 후는 거래중'],

            // ── 겨울 EST — 옛 KST 하드코딩이 틀렸던 체제 ────────────────
            'EST 금 16:59 → 거래중(마감 직전)' => ['2026-01-16 16:59', '거래중', 'EST 금 17:00 마감 직전은 거래중'],
            'EST 금 17:01 → 장마감(주말 휴장 시작)' => ['2026-01-16 17:01', '장마감', 'EST 금 17:00 마감 후는 장마감'],
            'EST 토 12:00 → 장마감(종일 휴장)' => ['2026-01-17 12:00', '장마감', 'EST 토요일은 종일 휴장'],
            'EST 일 17:59 → 장마감(재개 직전)' => ['2026-01-18 17:59', '장마감', 'EST 일 18:00 재개 직전은 장마감'],
            'EST 일 18:01 → 거래중(재개)' => ['2026-01-18 18:01', '거래중', 'EST 일 18:00 재개 후는 거래중'],
            'EST 화 17:30 → 장마감(유지보수)' => ['2026-01-20 17:30', '장마감', 'EST 평일 17:00~18:00 은 일일 유지보수(정지)'],
            'EST 화 18:01 → 거래중(유지보수 종료)' => ['2026-01-20 18:01', '거래중', 'EST 평일 18:00 유지보수 종료 후는 거래중'],
        ];
    }

    /**
     * ★ 핵심 회귀 가드 — ET↔KST 실환산을 KST 리터럴로 못박는다.
     *
     * 옛 코드는 휴장창을 "토 06:00 ~ 월 07:00 KST" 로 굳혔다. 아래 EST 행들이 그 오류를 정면으로 잡는다:
     *   · 토 06:30 KST(EST) = 금 16:30 ET → 아직 거래중인데, 옛 코드는 '장마감'
     *   · 월 07:30 KST(EST) = 일 17:30 ET → 아직 휴장인데, 옛 코드는 '거래중'
     * EDT 행들은 옛 코드와도 일치한다 — 이것이 버그가 7.5개월간 숨어 있던 이유다.
     */
    #[Test]
    #[DataProvider('kstEquivalenceProvider')]
    public function test_nq_session_kst_equivalence_across_dst(string $kstLiteral, string $expected, string $why): void
    {
        $data = $this->nqPayloadAt($kstLiteral, 'Asia/Seoul');

        $this->assertSame($expected, $data['session'], $why);
    }

    public static function kstEquivalenceProvider(): array
    {
        return [
            // ── 겨울 EST: 금 17:00 ET = 토 07:00 KST · 일 18:00 ET = 월 08:00 KST ──
            'EST 토 06:30 KST = 금 16:30 ET → 거래중' => ['2026-01-17 06:30', '거래중', 'EST 토 06:30 KST 는 금 16:30 ET — 아직 거래중. 옛 KST 하드코딩(토 06:00~)은 여기서 장마감으로 오판했다.'],
            'EST 토 07:00 KST = 금 17:00 ET → 장마감(마감 경계)' => ['2026-01-17 07:00', '장마감', 'EST 마감 경계는 토 06:00 이 아니라 토 07:00 KST 다.'],
            'EST 월 07:30 KST = 일 17:30 ET → 장마감' => ['2026-01-19 07:30', '장마감', 'EST 월 07:30 KST 는 일 17:30 ET — 아직 휴장. 옛 KST 하드코딩(~월 07:00)은 여기서 거래중으로 오판했다.'],
            'EST 월 08:00 KST = 일 18:00 ET → 거래중(재개 경계)' => ['2026-01-19 08:00', '거래중', 'EST 재개 경계는 월 07:00 이 아니라 월 08:00 KST 다.'],

            // ── 여름 EDT: 옛 하드코딩과 우연히 일치하던 구간(회귀 시 이 행은 침묵한다) ──
            'EDT 토 06:00 KST = 금 17:00 ET → 장마감(마감 경계)' => ['2026-07-18 06:00', '장마감', 'EDT 마감 경계는 토 06:00 KST — 옛 하드코딩과 우연히 일치했던 값.'],
            'EDT 월 07:00 KST = 일 18:00 ET → 거래중(재개 경계)' => ['2026-07-20 07:00', '거래중', 'EDT 재개 경계는 월 07:00 KST — 옛 하드코딩과 우연히 일치했던 값.'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // KOSPI_NIGHT — KRX 야간선물 (isKrNightFuturesActive)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * KRX 거래일 캘린더 픽스처 (hermetic).
     *
     * MarketSessionService::isKrTradingDay 는 `kis_trading_day_{Ymd}` 캐시를 **먼저** 보고
     * 있으면 그대로 반환한다 → 여기에 심어 두면 토스 캘린더 API 를 타지 않는다.
     *
     * 2026-07-17(금)=제헌절 휴장. 근거: KRX 2026-05-20 공지 — 제헌절이 2026년부터
     * 공휴일로 복원되어 6/3·7/17 전 시장 휴장. ("제헌절은 2008년부터 공휴일 아님"은
     * 2007년까지의 낡은 지식이다. 이 픽스처를 그 기억으로 되돌리지 말 것.)
     */
    private const KR_TRADING_DAYS = [
        '20260710' => true,   // 금
        '20260711' => false,  // 토
        '20260712' => false,  // 일
        '20260716' => true,   // 목 — KRX 공지 "7/16 18:00 개시분 정상"
        '20260717' => false,  // 금 — 제헌절 휴장 ("7/17 18:00 개시분 휴장")
        '20260718' => false,  // 토
    ];

    /**
     * KST 시각을 고정하고 실제 컨트롤러 경로를 태워 KOSPI_NIGHT 응답을 얻는다.
     * (소스 grep 아님 — Carbon::setTestNow + 실제 코드 경로.)
     */
    private function kospiNightPayloadAt(string $kstLiteral): array
    {
        Carbon::setTestNow(Carbon::parse($kstLiteral, 'Asia/Seoul'));

        foreach (self::KR_TRADING_DAYS as $ymd => $isOpen) {
            Cache::put("kis_trading_day_{$ymd}", $isOpen, 3600);
        }

        // WS 전송 경로(allowStale=true) → 캐시된 캔들 사용, 외부 HTTP 없음.
        Cache::put('kospi_night_data_1d', response()->json([
            'ticker' => 'KOSPI_NIGHT',
            'candles' => [['time' => 1, 'open' => 400, 'high' => 401, 'low' => 399, 'close' => 400]],
        ]));

        $request = Request::create('/api/stock/KOSPI_NIGHT', 'GET');
        $request->attributes->set('ws_allow_stale', true);

        $response = app(StockController::class)->getStockData($request, 'KOSPI_NIGHT');

        return json_decode($response->getContent(), true);
    }

    /**
     * ★ 핵심 회귀 가드 2종이 이 표에 있다:
     *   1) **새벽 소급(-1 day)** — 토 새벽은 금요일 밤 세션의 연장이다. 소급을 빼면
     *      '오늘=토=비거래일'로 잘려 거래중인 세션이 '장마감'으로 죽는다.
     *   2) **isKrTradingDay 연동** — 7/17(제헌절) 휴장이 18:00 개시분과 그 새벽 연장을
     *      모두 닫는다. 연동을 빼면 휴일 밤에도 '거래중'으로 열린다.
     */
    #[Test]
    #[DataProvider('krNightFuturesProvider')]
    public function test_kr_night_futures_session(string $kstLiteral, string $expected, string $why): void
    {
        $data = $this->kospiNightPayloadAt($kstLiteral);

        $this->assertSame($expected, $data['session'], $why);
    }

    public static function krNightFuturesProvider(): array
    {
        return [
            // ── 새벽 소급(-1 day) ──────────────────────────────────────────
            '토 00:30 = 금 밤 세션의 새벽 연장 → 거래중' => ['2026-07-11 00:30', '거래중', '토 새벽은 전일(금 7/10) 저녁 세션의 연장이다. -1 day 소급이 빠지면 오늘=토=비거래일로 잘려 장마감으로 오판한다.'],
            '금 00:30 = 목 밤 세션의 새벽 연장 → 거래중' => ['2026-07-17 00:30', '거래중', '금 새벽은 전일(목 7/16) 저녁 세션의 연장 — 7/17 이 휴일이어도 전일 밤 세션은 살아 있다.'],
            '일 00:30 = 토 밤(비거래일) → 장마감' => ['2026-07-12 00:30', '장마감', '전일(토 7/11)이 비거래일이므로 소급할 세션 자체가 없다.'],
            '월 00:30 = 일 밤(비거래일) → 장마감' => ['2026-07-13 00:30', '장마감', '월 새벽은 전일(일 7/12)이 비거래일 → 소급 불가 → 장마감. 주말 끝자락에서 세션이 되살아나지 않는지 잠근다.'],

            // ── isKrTradingDay 연동 (제헌절 휴장) ─────────────────────────
            '목 18:00 (거래일) → 거래중' => ['2026-07-16 18:00', '거래중', 'KRX 공지 "7/16 18:00 개시분 정상".'],
            '금 18:00 = 제헌절 휴장 → 장마감' => ['2026-07-17 18:00', '장마감', 'KRX 공지 "7/17 18:00 개시분 휴장"(제헌절). isKrTradingDay 연동이 빠지면 휴일 밤에도 거래중으로 열린다.'],
            '토 00:30 (전일 7/17 휴장) → 장마감' => ['2026-07-18 00:30', '장마감', '전일(7/17)이 비거래일 → 소급 불가. 소급 규칙과 휴일 캘린더가 동시에 걸리는 케이스.'],
            '일 18:00 (비거래일) → 장마감' => ['2026-07-12 18:00', '장마감', '일요일은 비거래일 — 저녁 개시분 없음.'],

            // ── 시간 경계 ─────────────────────────────────────────────────
            '목 17:59 → 장마감(개시 전)' => ['2026-07-16 17:59', '장마감', '18:00 개시 전은 장마감(거래일이어도).'],
            '금 05:00 → 장마감(종료 경계)' => ['2026-07-17 05:00', '장마감', '새벽 연장은 05:00 에 끝난다 — 05:00 은 이미 장마감.'],
        ];
    }
}
