<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Toss\TossApiClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 미국/한국 시장 세션 판정 서비스.
 *
 * StockController 에 있던 세션 관련 private 메서드들을 추출·공개한 클래스.
 * Cache 키는 StockController 와 동일해 캐시가 공유된다:
 *   - US 거래일: us_trading_day_{Y-m-d}  (NY 기준)
 *   - KR 거래일: kis_trading_day_{Ymd}   (Seoul 기준, 7일 TTL)
 *   - US 세션창: toss_us_session_windows_{Ymd} (KST 기준, 7일 TTL)
 */
class MarketSessionService
{
    /** US 세션 4창 캐시 키 prefix — KST 날짜(Ymd) 로 키잉된다(토스가 KST 절대시각으로 주므로) */
    private const US_WINDOWS_PREFIX = 'toss_us_session_windows_';

    /** US 캘린더 재조회 스로틀 키 */
    private const US_CALENDAR_THROTTLE_KEY = 'toss_us_calendar_fetch_throttle';

    /** US 캘린더 재조회 최소 간격(초) — 형제 TossChangeCalculator::KR_CLOSE_FAIL_TTL 과 동형 */
    private const US_CALENDAR_RETRY_TTL = 120;

    private TossApiClient $tossApiClient;

    public function __construct(TossApiClient $tossApiClient)
    {
        $this->tossApiClient = $tossApiClient;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 unix timestamp 기준의 미국 시장 세션명을 반환한다.
     *
     * 반환값: '주간거래' | '프리마켓' | '정규장' | '애프터마켓' | '장마감'
     *
     * 1순위 = 토스 마켓캘린더(`/api/v1/market-calendar/US`) — 세션 4창을 **KST 절대시각**으로 주므로
     *   DST·조기폐장·공휴일이 전부 캘린더에 내장된다(스키마·함정: docs/기능/토스_API_이관/03_구현_검증.md).
     * 2순위 = ET 하드코딩 폴백 — 토스는 허용 IP 기반이라 미등록 네트워크에선 전 호출이 `[]` 가 된다
     *   (CLAUDE.md 실사례). 캘린더가 확답 못 하는 날짜(커버 범위 밖)도 여기로 떨어진다.
     */
    public function getUsSession(int $timestamp): string
    {
        return $this->getUsSessionFromCalendar($timestamp)
            ?? $this->getUsSessionHardcoded($timestamp);
    }

    /**
     * 토스 캘린더 없이 ET 시각만으로 판정하는 폴백.
     *
     * 경계값 출처: 2026-07-17 실측 교정. 옛 상수(주간거래 종료 03:30·애프터 종료 19:30)는 토스 앱
     * 안내 팝업(docs/거래시간.jpg, 이후 삭제)에서 왔는데 토스 자신의 API·캘린더와 어긋났다 —
     * 03:30~04:00 는 91/91분 전부 체결(04:01 거래량 169→44,397 = 진짜 프리마켓 개시)이라 03:30 은 허구,
     * 19:30~20:00 도 전 분 체결(NVDA 19:51 15,249주)이라 애프터 종료는 19:50 이 맞다.
     * 공휴일(거래일 게이트)만 Yahoo SPY meta 에 의존 → 캘린더 경로가 살아있으면 여기까지 오지 않는다.
     */
    private function getUsSessionHardcoded(int $timestamp): string
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int) $dt->format('N'); // 1=Mon … 7=Sun
        $timeVal = (int) $dt->format('H') * 100 + (int) $dt->format('i');

        // 주말 휴장 경계(NY): 금 20:00 ~ 일 20:00 은 어떤 세션도 없음
        if ($dayOfWeek === 6) {
            return '장마감';
        }
        if ($dayOfWeek === 7 && $timeVal < 2000) {
            return '장마감';
        }
        if ($dayOfWeek === 5 && $timeVal >= 2000) {
            return '장마감';
        }

        // 주간거래: ET 20:00 ~ 익일 04:00 — 날짜를 넘나드는 세션이라 거래일 게이트 미적용(주말만).
        if ($timeVal >= 2000 || $timeVal < 400) {
            return '주간거래';
        }

        // 데이 세션(프리/정규/애프터)은 'NY 오늘'이 거래일일 때만 — 공휴일이면 장마감
        if (! $this->isUsMarketTradingToday($timestamp)) {
            return '장마감';
        }

        if ($timeVal >= 400 && $timeVal < 930) {
            return '프리마켓';
        }
        if ($timeVal >= 930 && $timeVal < 1600) {
            return '정규장';
        }
        if ($timeVal >= 1600 && $timeVal < 1950) {
            return '애프터마켓';
        }

        // ET 19:50~20:00 공백 (애프터 종료 ~ 주간거래 개시)
        return '장마감';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal — Toss US market calendar (세션 4창)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 토스 US 캘린더로 세션 판정. 확정 불가 시 null(호출부가 하드코딩 폴백).
     *
     * 토스는 영업일 D 의 4창을 **KST 절대시각**으로 준다:
     *   dayMarket D 09:00~17:00 · preMarket 17:00~22:30 · regularMarket 22:30~D+1 05:00 ·
     *   afterMarket D+1 05:00~08:50  (DST 전환 시 KST 쪽이 1h 이동, ET 는 고정 — 실측)
     * → 어떤 시각을 담을 수 있는 영업일은 그 시각의 KST 날짜 D 와 D−1 **둘뿐**이다.
     * → '다음 영업일 기준' 롤포워드 계산이 캘린더에 내장돼 사라진다(휴장 전야 미개장·휴장일 저녁 개장을
     *    캘린더가 각각 정확히 답한다 — 노동절 9/07 dayMarket=null, 9/08 dayMarket 존재).
     */
    private function getUsSessionFromCalendar(int $timestamp): ?string
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $dates = [$dt->format('Ymd'), (clone $dt)->modify('-1 day')->format('Ymd')];

        $windows = $this->readUsWindows($dates);
        if ($windows === null) {
            $this->fetchAndCacheUsWindows();
            $windows = $this->readUsWindows($dates);
            if ($windows === null) {
                return null;
            }
        }

        foreach ($windows as [$session, $start, $end]) {
            if ($timestamp >= $start && $timestamp < $end) {
                return $session;
            }
        }

        return '장마감';
    }

    /**
     * 캐시된 세션 창 병합. 요청 날짜 중 **하나라도** 미캐시면 null(= 확정 불가 → 폴백).
     *
     * @param  list<string>  $dates  Ymd
     * @return list<array{0:string,1:int,2:int}>|null
     */
    private function readUsWindows(array $dates): ?array
    {
        $merged = [];
        foreach ($dates as $date) {
            $cached = Cache::get(self::US_WINDOWS_PREFIX . $date);
            if (! is_array($cached)) {
                return null; // 휴장일도 빈 배열([])로 캐싱되므로, 미캐시와 구분된다
            }
            $merged = array_merge($merged, $cached);
        }

        return $merged;
    }

    /**
     * `/api/v1/market-calendar/US` 1콜 → [Ymd => 세션창 목록] 캐싱.
     *
     * 파라미터 없이 호출하면 previousBusinessDay·today·nextBusinessDay 가 오고, 이 3일이면 '지금' 근처
     * 전 시각을 커버한다. 연속한 두 영업일 **사이**는 휴장 확정 → 빈 창으로 채운다(KR fillNonTradingGap 동형).
     * 날짜별 확정값이라 7일 캐싱 안전(KR `kis_trading_day_{Ymd}` 패턴).
     *
     * 미캐시 조회마다 HTTP 를 때리지 않도록 시도 자체를 120초 스로틀한다 — 캘린더가 커버 못 하는
     * 오래된 timestamp 로 호출되면 매번 재조회가 되고, 토스 rate-limit 가드의 usleep 이 핫패스(WS 3초
     * 사이클)를 물어뜯는다. 실패 시에도 같은 마커로 재시도 간격이 잡힌다(형제 KR_CLOSE_FAIL_TTL=120 동형).
     */
    private function fetchAndCacheUsWindows(): void
    {
        if (Cache::get(self::US_CALENDAR_THROTTLE_KEY) !== null) {
            return;
        }
        Cache::put(self::US_CALENDAR_THROTTLE_KEY, true, self::US_CALENDAR_RETRY_TTL);

        try {
            $data = $this->tossApiClient->get('/api/v1/market-calendar/US');
            $this->cacheUsWindows($data['result'] ?? null);
        } catch (\Exception $e) {
            Log::error('MarketSessionService: 토스 US 캘린더 조회 실패: ' . $e->getMessage());
        }
    }

    /**
     * 캘린더 result → [Ymd => 세션창] 캐싱. 형식 미인식이면 아무것도 캐싱하지 않는다(→ 폴백).
     *
     * @param  mixed  $result  `$data['result']`
     */
    private function cacheUsWindows($result): void
    {
        if (! is_array($result)) {
            Log::warning('MarketSessionService: 토스 US 캘린더 응답 형식 미인식 — 하드코딩 폴백');

            return;
        }

        $days = [];
        foreach (['previousBusinessDay', 'today', 'nextBusinessDay'] as $key) {
            $node = $result[$key] ?? null;
            if (! is_array($node)) {
                continue;
            }
            $date = $this->toYmd($node['date'] ?? null);
            if ($date !== null) {
                $days[$date] = $this->parseUsDayWindows($node);
            }
        }

        if ($days === []) {
            Log::warning('MarketSessionService: 토스 US 캘린더에 유효 날짜 없음 — 하드코딩 폴백');

            return;
        }

        // 연속한 두 영업일 사이 = 휴장 확정 → 빈 창.
        // Ymd 는 숫자문자열이라 PHP 가 배열 키를 int 로 강제변환한다 → 문자열로 되돌려 쓴다.
        ksort($days);
        $known = array_map('strval', array_keys($days));
        for ($i = 0; $i < count($known) - 1; $i++) {
            $gap = [];
            $this->fillNonTradingGap($gap, $known[$i], $known[$i + 1]);
            foreach (array_keys($gap) as $d) {
                $days[$d] = [];
            }
        }

        foreach ($days as $date => $windows) {
            Cache::put(self::US_WINDOWS_PREFIX . $date, $windows, 60 * 60 * 24 * 7);
        }
    }

    /**
     * 영업일 노드 → 세션창 목록. 휴장일이면 빈 배열.
     *
     * ⚠️ 함정: 휴장일엔 **키가 존재하고 값이 null** 이다 — `{"date":"2026-09-07","dayMarket":null,…}`.
     *   `isset()` 은 여기서 false 지만 `array_key_exists()` 는 true 라 키 유무로 판정하면 안 되고,
     *   값을 `is_array()` 로 봐야 한다(KR 경로의 `integrated !== null` 과 동형).
     *
     * @param  array<string,mixed>  $node
     * @return list<array{0:string,1:int,2:int}> [세션명, 시작ts, 종료ts]
     */
    private function parseUsDayWindows(array $node): array
    {
        $labels = [
            'dayMarket' => '주간거래',
            'preMarket' => '프리마켓',
            'regularMarket' => '정규장',
            'afterMarket' => '애프터마켓',
        ];

        $windows = [];
        foreach ($labels as $key => $label) {
            $window = $node[$key] ?? null;
            if (! is_array($window)) {
                continue;
            }
            $start = strtotime((string) ($window['startTime'] ?? ''));
            $end = strtotime((string) ($window['endTime'] ?? ''));
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }
            $windows[] = [$label, $start, $end];
        }

        return $windows;
    }

    /**
     * 주어진 unix timestamp 기준의 한국 시장 세션명을 반환한다.
     *
     * 반환값: '정규장' | '장마감'
     */
    public function getKrSession(int $timestamp): string
    {
        if (! $this->isKrTradingDay($timestamp)) {
            return '장마감';
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int) $dt->format('Hi'); // e.g. 0900
        if ($timeVal >= 900 && $timeVal <= 1530) {
            return '정규장';
        }

        return '장마감';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // US trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 timestamp(기본: 현재) 의 NY 날짜가 미국 거래일인지 판정한다.
     *
     * Cache 키 `us_trading_day_{Y-m-d}` 는 StockController 와 공유된다.
     * Yahoo SPY meta 는 '지금'의 거래일만 알려주므로, 오늘이 아닌 날짜는 주말 폴백으로만 판정한다.
     * API 실패 시 폴백: 주말 여부(평일=거래일 가정) — 공휴일 하드코딩 없음.
     */
    public function isUsMarketTradingToday(?int $timestamp = null): bool
    {
        $nyTz = new \DateTimeZone('America/New_York');
        $refDt = new \DateTime($timestamp !== null ? "@{$timestamp}" : 'now');
        $refDt->setTimezone($nyTz);
        $refNy = $refDt->format('Y-m-d');
        $todayNy = (new \DateTime('now', $nyTz))->format('Y-m-d');

        $cacheKey = "us_trading_day_{$refNy}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        // Yahoo SPY meta 는 '지금'의 거래 세션만 알려준다 — NY 오늘일 때만 조회, 그 외는 폴백.
        if ($refNy === $todayNy) {
            try {
                $client = new Client;
                // SPY 는 NYSE Arca 상장 ETF — 가벼운 1d/1d 요청으로 meta 만 취득
                $url = 'https://query1.finance.yahoo.com/v8/finance/chart/SPY?interval=1d&range=1d';
                $response = $client->get($url, [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
                    'timeout' => 5,
                ]);
                $data = json_decode($response->getBody()->getContents(), true);

                $regularStart = $data['chart']['result'][0]['meta']['currentTradingPeriod']['regular']['start'] ?? null;
                if ($regularStart !== null) {
                    $startDt = new \DateTime("@{$regularStart}");
                    $startDt->setTimezone($nyTz);
                    $startNyDate = $startDt->format('Y-m-d');

                    $isTradingDay = ($startNyDate === $todayNy);

                    // 오늘 자정(NY)까지 캐싱
                    $midnight = new \DateTime('tomorrow', $nyTz);
                    $ttl = $midnight->getTimestamp() - time();
                    Cache::put($cacheKey, $isTradingDay, max($ttl, 300));

                    return $isTradingDay;
                }
            } catch (\Exception $e) {
                Log::warning('MarketSessionService::isUsMarketTradingToday: Yahoo fetch 실패, 주말 폴백 사용: ' . $e->getMessage());
            }
        }

        // 폴백: 요청 날짜의 주말 여부로만 판정 — 짧게 캐싱해 API 회복 시 갱신
        $isWeekday = ((int) $refDt->format('N')) <= 5;
        Cache::put($cacheKey, $isWeekday, 300);

        return $isWeekday;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // KR trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 timestamp 가 KRX 거래일인지 토스 마켓캘린더 API 로 판정.
     *
     * Cache 키 `kis_trading_day_{Ymd}` 는 하위 호환을 위해 유지한다.
     * API 실패 시 폴백: 주말 + 고정공휴일 판정 (TTL 30분, API 회복 시 갱신).
     */
    public function isKrTradingDay(int $timestamp): bool
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        $dateStr = $dt->format('Ymd');
        $cacheKey = "kis_trading_day_{$dateStr}"; // 캐시 키 하위 호환 유지

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        // 토스 캘린더로 확정 가능한 날짜 조회 (전 영업일 ~ 다음 영업일)
        $opened = $this->fetchTossOpenedDays();
        if (! empty($opened)) {
            // 확정값만 담기므로 7일 캐싱 안전 (폴백 추정치는 아래에서 30분만 캐싱)
            foreach ($opened as $d => $isOpen) {
                Cache::put("kis_trading_day_{$d}", $isOpen, 60 * 60 * 24 * 7);
            }
            if (array_key_exists($dateStr, $opened)) {
                return $opened[$dateStr];
            }
        }

        // 폴백: 주말 + 고정공휴일
        $isWeekday = ((int) $dt->format('N')) <= 5;
        if (! $isWeekday) {
            Cache::put($cacheKey, false, 60 * 30);

            return false;
        }
        $mmdd = $dt->format('md');
        $fixedHolidays = ['0101', '0301', '0505', '0815', '1003', '1009', '1225'];
        $isHoliday = in_array($mmdd, $fixedHolidays, true);
        $result = ! $isHoliday;
        Cache::put($cacheKey, $result, 60 * 30);

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal — Toss market calendar API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 토스 마켓캘린더 API → [Ymd => 거래일여부(bool)] 맵 반환.
     *
     * 응답은 from/to 를 무시하고 **오늘 기준 3일**만 준다:
     *   { result: { today:{date,integrated}, previousBusinessDay:{...}, nextBusinessDay:{...} } }
     *   integrated !== null → 거래일 · integrated === null → 휴장.
     *
     * 이 3일에서 **확정** 가능한 날짜만 맵에 담는다:
     *   - previousBusinessDay.date / nextBusinessDay.date → 거래일(true)
     *   - today → integrated 유무로 true/false
     *   - prev < d < today, today < d < next → 그 사이엔 영업일이 없으므로 비거래일(false)
     * 그 밖의 날짜는 담지 않는다(호출부가 폴백으로 처리).
     *
     * API 실패 또는 응답 형식 미인식 시 빈 배열 반환.
     *
     * @return array<string,bool>
     */
    private function fetchTossOpenedDays(): array
    {
        try {
            // from/to 는 서버가 무시한다(실측) — 항상 '오늘' 기준 3일치가 온다.
            $data = $this->tossApiClient->get('/api/v1/market-calendar/KR');

            if (empty($data)) {
                return [];
            }

            $result = $data['result'] ?? null;
            $todayDate = $this->toYmd($result['today']['date'] ?? null);
            if (! is_array($result) || $todayDate === null) {
                Log::warning('MarketSessionService: 토스 캘린더 응답 형식 미인식', ['keys' => array_keys($data)]);

                return [];
            }

            $prevDate = $this->toYmd($result['previousBusinessDay']['date'] ?? null);
            $nextDate = $this->toYmd($result['nextBusinessDay']['date'] ?? null);

            $map = [];
            if ($prevDate !== null) {
                $map[$prevDate] = true;
                $this->fillNonTradingGap($map, $prevDate, $todayDate);
            }
            $map[$todayDate] = ($result['today']['integrated'] ?? null) !== null;
            if ($nextDate !== null) {
                $this->fillNonTradingGap($map, $todayDate, $nextDate);
                $map[$nextDate] = true;
            }

            return $map;
        } catch (\Exception $e) {
            Log::error('MarketSessionService: 토스 캘린더 조회 실패: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * 토스 응답의 'Y-m-d' 날짜 문자열 → 'Ymd'. 형식이 아니면 null.
     */
    private function toYmd($date): ?string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return str_replace('-', '', $date);
    }

    /**
     * $from 과 $to (둘 다 Ymd, 배타) 사이의 날짜를 비거래일로 채운다.
     * 연속한 두 영업일 사이 = 휴장 확정.
     *
     * @param  array<string,bool>  $map
     */
    private function fillNonTradingGap(array &$map, string $from, string $to): void
    {
        $cursor = date('Ymd', strtotime($from . ' +1 day'));
        while ($cursor < $to) {
            $map[$cursor] = false;
            $cursor = date('Ymd', strtotime($cursor . ' +1 day'));
        }
    }
}
