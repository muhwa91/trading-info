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
 */
class MarketSessionService
{
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
     * 주어진 timestamp 가 KRX 거래일인지 토스 마켓캘린더 API 로 판정.
     *
     * Cache 키 `kis_trading_day_{Ymd}` 는 하위 호환을 위해 유지한다.
     * API 실패 시 폴백: 주말 + 고정공휴일 판정 (TTL 30분, API 회복 시 갱신).
     */
    public function isKrTradingDay(int $timestamp): bool
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        $dateStr  = $dt->format('Ymd');
        $cacheKey = "kis_trading_day_{$dateStr}"; // 캐시 키 하위 호환 유지

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        // 토스 캘린더로 배치 조회 (앞뒤 7일)
        $opened = $this->fetchTossOpenedDays($dateStr);
        if (!empty($opened)) {
            foreach ($opened as $d => $isOpen) {
                Cache::put("kis_trading_day_{$d}", $isOpen, 60 * 60 * 24 * 7);
            }
            if (array_key_exists($dateStr, $opened)) {
                return $opened[$dateStr];
            }
        }

        // 폴백: 주말 + 고정공휴일
        $isWeekday = ((int)$dt->format('N')) <= 5;
        if (!$isWeekday) {
            Cache::put($cacheKey, false, 60 * 30);
            return false;
        }
        $mmdd           = $dt->format('md');
        $fixedHolidays  = ['0101', '0301', '0505', '0815', '1003', '1009', '1225'];
        $isHoliday      = in_array($mmdd, $fixedHolidays, true);
        $result         = !$isHoliday;
        Cache::put($cacheKey, $result, 60 * 30);
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal — Toss market calendar API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 토스 마켓캘린더 API → [Ymd => 거래일여부(bool)] 맵 반환.
     *
     * baseDateStr 기준 앞뒤 7일치를 한 번에 조회해 여러 날짜를 한 번에 캐싱한다.
     * API 실패 또는 응답 형식 미인식 시 빈 배열 반환.
     */
    private function fetchTossOpenedDays(string $baseDateStr): array
    {
        try {
            // baseDateStr 기준 앞뒤 7일 범위 조회
            $baseTs   = mktime(0, 0, 0, (int)substr($baseDateStr, 4, 2), (int)substr($baseDateStr, 6, 2), (int)substr($baseDateStr, 0, 4));
            $fromDate = date('Ymd', $baseTs - 7 * 86400);
            $toDate   = date('Ymd', $baseTs + 7 * 86400);

            $data = $this->tossApiClient->get('/api/v1/market-calendar/KR', [
                'from' => $fromDate,
                'to'   => $toDate,
            ]);

            if (empty($data)) {
                return [];
            }

            // 응답에서 거래일 배열 추출 (키 이름 유연하게)
            $tradingDays = $data['tradingDays'] ?? $data['openDates'] ?? $data['data']['tradingDays'] ?? null;
            if (!is_array($tradingDays)) {
                Log::warning('MarketSessionService: 토스 캘린더 응답 형식 미인식', ['keys' => array_keys($data)]);
                return [];
            }

            // 범위 내 모든 날짜에 대해 거래일 여부 맵 생성
            $map        = [];
            $tradingSet = array_flip($tradingDays); // YYYYMMDD 키로 빠른 룩업
            $current    = $fromDate;
            while ($current <= $toDate) {
                $map[$current] = isset($tradingSet[$current]);
                $current = date('Ymd', mktime(0, 0, 0, (int)substr($current, 4, 2), (int)substr($current, 6, 2) + 1, (int)substr($current, 0, 4)));
            }
            return $map;
        } catch (\Exception $e) {
            Log::error('MarketSessionService: 토스 캘린더 조회 실패: ' . $e->getMessage());
            return [];
        }
    }
}
