<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\FxService;
use App\Services\MarketSessionService;
use App\Services\Toss\TossFxProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FxService::getUsdKrw() 의 prev_close(전일 종가) 필드 검증.
 *
 * SQLite :memory: — 실 DB(TiDB Cloud hachiware)에는 접근하지 않는다.
 * exchange_rates 테이블만 직접 생성(RefreshDatabase 미사용):
 *   전체 마이그레이션 중 ->change() 사용분이 doctrine/dbal 을 요구하는데,
 *   본 로컬 환경(PHP 7.4)에는 미설치라 마이그레이션 전량 실행이 불가하기 때문.
 * Yahoo HTTP 는 호출하지 않는다: 전일 종가 캐시(yahoo_fx_prev_close_usdkrw)를
 * 미리 시드하면 fetchPrevClose() 가 캐시 히트로 즉시 반환하기 때문이다.
 *
 * 검증 항목:
 *   1. DB 신선값 경로: 캐시된 prev_close 가 반환 배열에 실림
 *   2. 토스 취득 경로: prev_close 가 실려 반환됨
 *   3. 캐시가 비양수(0/음수)면 prev_close = null (>0 가드, graceful)
 *   4. (아래 '서울 종가 계약' 절) 캐시 miss 시 어느 봉·어느 영업일을 집는지 + TTL 경계
 *
 * PHP 7.4 환경: named arguments 사용 금지.
 */
class FxServicePrevCloseTest extends TestCase
{
    private const PREV_CLOSE_KEY = 'yahoo_fx_prev_close_usdkrw';

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();

        // 마이그레이션 전량 대신 필요한 테이블만 직접 생성 (doctrine/dbal 회피).
        Schema::create('exchange_rates', function ($table): void {
            $table->id();
            $table->string('from_currency', 8);
            $table->string('to_currency', 8);
            $table->decimal('rate', 12, 4);
            $table->timestamp('recorded_at')->nullable();
            $table->string('source')->nullable();
            $table->unique(['from_currency', 'to_currency']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();  // 시간 고정 해제 (15:30 경계 테스트 격리)
        Schema::dropIfExists('exchange_rates');
        parent::tearDown();
    }

    #[Test]
    public function test_get_usd_krw_fresh_db_row_includes_cached_prev_close(): void
    {
        Cache::put(self::PREV_CLOSE_KEY, 1505.91, 60);

        // 신선한 DB 값 → 외부 호출(토스) 없이 조기 반환 경로
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'KRW',
            'rate' => 1496.3,
            'recorded_at' => Carbon::now(),
        ]);

        $fxProvider = $this->createMock(TossFxProvider::class);
        $fxProvider->expects($this->never())->method('fetchUsdKrw');

        $service = new FxService($fxProvider);
        $result = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('prev_close', $result);
        $this->assertSame(1505.91, $result['prev_close']);
    }

    #[Test]
    public function test_get_usd_krw_toss_fetched_path_includes_prev_close(): void
    {
        Cache::put(self::PREV_CLOSE_KEY, 1505.91, 60);

        // DB 값 없음 → 토스 취득 경로
        $fxProvider = $this->createMock(TossFxProvider::class);
        $fxProvider->method('fetchUsdKrw')->willReturn([
            'rate' => 1496.3,
            'recorded_at' => Carbon::now()->toDateTimeString(),
            'source' => 'Toss_ExchangeRate',
        ]);

        $service = new FxService($fxProvider);
        $result = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(1496.3, $result['rate']);
        $this->assertSame(1505.91, $result['prev_close']);
    }

    #[Test]
    public function test_get_usd_krw_non_positive_cached_prev_close_returns_null(): void
    {
        // 캐시에 비양수(파싱 실패/이상값) → prev_close 는 null 로 graceful 처리
        Cache::put(self::PREV_CLOSE_KEY, 0, 60);

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'KRW',
            'rate' => 1496.3,
            'recorded_at' => Carbon::now(),
        ]);

        $service = new FxService($this->createMock(TossFxProvider::class));
        $result = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertNull($result['prev_close']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 서울 외환시장 종가 계약 (2026-07-17) — 캐시 miss 경로, hermetic
    //   prev = '이미 지나간 마지막 영업일 15:30 KST' 의 환율. 값이 바뀌는 경계는 그 15:30 하나뿐.
    //
    //   ⚠️ 대역 주입 필수: FxService 는 $http·$session 을 미주입하면 진짜 Yahoo·컨테이너를 때린다.
    //      아래 케이스는 전부 makeService() 로 두 대역을 주입해 네트워크 0회로 검증한다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * 2026 KRX 휴장일 — 아래 테스트가 밟는 구간(2월 설연휴·7월)만. 전부 실제 캘린더로 확증한 날짜다.
     *
     *   2/16~2/18 설연휴.
     *   7/17 제헌절 — 2026년부터 공휴일 복원(2026-04-28 국무회의 '관공서의 공휴일에 관한 규정' 개정 의결).
     *     KRX 2026-05-20 공지: 6/3·7/17 전 시장 휴장. 토스 마켓캘린더 API 실측도 일치
     *     (7/17 integrated=null · previousBusinessDay=7/16 · nextBusinessDay=7/20).
     *     ⚠️ 2008~2025 년엔 평일이었다 — '제헌절은 공휴일이 아니다'라는 낡은 지식으로 이 날짜를
     *     영업일로 되돌리지 말 것. 공휴일이라 은행·서울 외환시장도 함께 쉰다(KRX 달력으로 근사해도 정확).
     */
    private const KR_HOLIDAYS = ['2026-02-16', '2026-02-17', '2026-02-18', '2026-07-17'];

    /** 서울 종가 실측값 — 7/16(목) 15:30 KST. [15:00,15:30) 봉의 close. */
    private const CLOSE_0716 = 1479.78;

    /** 함정값 — 15:30 각인 봉([15:30,16:00))의 close 는 16:00 값이다. 집으면 30분 밀린다. */
    private const CLOSE_0716_1600 = 1478.28;

    /**
     * Yahoo USDKRW=X 30분봉 대역.
     *
     * 봉 timestamp = 구간 시작점. 키를 'Y-m-d H:i'(KST) 로 주면 그 각인의 봉을 만든다.
     * URI 무관하게 같은 시계열을 준다 — range 파라미터가 바뀌어도 프로덕션이 같은 봉을 집는지 보기 위함.
     *
     * @param  array<string,float>  $barsByKstTime  ['Y-m-d H:i'(KST) => close]
     */
    private function yahooFxClient(array $barsByKstTime): Client
    {
        $handler = function () use ($barsByKstTime): FulfilledPromise {
            $timestamps = [];
            $closes = [];
            foreach ($barsByKstTime as $at => $close) {
                $timestamps[] = Carbon::parse($at, 'Asia/Seoul')->getTimestamp();
                $closes[] = $close;
            }

            $body = json_encode(['chart' => ['result' => [[
                'timestamp' => $timestamps,
                'indicators' => ['quote' => [['close' => $closes]]],
            ]]]]);

            return new FulfilledPromise(new Response(200, [], $body));
        };

        return new Client(['handler' => $handler]);
    }

    /**
     * 결정론 캘린더 대역 — 주말 + KR_HOLIDAYS 리터럴만 휴장. 토스 캘린더 API 를 타지 않는다.
     *
     * @return MarketSessionService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function fxSession()
    {
        $session = $this->createMock(MarketSessionService::class);
        $session->method('isKrTradingDay')->willReturnCallback(function (int $ts): bool {
            $day = Carbon::createFromTimestamp($ts, 'Asia/Seoul');

            return ! $day->isWeekend() && ! in_array($day->toDateString(), self::KR_HOLIDAYS, true);
        });

        return $session;
    }

    /**
     * 두 대역이 주입된 FxService. 토스는 고정 rate 를 주는 스텁 — 이 절의 관심사는 prev_close 뿐이다.
     *
     * @param  array<string,float>  $barsByKstTime
     */
    private function makeService(array $barsByKstTime): FxService
    {
        $fxProvider = $this->createMock(TossFxProvider::class);
        $fxProvider->method('fetchUsdKrw')->willReturn([
            'rate' => 1484.1,
            'recorded_at' => '2026-07-17 09:00:00',
            'source' => 'Toss_ExchangeRate',
        ]);

        return new FxService($fxProvider, $this->yahooFxClient($barsByKstTime), $this->fxSession());
    }

    /** 전일 종가 캐시의 put TTL 을 잡아낸다(캐시는 항상 miss). @return int|null 캡처된 TTL */
    private function captureTtl(): \Closure
    {
        $ttl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $t) use (&$ttl) {
            $ttl = $t;

            return true;
        });

        return function () use (&$ttl) {
            return $ttl;
        };
    }

    /**
     * ⚠️ 핵심 함정 가드: 15:30 '각인' 봉을 집으면 안 된다.
     *
     * [15:00,15:30) 봉 close = 15:30 직전 마지막 호가 = 서울 종가(1479.78).
     * [15:30,16:00) 봉 close = 16:00 값(1478.28) — 30분 밀린 값.
     * 두 봉을 다 주고 일부러 값을 다르게 둬, 어느 봉을 집었는지가 반환값에 그대로 드러나게 한다.
     */
    #[Test]
    public function test_prev_close_picks_bar_ending_at1530_not_the1530_stamped_bar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 09:00:00', 'Asia/Seoul'));

        $service = $this->makeService([
            '2026-07-16 14:30' => 1477.01,
            '2026-07-16 15:00' => self::CLOSE_0716,       // ← 정답: 15:30 을 끝점으로 갖는 봉
            '2026-07-16 15:30' => self::CLOSE_0716_1600,  // ← 함정: 이건 16:00 값이다
            '2026-07-16 16:00' => 1476.55,
        ]);

        $result = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(1479.78, $result['prev_close'], '15:30 각인 봉(1478.28)을 집으면 종가가 30분 밀린다');
    }

    /**
     * 경계 직전(목 15:29:59): 오늘 종가는 아직 없다 → 기준일 = 전 영업일 7/15(수).
     * TTL 은 1초 뒤 15:30 까지 — 경계를 넘겨 사는 캐시가 곧 stale 이다(하한 300초 금지).
     */
    #[Test]
    public function test_prev_close_thursday_one_second_before1530_uses_wednesday_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:29:59', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-07-15 15:00' => 1471.15,
            '2026-07-16 15:00' => self::CLOSE_0716,
        ])->getUsdKrw();

        $this->assertSame(1471.15, $result['prev_close'], '15:30 전엔 그날 종가가 없다 → 전 영업일(7/15)');
        $this->assertSame(1, $ttl(), '1초 뒤 경계(7/16 15:30)에 만료해야 한다');
    }

    /**
     * 경계 직후(목 15:30:00): 그 순간 오늘 종가가 확정 → 기준일 = 7/16(목). 위 테스트와 1초 차이로 값이 갈린다.
     *
     * 다음 경계는 7/17(금 제헌절 휴장)·주말을 건너뛴 7/20(월) 15:30 — 나흘간 기준일은 7/16 하나로 고정된다.
     */
    #[Test]
    public function test_prev_close_thursday_exactly1530_uses_thursday_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:30:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-07-15 15:00' => 1471.15,
            '2026-07-16 15:00' => self::CLOSE_0716,
        ])->getUsdKrw();

        $this->assertSame(1479.78, $result['prev_close'], '15:30 이 지난 순간 그날 종가가 기준이 된다');
        $this->assertSame('2026-07-20 15:30:00', $this->expiresAt($ttl()), '7/17(제헌절 휴장)·주말을 건너뛴 7/20(월) 15:30 이 다음 경계');
    }

    /**
     * 금요일 종가 뒤: 다음 경계는 토·일을 건너뛴 월요일 15:30 — 주말 내내 기준일은 7/24(금) 하나로 고정.
     */
    #[Test]
    public function test_prev_close_friday_after_close_ttl_skips_weekend_to_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 15:31:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-07-23 15:00' => 1482.30,
            '2026-07-24 15:00' => 1484.24,
        ])->getUsdKrw();

        $this->assertSame(1484.24, $result['prev_close']);
        $this->assertSame('2026-07-27 15:30:00', $this->expiresAt($ttl()), '주말엔 경계가 없다 → 월요일 15:30');
    }

    /**
     * 토요일 정오: 15:30 이 안 지났지만 토요일은 영업일이 아니다 → 금요일(7/24)로 소급. 경계는 월요일.
     */
    #[Test]
    public function test_prev_close_saturday_uses_friday_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-07-23 15:00' => 1482.30,
            '2026-07-24 15:00' => 1484.24,
        ])->getUsdKrw();

        $this->assertSame(1484.24, $result['prev_close'], '토요일엔 전날(금) 종가가 여전히 최신 종가다');
        $this->assertSame('2026-07-27 15:30:00', $this->expiresAt($ttl()));
    }

    /**
     * 월요일 개장 전: 오늘 종가는 아직 없고 어제·그제는 주말 → 금요일(7/24)까지 이틀을 거슬러야 한다.
     *
     * 주말 스킵에 실제로 이빨이 있는 유일한 기준일 케이스다 — 토요일 케이스는 '토−1일=금' 이라
     * 영업일 스캔이 통째로 빠져도 우연히 통과한다(뮤테이션으로 실측). 여기선 −1일이면 일요일(봉 없음)이라 반드시 깨진다.
     */
    #[Test]
    public function test_prev_close_monday_before_close_skips_weekend_back_to_friday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-07-23 15:00' => 1482.30,
            '2026-07-24 15:00' => 1484.24,
        ])->getUsdKrw();

        $this->assertSame(1484.24, $result['prev_close'], '주말을 건너뛰어 금요일(7/24) 종가여야 한다');
        $this->assertSame('2026-07-27 15:30:00', $this->expiresAt($ttl()), '오늘(월) 15:30 이 다음 경계');
    }

    /**
     * 설연휴 한복판(2/17 화, 휴장): 2/16~2/18 휴장 + 주말을 건너뛰어 2/13(금)까지 소급. 경계는 2/19(목).
     * 단순 '-1일' 로는 절대 안 나오는 값이다.
     */
    #[Test]
    public function test_prev_close_mid_seollal_holiday_falls_back_to_friday_before_holiday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-17 12:00:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([
            '2026-02-12 15:00' => 1450.02,
            '2026-02-13 15:00' => 1452.13,
        ])->getUsdKrw();

        $this->assertSame(1452.13, $result['prev_close'], '연휴 4일을 거슬러 2/13(금) 종가여야 한다');
        $this->assertSame('2026-02-19 15:30:00', $this->expiresAt($ttl()), '연휴가 끝나는 2/19(목) 15:30 이 다음 경계');
    }

    /**
     * range 불변성: 봉 개수(range)가 달라도 15:00 봉이 같으면 같은 값. 옛 chartPreviousClose 는 여기서 46원 흔들렸다.
     */
    #[Test]
    public function test_prev_close_same_value_regardless_of_range_width(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 09:00:00', 'Asia/Seoul'));

        // 5d 상당 — 며칠치만
        $narrow = $this->makeService([
            '2026-07-15 15:00' => 1471.15,
            '2026-07-16 15:00' => self::CLOSE_0716,
        ])->getUsdKrw();

        Cache::flush();  // 캐시 히트로 같은 값이 나오는 '가짜 일치' 방지 — 두 번 다 실제로 봉을 집게 한다

        // 60d 상당 — 앞뒤로 봉이 훨씬 많다(옛 로직이라면 여기서 값이 달라졌다)
        $wide = $this->makeService([
            '2026-05-20 15:00' => 1390.00,
            '2026-06-30 15:00' => 1425.40,
            '2026-07-14 15:00' => 1468.88,
            '2026-07-15 15:00' => 1471.15,
            '2026-07-16 15:00' => self::CLOSE_0716,
        ])->getUsdKrw();

        $this->assertSame(1479.78, $narrow['prev_close']);
        $this->assertSame(1479.78, $wide['prev_close'], 'range 를 넓혀도 같은 봉을 집어야 한다');
    }

    /**
     * Yahoo 실패 → prev_close 는 null(graceful, 환율 자체는 계속 나온다) + 실패분 TTL 은 120초.
     * 실패를 장TTL(다음 15:30)로 박으면 최대 며칠간 '전일대비 없음' 이 고착된다.
     */
    #[Test]
    public function test_prev_close_yahoo_fails_null_and_short_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 09:00:00', 'Asia/Seoul'));
        $ttl = $this->captureTtl();

        $result = $this->makeService([])->getUsdKrw();  // 봉 없음 = Yahoo 실패

        $this->assertNotNull($result, '전일 종가가 없어도 환율 자체는 반환해야 한다');
        $this->assertSame(1484.1, $result['rate']);
        $this->assertNull($result['prev_close']);
        // 성공분이면 다음 15:30 까지 23400초 — 실패분은 120초여야 자가치유한다
        $this->assertSame(120, $ttl(), '실패분을 장TTL 로 박으면 안 된다');
    }

    /**
     * 불변식: 캐시가 살아있는 동안 기준일은 안 바뀐다 — ref(T) == ref(T + ttl(T) - 1s).
     *
     * 즉 TTL 이 경계를 넘지 않는다(stale 0). 7월 2주 + 설연휴 구간을 1시간 격자로 훑는다.
     * 날짜마다 종가를 다르게 둬(1400 + 통산일) 기준일이 반환값에 1:1로 드러나게 했다.
     */
    #[Test]
    public function test_prev_close_ref_date_is_constant_while_cache_lives(): void
    {
        $bars = [];
        foreach ([['2026-02-09', '2026-02-27'], ['2026-07-06', '2026-07-31']] as $span) {
            for ($d = Carbon::parse($span[0], 'Asia/Seoul'); $d->lte(Carbon::parse($span[1], 'Asia/Seoul')); $d->addDay()) {
                $bars[$d->toDateString() . ' 15:00'] = 1400.0 + $d->dayOfYear;
            }
        }
        $service = $this->makeService($bars);

        $ttl = $this->captureTtl();

        foreach ([['2026-02-13 00:00', '2026-02-23 00:00'], ['2026-07-13 00:00', '2026-07-27 00:00']] as $span) {
            $t = Carbon::parse($span[0], 'Asia/Seoul');
            $end = Carbon::parse($span[1], 'Asia/Seoul');

            for (; $t->lte($end); $t->addHour()) {
                Carbon::setTestNow($t->copy());
                $atT = $service->getUsdKrw()['prev_close'];

                Carbon::setTestNow($t->copy()->addSeconds($ttl())->subSecond());
                $atExpiry = $service->getUsdKrw()['prev_close'];

                $this->assertSame($atT, $atExpiry, "캐시 만료 직전에 기준일이 바뀌었다 (T={$t->toDateTimeString()} KST, ttl={$ttl()}s) — 그 사이 15:30 경계를 넘겼다");
            }
        }
    }

    /** 캡처한 TTL 이 가리키는 만료 시각(KST) — 기대값을 스펙 표 그대로 'Y-m-d H:i:s' 로 비교하기 위함. */
    private function expiresAt(?int $ttl): string
    {
        return Carbon::now('Asia/Seoul')->addSeconds((int) $ttl)->toDateTimeString();
    }
}
