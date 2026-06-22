<?php

declare(strict_types=1);

namespace App\Services;

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
 */
class MarketSessionService
{
    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 unix timestamp 기준의 미국 시장 세션명을 반환한다.
     *
     * 반환값: '주간거래' | '프리마켓' | '정규장' | '애프터마켓' | '장마감'
     */
    public function getUsSession(int $timestamp): string
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N'); // 1=Mon … 7=Sun
        $timeVal   = (int)$dt->format('H') * 100 + (int)$dt->format('i');

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

        // 주간거래: ET 20:00 ~ 익일 03:30 — 날짜를 넘나드는 세션이라 거래일 게이트 미적용(주말만).
        if ($timeVal >= 2000 || $timeVal < 330) {
            return '주간거래';
        }

        // 데이 세션(프리/정규/애프터)은 'NY 오늘'이 거래일일 때만 — 공휴일이면 장마감
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
        // ET 03:30~04:00, 19:30~20:00 공백
        return '장마감';
    }

    /**
     * 주어진 unix timestamp 기준의 한국 시장 세션명을 반환한다.
     *
     * 반환값: '정규장' | '장마감'
     */
    public function getKrSession(int $timestamp): string
    {
        if (!$this->isKrTradingDay($timestamp)) {
            return '장마감';
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int)$dt->format('Hi'); // e.g. 0900
        if ($timeVal >= 900 && $timeVal <= 1530) {
            return '정규장';
        }

        return '장마감';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // US trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Yahoo Finance SPY meta 로 오늘(NY)이 미국 거래일인지 판정한다.
     *
     * Cache 키 `us_trading_day_{Y-m-d}` 는 StockController 와 공유된다.
     * API 실패 시 폴백: 주말 여부(평일=거래일 가정) — 공휴일 하드코딩 없음.
     */
    public function isUsMarketTradingToday(): bool
    {
        $nyTz     = new \DateTimeZone('America/New_York');
        $todayNy  = (new \DateTime('now', $nyTz))->format('Y-m-d');
        $cacheKey = "us_trading_day_{$todayNy}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        try {
            $client = new Client();
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

        // 폴백: 주말 여부로만 판정 — 짧게 캐싱해 API 회복 시 갱신
        $nyDow    = (int)(new \DateTime('now', $nyTz))->format('N');
        $isWeekday = ($nyDow <= 5);
        Cache::put($cacheKey, $isWeekday, 300);
        return $isWeekday;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // KR trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 timestamp 가 KRX 거래일인지 KIS 국내휴장일조회 API(CTCA0903R)로 판정.
     *
     * Cache 키 `kis_trading_day_{Ymd}` 는 StockController 와 공유된다.
     * API 설정(KIS_APP_KEY) 이 없으면 평일 여부로 폴백.
     */
    public function isKrTradingDay(int $timestamp): bool
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        $dateStr  = $dt->format('Ymd');
        $cacheKey = "kis_trading_day_{$dateStr}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        // KIS 국내휴장일조회 — 한 번에 여러 날짜를 받아 함께 캐싱
        $opened = $this->fetchKisOpenedDays($dateStr);
        if (!empty($opened)) {
            foreach ($opened as $d => $isOpen) {
                Cache::put("kis_trading_day_{$d}", $isOpen, 60 * 60 * 24 * 7); // 7일
            }
            if (array_key_exists($dateStr, $opened)) {
                return $opened[$dateStr];
            }
        }

        // 폴백: API 응답 없을 때만 평일 여부로 판정 (짧게 캐싱해 API 회복 시 갱신)
        $isWeekday = ((int)$dt->format('N')) <= 5;
        Cache::put($cacheKey, $isWeekday, 60 * 30);
        return $isWeekday;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal — KIS holiday API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * KIS 국내휴장일조회(CTCA0903R) → [Ymd => 개장여부(bool)] 맵 반환.
     *
     * opnd_yn(개장일여부) 'Y' 면 거래일.
     * KIS_APP_KEY 가 설정되지 않았거나 API 오류 시 빈 배열 반환.
     */
    private function fetchKisOpenedDays(string $baseDateStr): array
    {
        $apiUrl    = env('KIS_API_URL', 'https://openapi.koreainvestment.com:9443');
        $appKey    = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return [];
        }

        try {
            $client = new Client();

            $doRequest = function (string $token) use ($client, $apiUrl, $appKey, $appSecret, $baseDateStr) {
                return $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/chk-holiday", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$token}",
                        'appkey'        => $appKey,
                        'appsecret'     => $appSecret,
                        'tr_id'         => 'CTCA0903R',
                        'custtype'      => 'P',
                    ],
                    'query' => [
                        'BASS_DT'       => $baseDateStr,
                        'CTX_AREA_NK'   => '',
                        'CTX_AREA_FK'   => '',
                    ],
                    'http_errors' => false,
                ]);
            };

            $accessToken = $this->getKisAccessToken();
            $response    = $doRequest($accessToken);
            $data        = json_decode($response->getBody()->getContents(), true);

            // 토큰 만료(EGW00123) 시 강제 갱신 후 1회 재시도
            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                $accessToken = $this->getKisAccessToken(true);
                $response    = $doRequest($accessToken);
                $data        = json_decode($response->getBody()->getContents(), true);
            }

            $map = [];
            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $row) {
                    if (isset($row['bass_dt'])) {
                        $map[$row['bass_dt']] = (($row['opnd_yn'] ?? 'N') === 'Y');
                    }
                }
            }
            return $map;
        } catch (\Exception $e) {
            Log::error('MarketSessionService: KIS 휴장일 조회 실패: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * KIS OAuth2 액세스 토큰을 캐시에서 가져오거나 신규 발급한다.
     *
     * StockController::getAccessToken() 과 동일한 캐시 키(`kis_access_token`)·락(`kis_token_lock`)·
     * TTL(72000 s)·기본 API URL 을 사용해 두 컴포넌트가 같은 토큰을 공유한다.
     */
    private function getKisAccessToken(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token) {
            return $token;
        }

        // 동시 다중 요청이 KIS 토큰 API 속도 제한(1 req/min)을 초과하지 않도록 락 사용
        $lock = Cache::lock('kis_token_lock', 15);

        try {
            $attempts = 0;
            while (!$lock->get() && $attempts < 10) {
                usleep(500_000); // 0.5초 대기
                $token = Cache::get('kis_access_token');
                if ($token) {
                    return $token;
                }
                $attempts++;
            }

            // 락 획득 후 재확인 (다른 프로세스가 이미 갱신했을 수 있음)
            $token = Cache::get('kis_access_token');
            if ($token) {
                return $token;
            }

            $apiUrl    = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey    = env('KIS_APP_KEY');
            $appSecret = env('KIS_APP_SECRET');

            if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
                throw new \Exception('KIS_APP_KEY 또는 KIS_APP_SECRET 이 설정되지 않았습니다.');
            }

            $client   = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey'     => $appKey,
                    'appsecret'  => $appSecret,
                ],
                'headers' => [
                    'content-type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            throw new \Exception('KIS 액세스 토큰 발급 실패: ' . ($data['msg1'] ?? 'Unknown error'));
        } finally {
            $lock->release();
        }
    }
}
