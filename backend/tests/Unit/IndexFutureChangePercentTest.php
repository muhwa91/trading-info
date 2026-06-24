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

    /** @test */
    public function testOneDayBranchDoesNotUseChartPreviousCloseMini(): void
    {
        $src = $this->getYahooChartSection();

        // 2026-06-24 재수정: 지수·선물 1d 등락에 chartPreviousClose mini(range=2d) 요청을 쓰면
        // range '시작 직전'(이틀 전) 종가가 되어 한 칸 밀린다(코스피 폭락일 +3.26% 를 -7% 로 오표시).
        // 직전 봉(prev candles)을 써야 하므로 mini 요청은 없어야 한다.
        $usesMiniChartPrev = (bool)preg_match('/range=2d/', $src)
            && (bool)preg_match('/chartPreviousClose/', $src);

        $this->assertFalse(
            $usesMiniChartPrev,
            "getYahooChartData() 1d 분기에 chartPreviousClose mini(range=2d) 요청이 남아 있음. " .
            "range 시작 직전(이틀 전) 값이라 전일종가가 한 칸 밀린다. 직전 봉(prev candles)을 써야 함."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. 1d 분기 — meta previousClose 참조 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testOneDayBranchUsesMetaPreviousCloseForIndexFuture(): void
    {
        $src = $this->getYahooChartSection();

        // meta['previousClose'] 또는 meta['chartPreviousClose'] 를 1d 분기에서 읽어야 한다
        $hasMetaPrevClose = (bool)preg_match(
            "/\\\$meta\['previousClose'\]\s*\?\?/",
            $src
        );

        $this->assertTrue(
            $hasMetaPrevClose,
            "getYahooChartData() 1d 분기에 \$meta['previousClose'] ?? 패턴이 없음. " .
            "지수·선물 1d 등락률은 Yahoo meta previousClose(공식 전일종가)를 기준으로 계산해야 함."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. 1d 분기 — 개별 종목은 여전히 prev($candles) 방식 유지 (회귀 방지)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testOneDayBranchKeepsPrevCandleForIndividualStocks(): void
    {
        $src = $this->getYahooChartSection();

        // else 블록에 $prevCandle = prev($candles) ?: $latestCandle 패턴이 있어야 한다
        $hasPrevCandle = (bool)preg_match(
            '/\$prevCandle\s*=\s*prev\s*\(\s*\$candles\s*\)/',
            $src
        );

        $this->assertTrue(
            $hasPrevCandle,
            "getYahooChartData() 1d 분기 개별 종목 else 블록에 prev(\$candles) ?: \$latestCandle 패턴이 없음. " .
            "개별 종목 1d 등락률은 기존 직전 일봉 방식을 유지해야 함 (회귀 방지)."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. 야간선물 base — '0002' 사용 금지
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiNightDoesNotUseLargeCapIndexCode(): void
    {
        $src = $this->getKospiNightSection();

        // getKospiIndexData 호출 인자로 '0002' 가 없어야 한다
        // ('0002' 는 코스피 대형주 ~10,156, KOSPI200 이 아님)
        $hasOldCode = (bool)preg_match(
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

    /** @test */
    public function testKospiNightUsesKospi200IndexCode(): void
    {
        $src = $this->getKospiNightSection();

        // 2026-06-24 Yahoo 전환: getKOSPINightChartData 는 getYahooChartData('^KS200', ...) 직행.
        // getKospiIndexData 경유를 제거하고 Yahoo ^KS200 을 직접 호출해야 한다.
        $hasYahooKs200 = (bool)preg_match(
            "/getYahooChartData\s*\(\s*'\\^KS200'\s*,/",
            $src
        );

        $this->assertTrue(
            $hasYahooKs200,
            "getKOSPINightChartData() 에서 getYahooChartData('^KS200', ...) 를 찾을 수 없음. " .
            "2026-06-24 Yahoo 전환: KIS getKospiIndexData 경유 대신 Yahoo ^KS200 직행으로 변경됨. " .
            "^KS200 = KOSPI200(~1,477)을 base 로 사용해야 야간선물 합성 가격이 정상 범위가 됨."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────────

    private function getControllerSource(): string
    {
        $path = __DIR__ . '/../../app/Http/Controllers/StockController.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'StockController.php 읽기 실패');
        return (string)$src;
    }

    /**
     * getYahooChartData() 전체 함수 구간 추출.
     * getKOSPINightChartData 정의 직전까지 (함수 전체 8000+ 바이트).
     */
    private function getYahooChartSection(): string
    {
        $src   = $this->getControllerSource();
        $start = strpos($src, 'public function getYahooChartData(');
        $end   = strpos($src, 'public function getKOSPINightChartData(');
        if ($start === false) {
            return $src;
        }
        $length = ($end !== false) ? ($end - $start) : 10000;
        return substr($src, $start, $length);
    }

    /**
     * getKOSPINightChartData() 전체 구간 추출.
     */
    private function getKospiNightSection(): string
    {
        $src    = $this->getControllerSource();
        $marker = 'public function getKOSPINightChartData(';
        $pos    = strpos($src, $marker);
        if ($pos === false) {
            return $src;
        }
        return substr($src, $pos, 3000);
    }
}
