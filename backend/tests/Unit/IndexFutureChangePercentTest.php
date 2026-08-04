<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 지수·선물 등락률 계산 / 야간선물 base 코드 — 회귀 테스트 (2026-06-23)
 *
 * 버그 A (재수정 2026-06-24):
 *   getYahooChartData() 1d 분기에서 지수·선물 전일종가는 직전 일봉 종가(prev($candles))를 쓴다.
 *   chartPreviousClose / meta previousClose 는 range '시작 직전'(이틀 전) 값이라 한 칸 밀려,
 *   코스피 폭락일에 등락 부호가 뒤집힌다(6/24 코스피 +3.26% 를 -7% 로 오표시) → 사용 금지.
 *
 * 버그 B (수정):
 *   getKOSPINightChartData() 내 getKospiIndexData() 호출 코드 인자가
 *   '0002'(코스피 대형주, ~10,156) 가 아닌 '2001'(KOSPI200, ~1,477) 이어야 한다.
 *
 * 검증 케이스:
 *   1. 1d 분기에서 지수·선물 ticker 에 대해 in_array 체크가 존재한다
 *   2. 1d 분기에서 지수·선물 ticker 에 대해 meta previousClose 를 사용한다
 *   3. 개별 종목 1d 는 meta previousClose 를 직접 사용하지 않는다 (회귀 방지)
 *   4. 야간선물 base 로 '0002' 가 사용되지 않는다
 *   5. 야간선물 base 로 '2001' 이 사용된다
 */
class IndexFutureChangePercentTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 1. 1d 분기 — 지수·선물 in_array 체크 존재
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_one_day_branch_does_not_use_chart_previous_close_mini(): void
    {
        $src = $this->getYahooChartSection();

        // 2026-06-24 재수정: 지수·선물 1d 등락에 chartPreviousClose mini(range=2d) 요청을 쓰면
        // range '시작 직전'(이틀 전) 종가가 되어 한 칸 밀린다(코스피 폭락일 +3.26% 를 -7% 로 오표시).
        // 직전 봉(prev candles)을 써야 하므로 mini 요청은 없어야 한다.
        $usesMiniChartPrev = (bool) preg_match('/range=2d/', $src)
            && (bool) preg_match('/chartPreviousClose/', $src);

        $this->assertFalse(
            $usesMiniChartPrev,
            'getYahooChartData() 1d 분기에 chartPreviousClose mini(range=2d) 요청이 남아 있음. ' .
            'range 시작 직전(이틀 전) 값이라 전일종가가 한 칸 밀린다. 직전 봉(prev candles)을 써야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. 1d 분기 — meta previousClose 참조 존재
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_one_day_branch_uses_meta_previous_close_for_index_future(): void
    {
        $src = $this->getYahooChartSection();

        // meta['previousClose'] 또는 meta['chartPreviousClose'] 를 1d 분기에서 읽어야 한다
        $hasMetaPrevClose = (bool) preg_match(
            "/\\\$meta\['previousClose'\]\s*\?\?/",
            $src
        );

        $this->assertTrue(
            $hasMetaPrevClose,
            "getYahooChartData() 1d 분기에 \$meta['previousClose'] ?? 패턴이 없음. " .
            '지수·선물 1d 등락률은 Yahoo meta previousClose(공식 전일종가)를 기준으로 계산해야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. 1d 분기 — 개별 종목은 여전히 prev($candles) 방식 유지 (회귀 방지)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_one_day_branch_keeps_prev_candle_for_individual_stocks(): void
    {
        $src = $this->getYahooChartSection();

        // else 블록에 $prevCandle = prev($candles) ?: $latestCandle 패턴이 있어야 한다
        $hasPrevCandle = (bool) preg_match(
            '/\$prevCandle\s*=\s*prev\s*\(\s*\$candles\s*\)/',
            $src
        );

        $this->assertTrue(
            $hasPrevCandle,
            'getYahooChartData() 1d 분기 개별 종목 else 블록에 prev($candles) ?: $latestCandle 패턴이 없음. ' .
            '개별 종목 1d 등락률은 기존 직전 일봉 방식을 유지해야 함 (회귀 방지).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3-1. 1d 분기 — 당일 봉 결손 시 meta 현재가로 대체 (2026-08-04)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_one_day_branch_falls_back_to_meta_price_when_today_candle_missing(): void
    {
        $src = $this->getOneDayBranch();

        // (a) meta 현재가 대체가 존재한다
        $this->assertMatchesRegularExpression(
            "/\\\$current\s*=\s*\\\$metaPrice/",
            $src,
            '1d 분기에 meta regularMarketPrice 대체($current = $metaPrice)가 없음. ' .
            '당일 봉이 close=null 로 통째 결손이면 end($candles) 가 지난 거래일이라 낡은 가격·낡은 등락률이 나간다 ' .
            '(^KS11 8/3·8/4 연속 null → 7/31 종가 + 7/30 대비 +17.91% 실측).'
        );

        // (b) '오늘' 판정은 서버 시계가 아니라 응답의 regularMarketTime + gmtoffset(거래소 현지)로 한다
        $this->assertMatchesRegularExpression(
            "/gmdate\(\s*'Y-m-d'\s*,[^;]*regularMarketTime[^;]*gmtoffset/s",
            $src,
            "1d 분기의 '오늘' 판정이 gmdate('Y-m-d', regularMarketTime + gmtoffset) 가 아님. " .
            '서버 시계를 쓰면 거래소 시간대(미국·KR)에 따라 당일 봉을 오판한다.'
        );

        // (c) 대체는 '마지막 봉이 오늘보다 과거일 때'만 — 정상 피드(당일 봉 생존)는 종전 동작 유지
        $this->assertMatchesRegularExpression(
            "/\\\$latestCandle\['time'\]\s*<\s*\\\$today/",
            $src,
            "meta 대체가 \$latestCandle['time'] < \$today 가드 없이 무조건 실행된다. " .
            '정상 피드(마지막 봉 = 오늘)에서는 종전 캔들 기반 동작이 그대로여야 한다.'
        );

        // (d) 대체 시 기준가는 마지막 봉(= 직전 거래일 종가)으로 당긴다
        $this->assertMatchesRegularExpression(
            "/\\\$prevClose\s*=\s*\\\$latestCandle\['close'\]/",
            $src,
            "meta 대체 시 기준가를 \$latestCandle['close'] 로 당기지 않음. " .
            'prev($candles) 를 그대로 두면 전전 거래일 대비가 되어 등락률이 한 칸 밀린다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. 야간선물 base — '0002' 사용 금지
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_kospi_night_does_not_use_large_cap_index_code(): void
    {
        $src = $this->getKospiNightSection();

        // getKospiIndexData 호출 인자로 '0002' 가 없어야 한다
        // ('0002' 는 코스피 대형주 ~10,156, KOSPI200 이 아님)
        $hasOldCode = (bool) preg_match(
            "/getKospiIndexData\s*\([^)]*'0002'/",
            $src
        );

        $this->assertFalse(
            $hasOldCode,
            "getKOSPINightChartData() 에서 getKospiIndexData(..., '0002') 가 발견됨. " .
            "'0002' 는 코스피 대형주(~10,156)이고 KOSPI200 이 아님. '2001' 로 바꿔야 함."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. 야간선물 base — Yahoo ^KS200 직행 확인 (2026-06-24 Yahoo 전환 후)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function test_kospi_night_uses_kospi200_index_code(): void
    {
        $src = $this->getKospiNightSection();

        // 2026-06-24 Yahoo 전환: getKOSPINightChartData 는 getYahooChartData('^KS200', ...) 직행.
        // getKospiIndexData 경유를 제거하고 Yahoo ^KS200 을 직접 호출해야 한다.
        $hasYahooKs200 = (bool) preg_match(
            "/getYahooChartData\s*\(\s*'\\^KS200'\s*,/",
            $src
        );

        $this->assertTrue(
            $hasYahooKs200,
            "getKOSPINightChartData() 에서 getYahooChartData('^KS200', ...) 를 찾을 수 없음. " .
            '2026-06-24 Yahoo 전환: KIS getKospiIndexData 경유 대신 Yahoo ^KS200 직행으로 변경됨. ' .
            '^KS200 = KOSPI200(~1,477)을 base 로 사용해야 야간선물 합성 가격이 정상 범위가 됨.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────────

    private function getControllerSource(): string
    {
        $path = __DIR__ . '/../../app/Http/Controllers/StockController.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'StockController.php 읽기 실패');

        return (string) $src;
    }

    /**
     * getYahooChartData() 전체 함수 구간 추출.
     * getKOSPINightChartData 정의 직전까지 (함수 전체 8000+ 바이트).
     */
    private function getYahooChartSection(): string
    {
        $src = $this->getControllerSource();
        $start = strpos($src, 'public function getYahooChartData(');
        $end = strpos($src, 'public function getKOSPINightChartData(');
        if ($start === false) {
            return $src;
        }
        $length = ($end !== false) ? ($end - $start) : 10000;

        return substr($src, $start, $length);
    }

    /**
     * getYahooChartData() 안의 1d 등락률 분기만 추출.
     * ($latestCandle 확정 지점 ~ 분봉 else 블록 직전. 캔들 시간 포맷용 1d 분기와 구분하기 위한 앵커)
     */
    private function getOneDayBranch(): string
    {
        $src = $this->getYahooChartSection();
        $start = strpos($src, '$latestCandle = end($candles);');
        $this->assertNotFalse($start, 'getYahooChartData() 에서 $latestCandle = end($candles) 앵커를 찾을 수 없음');

        $end = strpos($src, '} else {', $start);

        return substr($src, $start, ($end !== false) ? ($end - $start) : 3000);
    }

    /**
     * getKOSPINightChartData() 전체 구간 추출.
     */
    private function getKospiNightSection(): string
    {
        $src = $this->getControllerSource();
        $marker = 'public function getKOSPINightChartData(';
        $pos = strpos($src, $marker);
        if ($pos === false) {
            return $src;
        }

        return substr($src, $pos, 3000);
    }
}
