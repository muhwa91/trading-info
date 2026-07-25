<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use App\Services\Toss\TossFxProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * USD→KRW 환율 관리 서비스.
 *
 * 소스 우선순위 (Phase 2 교체 후):
 *   1순위 — 토스증권 Open API (/api/v1/exchange-rate)
 *   2순위 — DB 직전 캐시값 (신선도 FX_STALE_SECONDS 이내)
 *   3순위 — DB 최후 저장값 (신선도 만료여도 반환, 완전 폴백)
 *
 * exchange_rates 테이블에 (from_currency, to_currency) 고유 키로 최신 1건 upsert.
 * 신선도: FX_STALE_SECONDS(기본 300초=5분) 이내 데이터는 외부 호출 생략.
 *
 * 설계 참조: docs/기능/토스_API_이관/01_계획.md §2.2 (FxService 행), Phase 2.
 */
class FxService
{
    /** 환율 신선도 임계값(초). 5분. */
    private const FX_STALE_SECONDS = 300;

    private const FROM = 'USD';

    private const TO = 'KRW';

    /** USD/KRW 전일 종가 캐시 키 — 하루 1회 갱신(서울 외환시장 종가 경계). */
    private const FX_PREV_CLOSE_CACHE_KEY = 'yahoo_fx_prev_close_usdkrw';

    /**
     * Yahoo Finance v8 chart endpoint — USDKRW=X 30분봉(전 영업일 15:30 KST 환율 소스).
     *
     * interval=30m 인 이유: 15:30 KST 를 봉 경계로 갖는 가장 성긴 간격이다. 60m 는 정각 각인이라
     *   15:30 이 아예 없고(실측 0개), 5m·15m 는 payload 만 커진다(5m/1mo 505KB vs 30m/1mo 89KB).
     *   경계 정밀도는 간격과 무관하다 — 15:00 봉의 close 는 어느 간격이든 '15:30 직전 마지막 호가'다.
     * range=1mo(≈22 영업일): 연휴로 전 영업일이 멀어져도 닿는 여유분. 값은 range 에 의존하지 않는다
     *   — 날짜+시각 정확 일치로 봉을 집으므로 range 는 그 봉이 창 안에 들어오기만 하면 된다.
     */
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/USDKRW=X?interval=30m&range=1mo';

    /** 서울 외환시장 종가 시각(KST) — 개발자 결정: '전일' = 서울 외환시장 전 영업일 종가. */
    private const SEOUL_FX_CLOSE_HOUR = 15;

    private const SEOUL_FX_CLOSE_MINUTE = 30;

    /**
     * 종가 시각(15:30)을 끝점으로 갖는 30분봉의 시작 시각(KST).
     *
     * 봉 timestamp = 시작점이므로 [15:00,15:30) 봉의 close = 15:30 직전 마지막 호가 = 서울 종가.
     * (15:30 각인 봉은 [15:30,16:00) 이라 그 close 는 16:00 값 — 쓰면 30분 밀린다.)
     */
    private const SEOUL_FX_CLOSE_BAR = '15:00';

    /** 영업일 탐색 상한(일) — 최장 연휴(설·추석+주말)를 넘는 여유. 무한루프 방지. */
    private const BUSINESS_DAY_SCAN_LIMIT = 12;

    /** Yahoo 전일 종가 요청 타임아웃(초). */
    private const YAHOO_TIMEOUT = 5;

    /**
     * 전일 종가 취득 실패 시 sentinel(0.0) 캐시의 짧은 TTL(초) — TossChangeCalculator::KR_CLOSE_FAIL_TTL 과 동일 패턴.
     *
     * 실패를 정상 TTL(런던 자정까지)로 박아두면 최대 24시간 '전일대비 없음'이 고착된다. 120초만 눌러
     * 매 요청 Yahoo 5초 타임아웃을 반복하는 것만 막고, 다음 사이클에 자가치유시킨다.
     */
    private const FX_PREV_FAIL_TTL = 120;

    private TossFxProvider $tossFxProvider;

    /** Yahoo 봉 조회용 HTTP 클라이언트. 선택 주입 — 테스트에서 MockHandler 로 갈아끼워 네트워크 없이 검증한다. */
    private Client $http;

    /** 영업일 판정용. 선택 주입 — 미주입 시 컨테이너에서 해석(기존 `new FxService($provider)` 호출부 보존). */
    private MarketSessionService $session;

    public function __construct(
        TossFxProvider $tossFxProvider,
        ?Client $http = null,
        ?MarketSessionService $session = null
    ) {
        $this->tossFxProvider = $tossFxProvider;
        $this->http = $http ?? new Client;
        $this->session = $session ?? app(MarketSessionService::class);
    }

    /**
     * USD→KRW 최신 환율 반환.
     *
     * 신선하면 DB값 그대로, 오래됐으면:
     *   1. 토스 API 호출
     *   2. 실패 시 DB 직전값 (신선도 무관)
     *   3. DB도 없으면 null
     *
     * 반환 배열에는 전일 종가 prev_close(서울 외환시장 전 영업일 종가 15:30 KST, float|null)가 항상 포함된다.
     * 프론트의 "환율 전일 대비 ▲/▼" 표시용 — 취득 실패 시 null(graceful).
     *
     * @return array{rate:float,recorded_at:string,source:string,prev_close:float|null}|null
     */
    public function getUsdKrw(): ?array
    {
        // 전일 종가는 하루 단위로 천천히 변하는 값 — 캐시 히트가 대부분(1일 1회 HTTP).
        $prevClose = $this->fetchPrevClose();

        $row = ExchangeRate::where('from_currency', self::FROM)
            ->where('to_currency', self::TO)
            ->first();

        $staleThreshold = Carbon::now()->subSeconds(self::FX_STALE_SECONDS);

        // DB 값이 신선하면 외부 호출 생략
        if ($row !== null && $row->recorded_at !== null && $row->recorded_at->gt($staleThreshold)) {
            return [
                'rate' => (float) $row->rate,
                'recorded_at' => $row->recorded_at->toDateTimeString(),
                'source' => (string) ($row->source ?? 'cached'),
                'prev_close' => $prevClose,
            ];
        }

        // ── 1순위: 토스 환율 API ─────────────────────────────────────────
        $fetched = $this->tossFxProvider->fetchUsdKrw();

        // ── 2순위: DB 직전값 (토스 실패 시) ────────────────────────────────
        if ($fetched === null) {
            Log::warning('[FxService] 토스 환율 취득 실패 — DB 직전값 사용');
            if ($row !== null) {
                return [
                    'rate' => (float) $row->rate,
                    'recorded_at' => $row->recorded_at ? $row->recorded_at->toDateTimeString() : null,
                    'source' => 'db_fallback',
                    'prev_close' => $prevClose,
                ];
            }

            // ── 3순위: DB 완전 폴백 (값은 있지만 신선도 만료) ────────────────
            Log::warning('[FxService] DB값 없음 — 환율 취득 불가');

            return null;
        }

        $this->upsertRate($fetched['rate'], $fetched['recorded_at'], $fetched['source']);

        $fetched['prev_close'] = $prevClose;

        return $fetched;
    }

    /**
     * USD/KRW 전일 종가 = **서울 외환시장 전 영업일 종가(15:30 KST)** (2026-07-17 개발자 결정).
     *
     * '전일'의 정의: 네이버·국내 매체가 쓰는 서울 외환시장 관행. 토스 /exchange-rate 는 rateChangeType(UP/DOWN)만
     *   주고 기준가를 안 줘서 그쪽에 맞출 수 없다 → 서울 종가 관행으로 확정.
     *
     * 왜 chartPreviousClose(옛 소스)를 버렸나: '요청 range 시작 직전' 값이라 range 마다 값이 달라진다
     *   (실측 같은 순간 2d→1487.88 · 5d→1505.91 · 3mo→1474.06, 46원 편차). TossPriceFetcher 는 이미 같은 이유로
     *   이 필드를 배척해 놨는데 여기만 쓰고 있었다. 지금은 '어느 봉'인지 날짜+시각으로 특정하므로 range 무관.
     *
     * 값이 바뀌는 경계는 **영업일 15:30 KST 하나뿐**이다 — prev 는 '이미 지나간 마지막 영업일 15:30'의 함수라
     *   두 15:30 사이에선 상수다(런던 자정·ET 자정·KST 자정 전부 경계가 아니다). TTL 은 그 경계 하나에 맞춘다.
     *
     * @return float|null 실패·해당 봉 없음 시 null (예외 전파 금지 — 기존 폴백 스타일 유지)
     */
    private function fetchPrevClose(): ?float
    {
        $cached = Cache::get(self::FX_PREV_CLOSE_CACHE_KEY);
        if ($cached !== null) {
            return (float) $cached > 0 ? (float) $cached : null;
        }

        $refDate = $this->lastSeoulFxCloseDate();
        $prev = $refDate !== null ? $this->fetchYahooCloseAt($refDate) : null;

        // 성공 = 다음 영업일 15:30(값이 바뀌는 유일한 경계)까지 · 실패 = sentinel 0.0 을 120초만(장TTL 고착 금지).
        Cache::put(
            self::FX_PREV_CLOSE_CACHE_KEY,
            $prev ?? 0.0,
            $prev !== null ? $this->secondsUntilNextSeoulFxClose() : self::FX_PREV_FAIL_TTL
        );

        return $prev;
    }

    /**
     * '종가가 이미 확정된 마지막 영업일'(KST, Y-m-d). 없으면 null.
     *
     * 오늘 15:30 이 아직 안 지났으면 어제부터 거슬러 찾는다 — 15:30 이 지나야 그날 종가가 생기기 때문.
     * 주말·공휴일은 영업일이 아니라 자연히 직전 영업일로 소급된다(연휴도 상한까지 반복).
     */
    private function lastSeoulFxCloseDate(): ?string
    {
        $now = Carbon::now('Asia/Seoul');
        $day = $now->copy()->startOfDay();

        if ($now->lt($this->seoulFxCloseOn($now))) {
            $day->subDay();  // 오늘 15:30 전 → 오늘 종가는 아직 없다
        }

        for ($i = 0; $i < self::BUSINESS_DAY_SCAN_LIMIT; $i++) {
            if ($this->session->isKrTradingDay($day->getTimestamp())) {
                return $day->toDateString();
            }
            $day->subDay();
        }

        Log::warning('[FxService] 최근 영업일 탐색 실패 — 캘린더 이상');

        return null;
    }

    /**
     * 다음 영업일 15:30(KST)까지 남은 초 = 전일 종가의 의미가 바뀌는 다음(그리고 유일한) 경계.
     *
     * 하한 없음(max(...,1)): 300초 하한은 방어가 아니라 '경계를 5분 넘겨 살아남는 stale' 의 원인이다
     * (US·KR 경로에서 같은 이유로 이미 제거·검증됨). 경계 직전 짧은 TTL 은 정상 — 그게 경계다.
     *
     * diffInSeconds 를 쓰지 않고 타임스탬프 차를 쓴다: Carbon::diffInSeconds 는 서머타임 전환일(23h·25h)에도
     * 벽시계 기준 86400 을 돌려준다(실측 2026-03-29 런던 = 23h 인데 86400). KST 는 무DST 라 지금은 무해하지만,
     * 경계를 넘겨 살아남는 stale 은 이 파일이 고친 버그 그 자체라 애초에 그 함수를 쓰지 않는다.
     */
    private function secondsUntilNextSeoulFxClose(): int
    {
        $now = Carbon::now('Asia/Seoul');
        $close = $this->seoulFxCloseOn($now);

        if ($close->lte($now)) {
            $close->addDay();  // 오늘 15:30 이 지났으면 내일 이후에서 찾는다
        }

        for ($i = 0; $i < self::BUSINESS_DAY_SCAN_LIMIT; $i++) {
            if ($this->session->isKrTradingDay($close->getTimestamp())) {
                return max($close->getTimestamp() - $now->getTimestamp(), 1);
            }
            $close->addDay();
        }

        Log::warning('[FxService] 다음 영업일 탐색 실패 — 캘린더 이상, 짧게 재시도');

        return self::FX_PREV_FAIL_TTL;
    }

    /** 해당 날짜의 서울 외환시장 종가 시각(15:30 KST). */
    private function seoulFxCloseOn(Carbon $day): Carbon
    {
        return $day->copy()->setTime(self::SEOUL_FX_CLOSE_HOUR, self::SEOUL_FX_CLOSE_MINUTE, 0);
    }

    /**
     * Yahoo 30분봉에서 지정 영업일(KST)의 15:30 시점 환율을 반환. 실패·해당 봉 없음 → null(graceful).
     *
     * [15:00,15:30) 봉의 close = 15:30 직전 마지막 호가 = 서울 종가. 날짜+시각 정확 일치로 집으므로
     * range 를 넓혀도 같은 봉이 잡힌다(range 의존 없음).
     *
     * @param  string  $kstDate  'Y-m-d'(KST) — 기준 영업일
     */
    private function fetchYahooCloseAt(string $kstDate): ?float
    {
        try {
            $res = $this->http->get(self::YAHOO_CHART_URL, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'http_errors' => false,
                'timeout' => self::YAHOO_TIMEOUT,
            ]);

            $data = json_decode((string) $res->getBody(), true);
            $result = $data['chart']['result'][0] ?? null;
            $timestamps = is_array($result) ? ($result['timestamp'] ?? null) : null;
            $closes = is_array($result) ? ($result['indicators']['quote'][0]['close'] ?? null) : null;

            if (! is_array($timestamps) || ! is_array($closes)) {
                Log::debug('[FxService] Yahoo USDKRW=X 30분봉 시계열 없음');

                return null;
            }

            foreach ($timestamps as $i => $ts) {
                $close = $closes[$i] ?? null;
                if ($close === null || (float) $close <= 0.0) {
                    continue;  // 결측 봉
                }
                $bar = Carbon::createFromTimestamp((int) $ts, 'Asia/Seoul');
                if ($bar->toDateString() === $kstDate && $bar->format('H:i') === self::SEOUL_FX_CLOSE_BAR) {
                    return (float) $close;
                }
            }

            Log::debug("[FxService] Yahoo USDKRW=X {$kstDate} 15:30 봉 없음");

            return null;
        } catch (\Throwable $e) {
            Log::warning('[FxService] USD/KRW 전 영업일 종가 취득 실패: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * exchange_rates upsert: (from_currency, to_currency) 기준 최신 1건 유지.
     */
    public function upsertRate(float $rate, string $recordedAt, string $source = 'unknown'): void
    {
        DB::table('exchange_rates')->upsert(
            [
                'from_currency' => self::FROM,
                'to_currency' => self::TO,
                'rate' => $rate,
                'recorded_at' => $recordedAt,
                'source' => $source,
            ],
            ['from_currency', 'to_currency'],
            ['rate', 'recorded_at', 'source']
        );
    }
}
