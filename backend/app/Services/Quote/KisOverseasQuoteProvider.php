<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\Stock;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 해외(US) 종목 현재가 — KIS 해외주식 현재가(HHDFS00000300) 재사용.
 *
 * 세션별 동작:
 *   정규장(09:30~16:00 ET) — NAS/NYS/AMS. Blue Ocean 완전 배제.
 *   프리마켓·애프터마켓     — NAS/NYS/AMS 우선, BAQ/BAY/BAA 폴백.
 *              실측(2026-06-24): 애프터마켓에서 NAS.last ≈ Yahoo 1분봉, BAQ는 ~$60 stale.
 *              → current_price = NAS.last(정확한 애프터가), regular_close = Yahoo regularMarketPrice.
 *   주간거래(20:00~04:00 ET) — BAQ/BAY/BAA(Blue Ocean ATS) 우선, NAS/NYS/AMS 폴백.
 *              Blue Ocean이 실제 야간거래 venue. NAS는 이 시간대 전일종가(stale)만 반환.
 *
 * KIS HHDFS00000300 응답 주요 필드:
 *   last  : 현재가 (정규장=실시간, 장외=연장 체결가, 장마감=마지막 거래가)
 *   base  : 기준가 (전일 정규장 종가 — 오늘 정규장 종가가 아님. regular_close 에 쓰지 않음)
 *   diff  : 전일 대비 등락
 *   rate  : 전일 대비 등락률
 *   sign  : 1상한/2상승/3보합/4하한/5하락
 *
 * regular_close 출처:
 *   Yahoo Finance v8 chart meta.regularMarketPrice (가장 최근 완료된 정규장 종가).
 *   - 애프터/야간 세션: 오늘 정규장 종가 (예: MU=1,051.77)  ← 버그 수정 핵심
 *   - 프리마켓 세션  : 어제 정규장 종가 (기존 KIS base 와 동일하게 정확)
 *   Yahoo 실패 시: KIS output.base(전일종가) 폴백 → 장전 손익 0 허용 (기존 동작 보존).
 *
 * 거래소 선택 규칙:
 *   나스닥(NAS) 상장 → Blue Ocean: BAQ
 *   NYSE(NYS)  상장 → Blue Ocean: BAY
 *   AMEX(AMS)  상장 → Blue Ocean: BAA
 *   KIS 내부에서 상장 거래소를 직접 알 수 없으므로 BAQ → BAY → BAA 순 폴백.
 *
 * 기존 StockController::fetchOverseasPriceFromKis 로직을 서비스로 추출.
 * 원본 컨트롤러 메서드는 그대로 유지(기존 엔드포인트 회귀 방지).
 */
class KisOverseasQuoteProvider implements QuoteProviderInterface
{
    /** 정규장·프리마켓·애프터마켓 거래소 코드 순서 (NAS 우선) */
    private const EXCHANGES_REGULAR = ['NAS', 'NYS', 'AMS'];

    /** 주간거래(Blue Ocean ATS) 우선 코드 순서 — ET 20:00~04:00 전용 */
    private const EXCHANGES_OVERNIGHT = ['BAQ', 'BAY', 'BAA'];

    /** KIS 액세스 토큰(캐시). */
    private function getAccessToken(bool $forceRefresh = false): ?string
    {
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token !== null) {
            return $token;
        }

        $lock = Cache::lock('kis_token_lock', 15);

        try {
            $attempts = 0;
            while (!$lock->get() && $attempts < 10) {
                usleep(500000);
                $token = Cache::get('kis_access_token');
                if ($token !== null) {
                    return $token;
                }
                $attempts++;
            }

            $token = Cache::get('kis_access_token');
            if ($token !== null) {
                return $token;
            }

            $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey = env('KIS_APP_KEY');
            $appSec = env('KIS_APP_SECRET');

            if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
                return null;
            }

            $client   = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey'     => $appKey,
                    'appsecret'  => $appSec,
                ],
                'headers' => ['content-type' => 'application/json'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            Log::error('[KisOverseasQuoteProvider] 토큰 발급 실패: ' . ($data['msg1'] ?? 'Unknown'));
            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * 현재 미국 시장 세션을 판정한다.
     *
     * StockController::getUsMarketSessionInfo() 와 동일한 로직.
     * 서비스 레이어에서도 동일하게 적용하기 위해 중복 구현한다.
     *
     * 반환값:
     *   '정규장'      — 09:30~16:00 ET (거래일에만)
     *   '프리마켓'    — 04:00~09:30 ET (거래일에만)
     *   '애프터마켓'  — 16:00~20:00 ET (거래일에만)
     *   '주간거래'    — 20:00~04:00 ET (다음날, 주말 경계 제외)
     *   '장마감'      — 주말·공휴일 등 거래 없음
     */
    private function resolveUsMarketSession(): string
    {
        $now = time();
        $dt  = new \DateTime("@{$now}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N'); // 1=월 … 7=일
        $hour      = (int)$dt->format('H');
        $minute    = (int)$dt->format('i');
        $timeVal   = $hour * 100 + $minute;

        // 주말 휴장 경계: 금 20:00 이후 ~ 일 20:00 이전
        if ($dayOfWeek === 6) {
            return '장마감';
        }
        if ($dayOfWeek === 7 && $timeVal < 2000) {
            return '장마감';
        }
        if ($dayOfWeek === 5 && $timeVal >= 2000) {
            return '장마감';
        }

        // 거래시간(서머타임/KST, 증권사 기준): 주간 09:00~16:30 / 프리 17:00~22:30 /
        // 정규 22:30~익일05:00 / 애프터 05:00~08:30 → ET 경계로 환산해 판정.

        // 주간거래(Blue Ocean ATS): ET 20:00~익일 03:30 — 다음 거래일로 이어지는 세션(주말만 경계).
        if ($timeVal >= 2000 || $timeVal < 330) {
            return '주간거래';
        }

        // 데이 세션(프리/정규/애프터): 미국 거래일 여부를 Yahoo SPY meta 로 확인
        if (!$this->isUsMarketTradingToday()) {
            return '장마감';
        }

        if ($timeVal >= 400 && $timeVal < 930) {
            return '프리마켓';
        }
        if ($timeVal >= 930 && $timeVal < 1600) {
            return '정규장';
        }
        if ($timeVal >= 1600 && $timeVal < 1930) {
            return '애프터마켓';
        }
        // ET 03:30~04:00, 19:30~20:00 공백 → 장마감
        return '장마감';
    }

    /**
     * Yahoo Finance 의 SPY currentTradingPeriod.regular.start 로 미국 거래일 여부 판정.
     *
     * StockController::isUsMarketTradingToday() 와 동일한 로직.
     * API 실패 시 주말 여부로 폴백(공휴일 식별 불가).
     */
    private function isUsMarketTradingToday(): bool
    {
        $nyTz     = new \DateTimeZone('America/New_York');
        $todayNy  = (new \DateTime('now', $nyTz))->format('Y-m-d');
        $cacheKey = "us_trading_day_{$todayNy}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        try {
            $client   = new Client();
            $response = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/SPY?interval=1d&range=1d', [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
                'timeout' => 5,
            ]);
            $data         = json_decode($response->getBody()->getContents(), true);
            $regularStart = $data['chart']['result'][0]['meta']['currentTradingPeriod']['regular']['start'] ?? null;

            if ($regularStart !== null) {
                $startDt = new \DateTime("@{$regularStart}");
                $startDt->setTimezone($nyTz);
                $isTradingDay = ($startDt->format('Y-m-d') === $todayNy);

                $midnight = new \DateTime('tomorrow', $nyTz);
                $ttl      = max($midnight->getTimestamp() - time(), 300);
                Cache::put($cacheKey, $isTradingDay, $ttl);
                return $isTradingDay;
            }
        } catch (\Throwable $e) {
            Log::warning('[KisOverseasQuoteProvider] isUsMarketTradingToday Yahoo 실패, 주말 폴백: ' . $e->getMessage());
        }

        // 폴백: API 불가 시 주말 여부만 확인 (공휴일 식별 불가 → 짧게 캐싱)
        $dow         = (int)(new \DateTime('now', $nyTz))->format('N');
        $isWeekday   = ($dow <= 5);
        Cache::put($cacheKey, $isWeekday, 300);
        return $isWeekday;
    }

    /**
     * KIS HHDFS00000300 해외주식 현재가 조회.
     *
     * 세션별 거래소 선택:
     *   정규장 시간대(정규장/장마감)
     *     → NAS → NYS → AMS 순 조회
     *     → last = 정규장 현재가(또는 마지막 거래가)
     *     → 장전 손익 = 0 (의도된 동작)
     *
     *   연장 시간대(프리마켓 / 애프터마켓 / 주간거래)
     *     → BAQ → BAY → BAA (Blue Ocean ATS) 우선 조회
     *     → last = 연장 현재가
     *     → 모두 실패 시 NAS → NYS → AMS 폴백 (이 경우 장전 손익 = 0)
     *
     * 반환 배열:
     *   price          : 연장 현재가 (정규장이면 정규장 현재가)
     *   regular_close  : 가장 최근 완료된 정규장 종가 (Yahoo regularMarketPrice 우선, 실패 시 KIS base 폴백)
     *   change_amount  : 전일 대비 등락 (base 기준, 부호 적용)
     *   change_percent : 전일 대비 등락률
     *   recorded_at    : 조회 시각
     *
     * @return array{
     *   price: float,
     *   regular_close: float,
     *   change_amount: float,
     *   change_percent: float,
     *   recorded_at: string
     * }|null
     */
    public function fetchQuote(Stock $stock, string $session): ?array
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSec = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
            Log::info('[KisOverseasQuoteProvider] KIS 키 미설정 — null 반환');
            return null;
        }

        $symbol      = $stock->symbol;
        $fallbackKey = "kis_last_successful_overseas_price_{$symbol}";

        // ── 세션 판정 → 거래소 시도 순서 결정 ──────────────────────────
        // $session 파라미터(regular/pre/after)는 DashboardController 가 고정값으로 넘기므로,
        // 실제 NY 시각을 직접 판정해 세션 분기 기준을 삼는다.
        //
        // 세션별 올바른 EXCD 우선순위 (2026-06-24 실측 확정):
        //   정규장(09:30~16:00 ET) : NAS/NYS/AMS — Blue Ocean 완전 배제
        //   애프터마켓(16:00~20:00 ET): NAS/NYS/AMS 우선 → BAQ/BAY/BAA 폴백
        //     * 실측: NAS.last≈Yahoo 1분봉(일치), BAQ.last는 $50+ stale → NAS 우선이 정확
        //   프리마켓(04:00~09:30 ET): NAS/NYS/AMS 우선 → BAQ/BAY/BAA 폴백
        //     * 프리장 호가/체결도 NAS ECN 경유가 더 신선 (BAQ는 주간거래 전용 ATS)
        //   주간거래(20:00~04:00 ET): BAQ/BAY/BAA 우선 → NAS/NYS/AMS 폴백
        //     * 야간 Blue Ocean ATS 가 실제 거래 venue — NAS는 이 시간대 stale
        //   장마감·기타: NAS/NYS/AMS 만 (전일종가, 무해)
        $currentSession = $this->resolveUsMarketSession();

        switch ($currentSession) {
            case '정규장':
                // 정규장: Blue Ocean 완전 배제 — stale 장외값 오염 방지
                $exchanges = self::EXCHANGES_REGULAR;
                break;
            case '프리마켓':
            case '애프터마켓':
                // 프리/애프터: NAS 우선, Blue Ocean 폴백
                // (실측: 애프터마켓 NAS.last ≈ Yahoo 1분봉, BAQ는 ~$60 stale)
                $exchanges = array_merge(self::EXCHANGES_REGULAR, self::EXCHANGES_OVERNIGHT);
                break;
            case '주간거래':
                // 야간 주간거래: Blue Ocean 우선 — 실제 ATS 거래 venue
                // NAS는 이 시간대 전일종가(stale)만 반환
                $exchanges = array_merge(self::EXCHANGES_OVERNIGHT, self::EXCHANGES_REGULAR);
                break;
            default:
                // 장마감·기타: 정규장 코드만 (전일종가, 무해)
                $exchanges = self::EXCHANGES_REGULAR;
                break;
        }

        Log::debug(
            "[KisOverseasQuoteProvider] {$symbol} 세션={$currentSession} " .
            "EXCD순서=[" . implode(',', $exchanges) . "]"
        );

        try {
            $accessToken = $this->getAccessToken();
            if ($accessToken === null) {
                return Cache::get($fallbackKey);
            }

            $client = new Client();

            foreach ($exchanges as $exchange) {
                try {
                    $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                        'headers' => [
                            'content-type' => 'application/json',
                            'authorization' => "Bearer {$accessToken}",
                            'appkey'        => $appKey,
                            'appsecret'     => $appSec,
                            'tr_id'         => 'HHDFS00000300',
                        ],
                        'query' => [
                            'AUTH' => '',
                            'EXCD' => $exchange,
                            'SYMB' => $symbol,
                        ],
                        'http_errors' => false,
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);

                    // 토큰 만료(EGW00123) 시 1회 재발급 후 재시도
                    if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                        $accessToken = $this->getAccessToken(true);
                        if ($accessToken === null) {
                            return Cache::get($fallbackKey);
                        }
                        $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                            'headers' => [
                                'content-type' => 'application/json',
                                'authorization' => "Bearer {$accessToken}",
                                'appkey'        => $appKey,
                                'appsecret'     => $appSec,
                                'tr_id'         => 'HHDFS00000300',
                            ],
                            'query' => [
                                'AUTH' => '',
                                'EXCD' => $exchange,
                                'SYMB' => $symbol,
                            ],
                            'http_errors' => false,
                        ]);
                        $data = json_decode($response->getBody()->getContents(), true);
                    }

                    if (!isset($data['output']['last']) || (float)$data['output']['last'] <= 0) {
                        continue;
                    }

                    $output = $data['output'];

                    // ── 핵심 매핑 ────────────────────────────────────────────
                    // last : 현재가 (정규장=실시간가, 연장=연장 체결가)
                    // base : KIS 전일 정규장 종가 (오늘 정규장이 끝나도 다음날까지 갱신 안 됨)
                    //
                    // [버그 수정] regular_close 에 KIS base 를 쓰면:
                    //   애프터마켓에서 base = 전일종가(예: 1,211.38)가 들어가
                    //   "정규장 보조줄"이 오늘 정규장 종가(1,051.77)가 아닌 전일 종가를 쓰게 됨.
                    //   → Yahoo regularMarketPrice = "가장 최근 완료된 정규장 종가" 를 우선 사용.
                    //   → Yahoo 실패 시만 KIS base 폴백 (기존 동작 보존, 회귀 없음).
                    $price = (float)$output['last'];
                    $base  = isset($output['base']) && (float)$output['base'] > 0
                        ? (float)$output['base']
                        : $price; // base 없으면 last 로 폴백

                    // Yahoo regularMarketPrice 우선 — 폴백 체인: Yahoo → base → last
                    $yahooRegularClose = $this->fetchYahooRegularMarketPrice($symbol);
                    $regularClose      = $yahooRegularClose ?? $base;

                    $change = (float)($output['diff'] ?? 0);
                    $pct    = (float)($output['rate'] ?? 0);
                    $sign   = $output['sign'] ?? '3';

                    // sign: 4=하한, 5=하락 → 음수. 1=상한, 2=상승, 3=보합 → 양수.
                    if ($sign === '4' || $sign === '5') {
                        $change = -abs($change);
                        $pct    = -abs($pct);
                    } else {
                        $change = abs($change);
                        $pct    = abs($pct);
                    }

                    $result = [
                        'price'          => $price,
                        'regular_close'  => $regularClose,
                        'change_amount'  => $change,
                        'change_percent' => $pct,
                        'recorded_at'    => now()->toDateTimeString(),
                    ];

                    Log::debug(
                        "[KisOverseasQuoteProvider] {$symbol}@{$exchange} 성공 — " .
                        "price={$price}, regular_close={$regularClose}" .
                        "(yahoo=" . ($yahooRegularClose !== null ? $yahooRegularClose : 'null→base_fallback') . ")" .
                        ", kis_base={$base}, session={$currentSession}"
                    );

                    Cache::put($fallbackKey, $result, 86400);
                    return $result;
                } catch (\Throwable $ex) {
                    Log::debug("[KisOverseasQuoteProvider] {$symbol}@{$exchange} 실패: " . $ex->getMessage());
                    continue;
                }
            }

            // 모든 거래소 실패 → 마지막 성공값 폴백
            Log::warning("[KisOverseasQuoteProvider] {$symbol} 모든 거래소 조회 실패 — 폴백 사용");
            return Cache::get($fallbackKey);
        } catch (\Throwable $e) {
            Log::error("[KisOverseasQuoteProvider] {$symbol} 예외: " . $e->getMessage());
            return Cache::get($fallbackKey);
        }
    }

    /**
     * Yahoo Finance v8 chart meta 에서 regularMarketPrice(가장 최근 완료된 정규장 종가)를 반환한다.
     *
     * 목적:
     *   KIS HHDFS00000300 의 output.base 는 "전일 정규장 종가"다.
     *   애프터마켓에서는 오늘 정규장이 끝났으나 base 에는 어제 종가가 담겨 있어
     *   "정규장 보조줄" 기준가로 쓰면 오차가 생긴다(실측: MU base=1,211.38 vs 오늘종가=1,051.77).
     *   Yahoo regularMarketPrice 는 "가장 최근에 완료된 정규장 종가"이므로
     *   애프터마켓 시간대엔 오늘 종가, 프리마켓 시간대엔 어제 종가를 정확히 반환한다.
     *
     * 캐시 전략:
     *   키: "yahoo_regular_close_{symbol}" — 종목별, 당일 자정(ET)까지 유지.
     *   정규장 종가는 당일 16:00 ET 이후 변하지 않으므로 자정까지 재사용 안전.
     *   프리마켓 중(전날 종가)은 오늘 장 개시 직전까지 유효 — 동일하게 자정 캐시로 커버.
     *
     * 실패 처리:
     *   Yahoo 응답 이상·네트워크 오류 시 null 반환 → 호출부에서 KIS base 폴백.
     *   예외를 바깥으로 전파하지 않는다(graceful 처리).
     *
     * @param  string $symbol  종목 심볼 (예: "MU", "AAPL")
     * @return float|null      정규장 종가. Yahoo 실패 시 null.
     */
    private function fetchYahooRegularMarketPrice(string $symbol): ?float
    {
        $cacheKey = "yahoo_regular_close_{$symbol}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float)$cached;
        }

        try {
            $client   = new Client(['timeout' => 5.0, 'connect_timeout' => 3.0]);
            $response = $client->get(
                "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=1d",
                [
                    'headers'     => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
                    'http_errors' => false,
                ]
            );

            $data  = json_decode($response->getBody()->getContents(), true);
            $price = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;

            if ($price === null || (float)$price <= 0) {
                Log::warning("[KisOverseasQuoteProvider] {$symbol} Yahoo regularMarketPrice 없음 — base 폴백");
                return null;
            }

            $regularClose = (float)$price;

            // 캐시 TTL: 당일 자정(ET)까지. 최소 300초 보장.
            $nyTz     = new \DateTimeZone('America/New_York');
            $midnight = new \DateTime('tomorrow', $nyTz);
            $ttl      = max($midnight->getTimestamp() - time(), 300);
            Cache::put($cacheKey, $regularClose, $ttl);

            Log::debug("[KisOverseasQuoteProvider] {$symbol} Yahoo regularMarketPrice={$regularClose} (TTL={$ttl}s)");

            return $regularClose;
        } catch (\Throwable $e) {
            Log::warning("[KisOverseasQuoteProvider] {$symbol} Yahoo regularMarketPrice 조회 실패: " . $e->getMessage());
            return null;
        }
    }
}
