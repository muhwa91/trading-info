<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 코스피 지수 차트 구조 회귀 테스트 (2026-06-24 Yahoo 전환 후 업데이트)
 *
 * 변경 이력:
 *   2026-06-23: KIS 버그 A(stale 판정)/B(1m 집계 누락) 수정.
 *   2026-06-24: 코스피 지수를 Yahoo 직행으로 전환(KIS 코스피 경로 제거).
 *               getKospiIndexData → getYahooChartData($yahooSymbol, ...) 위임으로 단순화.
 *               KIS 버그 A/B 관련 테스트(4~7, 11~13) → Yahoo 직행 구조 검증으로 대체.
 *
 * 검증 케이스 (소스 텍스트 기반 — 실제 KIS/Yahoo 네트워크 호출 없음):
 *   1. kospiYahooSymbol: '0001' → '^KS11'
 *   2. kospiYahooSymbol: '2001' → '^KS200'
 *   3. kospiYahooSymbol: 미지의 iscd → '^KS11' 폴백
 *   4. getKospiIndexData: getYahooChartData($yahooSymbol, $timeframe) 호출로 위임
 *   5. getKospiIndexData: KIS kisIndexRequest 호출 없음 (KIS 코스피 경로 제거 확인)
 *   6~7. (Yahoo 직행 구조에서 KIS 분봉 집계/폴백 로직 불필요 → 단순화 검증으로 대체)
 *   8. getYahooChartData displayName: '^KS11' → '코스피 지수' 처리 포함
 *   9. getYahooChartData 1d prevClose: '^KS11' 도 mini 요청 지수 목록에 포함
 *  10. getYahooChartData 분봉: '^KS11' 에 대해 getPreviousClose 호출 안 함
 *  11~13. (Yahoo 직행 후 $liveCurrent/applyLiveCurrent 불필요 → 단순 위임 구조 확인)
 */
class KospiIndexBugFixTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 1. kospiYahooSymbol: '0001' → '^KS11'
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiYahooSymbolReturnsKs11ForIscd0001(): void
    {
        $src = $this->getKospiYahooSymbolSource();
        $this->assertNotEmpty($src, 'kospiYahooSymbol 메서드를 찾을 수 없음');

        $this->assertStringContainsString(
            "'^KS11'",
            $src,
            "kospiYahooSymbol 에 '^KS11' 반환 코드가 없음 — '0001'(코스피 종합)은 ^KS11 을 써야 9114 스케일이 맞음"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. kospiYahooSymbol: '2001' → '^KS200'
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiYahooSymbolReturnsKs200ForIscd2001(): void
    {
        $src = $this->getKospiYahooSymbolSource();
        $this->assertNotEmpty($src, 'kospiYahooSymbol 메서드를 찾을 수 없음');

        $this->assertStringContainsString(
            "'2001'",
            $src,
            "kospiYahooSymbol 에 '2001' 분기가 없음 — 코스피200은 ^KS200 을 써야 1477 스케일이 맞음"
        );

        $this->assertStringContainsString(
            "'^KS200'",
            $src,
            "kospiYahooSymbol 에 '^KS200' 반환 코드가 없음"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. kospiYahooSymbol: 미지의 iscd → '^KS11' 폴백
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiYahooSymbolDefaultFallbackIsKs11(): void
    {
        $src = $this->getKospiYahooSymbolSource();
        $this->assertNotEmpty($src, 'kospiYahooSymbol 메서드를 찾을 수 없음');

        // '2001' 분기가 아닌 경우 '^KS11' 을 반환해야 한다.
        // PHP 7.4 환경이므로 match 대신 if/else 로 구현 — return '^KS11' 패턴을 확인.
        $this->assertMatchesRegularExpression(
            "/return\s*'\\^KS11'\s*;/",
            $src,
            "kospiYahooSymbol 에 return '^KS11' 폴백 코드가 없음 — " .
            "'0001' 및 미지의 iscd 는 ^KS11(코스피 종합)을 반환해야 한다"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. getKospiIndexData: Yahoo 직행 위임 확인 (2026-06-24 Yahoo 전환)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataDelegatesToYahooChartData(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // getYahooChartData($yahooSymbol, $timeframe) 위임 호출이 있어야 한다
        $this->assertMatchesRegularExpression(
            '/getYahooChartData\s*\(\s*\$yahooSymbol\s*,\s*\$timeframe\s*\)/',
            $src,
            'getKospiIndexData 가 getYahooChartData($yahooSymbol, $timeframe) 로 위임하지 않음 — ' .
            '2026-06-24 Yahoo 전환: KIS 코스피 경로 제거 후 Yahoo 직행이어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. getKospiIndexData: KIS kisIndexRequest 호출 없음 (코스피 KIS 제거 확인)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataDoesNotCallKisIndexRequest(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // kisIndexRequest 호출이 없어야 한다 (KIS 코스피 경로 제거)
        $this->assertStringNotContainsString(
            'kisIndexRequest',
            $src,
            'getKospiIndexData 에 kisIndexRequest 호출이 남아있음 — ' .
            '2026-06-24 Yahoo 전환: 코스피 지수는 Yahoo ^KS11/^KS200 으로 이전됨'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. getKospiIndexData: iscd 매핑을 통해 올바른 Yahoo 심볼 사용 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataUsesKospiYahooSymbolMapping(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // kospiYahooSymbol($iscd) 매핑 호출로 Yahoo 심볼을 결정해야 한다
        $this->assertStringContainsString(
            '$yahooSymbol',
            $src,
            'getKospiIndexData 에 $yahooSymbol 변수가 없음 — ' .
            'kospiYahooSymbol($iscd) 매핑을 통해 ^KS11(0001) 또는 ^KS200(2001) 을 결정해야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. getKospiIndexData: 고정 KOSPI200 문자열 폴백 없음 (iscd 매핑 사용)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataDoesNotUseHardcodedKospi200Fallback(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // 고정 'KOSPI200' 문자열로 폴백하는 패턴이 없어야 한다
        $this->assertDoesNotMatchRegularExpression(
            "/getYahooChartData\s*\(\s*'KOSPI200'\s*,/",
            $src,
            "getKospiIndexData 에 고정 'KOSPI200' 폴백이 남아있음 — " .
            'iscd 매핑 $yahooSymbol 변수로 ^KS11 또는 ^KS200 을 동적으로 결정해야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. getYahooChartData displayName: '^KS11' → '코스피 지수'
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testYahooChartDataDisplayNameIncludesKs11(): void
    {
        $src = $this->getYahooChartDataSource();
        $this->assertNotEmpty($src, 'getYahooChartData 메서드를 찾을 수 없음');

        // displayName 분기에 '^KS11' 포함
        $this->assertStringContainsString(
            "'^KS11'",
            $src,
            "getYahooChartData displayName 분기에 '^KS11' 가 없음 — " .
            "Yahoo 폴백이 '^KS11' 을 쓸 때 이름이 '코스피 지수' 로 표시되어야 한다"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. getYahooChartData 1d prevClose: '^KS11' 도 mini 요청 지수 목록에 포함
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testYahooChartDataPrevCloseMiniRequestIncludesKs11(): void
    {
        $src = $this->getYahooChartDataSource();
        $this->assertNotEmpty($src, 'getYahooChartData 메서드를 찾을 수 없음');

        // in_array 지수 목록에 '^KS11' 포함
        $this->assertStringContainsString(
            "'^KS11'",
            $src,
            "getYahooChartData 1d prevClose mini 요청 지수 목록에 '^KS11' 이 없음 — " .
            "'^KS11' 일봉 폴백 시 등락률 계산도 mini-prevClose 를 사용해야 함"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. getYahooChartData 분봉: getPreviousClose 호출 자체가 없음 (KIS 제거)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testYahooChartDataIntradaySkipsPreviousCloseForKs11(): void
    {
        $src = $this->getYahooChartDataSource();
        $this->assertNotEmpty($src, 'getYahooChartData 메서드를 찾을 수 없음');

        // KIS getPreviousClose 완전 제거 후: 분봉 경로에서 getPreviousClose 호출이 없어야 한다.
        // (모든 종목이 Yahoo meta prevClose 를 사용하므로 지수별 분기 자체가 불필요)
        $this->assertStringNotContainsString(
            'getPreviousClose',
            $src,
            "getYahooChartData 분봉 경로에 getPreviousClose 호출이 남아있음 — " .
            "KIS getPreviousClose 제거 후 Yahoo meta prevClose 만 사용해야 한다"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. getKospiIndexData: KIS stale 판정 코드 없음 (Yahoo 직행으로 단순화)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataHasNoKisStalenessDetection(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // 2026-06-24 Yahoo 전환 후: KIS stale 판정 코드가 제거됐어야 한다
        $this->assertStringNotContainsString(
            '$deviation',
            $src,
            'getKospiIndexData 에 $deviation(KIS stale 판정) 코드가 남아있음 — ' .
            'Yahoo 직행 전환 후 KIS stale 판정 로직은 불필요하므로 제거되어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 12. getKospiIndexData: $liveCurrent 캡처 없음 (KIS 라이브값 불필요)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataHasNoLiveCurrentCapture(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // 2026-06-24 Yahoo 전환 후: KIS 라이브값 캡처 코드가 제거됐어야 한다
        $this->assertStringNotContainsString(
            '$liveCurrent',
            $src,
            'getKospiIndexData 에 $liveCurrent(KIS 라이브값 캡처) 코드가 남아있음 — ' .
            'Yahoo 직행 전환 후 KIS output1 라이브값 캡처는 불필요하므로 제거되어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 13. getKospiIndexData: 함수 본체가 단순 Yahoo 위임으로 축소됐는지 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataIsSimplifiedToYahooDelegate(): void
    {
        $src = $this->getKospiIndexDataSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 메서드를 찾을 수 없음');

        // kospiYahooSymbol + getYahooChartData 패턴이 모두 있어야 한다 (위임 구조)
        $this->assertStringContainsString(
            'kospiYahooSymbol',
            $src,
            'getKospiIndexData 에 kospiYahooSymbol 호출이 없음 — ' .
            'iscd 를 Yahoo 심볼로 매핑하는 코드가 있어야 한다'
        );
        $this->assertStringContainsString(
            'getYahooChartData',
            $src,
            'getKospiIndexData 에 getYahooChartData 위임이 없음 — ' .
            'Yahoo 직행 전환 후 getYahooChartData 로 위임해야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 14. applyLiveCurrentToIndexResponse 메서드: KIS 제거로 불필요, 삭제 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testApplyLiveCurrentToIndexResponseUpdatesLastCandleClose(): void
    {
        $controllerSrc = $this->getControllerSource();

        // KIS 코스피 라이브값 적용 로직은 KIS 완전 제거 후 불필요하므로 삭제됐어야 한다.
        $this->assertStringNotContainsString(
            'applyLiveCurrentToIndexResponse',
            $controllerSrc,
            "applyLiveCurrentToIndexResponse 메서드가 남아있음 — " .
            "KIS 완전 제거 후 Yahoo 직행 구조에서는 이 메서드가 불필요하므로 삭제되어야 한다"
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

    private function extractMethodSource(string $source, string $methodName): string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($source, '{', $pos);
        if ($openPos === false) {
            return '';
        }

        $depth  = 0;
        $end    = $openPos;
        for ($i = $openPos, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($source, $pos, $end - $pos + 1);
    }

    private function getKospiYahooSymbolSource(): string
    {
        return $this->extractMethodSource($this->getControllerSource(), 'kospiYahooSymbol');
    }

    private function getKospiIndexDataSource(): string
    {
        return $this->extractMethodSource($this->getControllerSource(), 'getKospiIndexData');
    }

    private function getYahooChartDataSource(): string
    {
        return $this->extractMethodSource($this->getControllerSource(), 'getYahooChartData');
    }

    /**
     * getKospiIndexData 내 1d 분기 소스만 추출 (함수 시작부터 분봉 섹션 직전).
     */
    private function getKospiIndexData1dBranchSource(): string
    {
        $src = $this->getKospiIndexDataSource();
        if (empty($src)) {
            return '';
        }

        // "if ($timeframe === '1d')" 블록 시작 위치
        $start = strpos($src, "if (\$timeframe === '1d')");
        if ($start === false) {
            return '';
        }

        $openPos = strpos($src, '{', $start);
        if ($openPos === false) {
            return '';
        }

        $depth = 0;
        $end   = $openPos;
        for ($i = $openPos, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($src, $start, $end - $start + 1);
    }

    /**
     * getKospiIndexData 내 분봉 섹션 소스 추출 (1d 블록 이후).
     */
    private function getKospiIndexDataIntradaySource(): string
    {
        $src = $this->getKospiIndexDataSource();
        if (empty($src)) {
            return '';
        }

        // 분봉 섹션 마커 — "분봉" 주석이 포함된 줄 이후
        $marker = '// 분봉';
        $pos    = strpos($src, $marker);
        if ($pos === false) {
            return '';
        }

        return substr($src, $pos);
    }

    /**
     * getKospiIndexData 분봉 intervalSeconds 블록 소스 추출.
     */
    private function getKospiIndexDataIntradayIntervalSource(): string
    {
        $src = $this->getKospiIndexDataIntradaySource();
        if (empty($src)) {
            return '';
        }

        $marker = '$intervalSeconds = 180';
        $pos    = strpos($src, $marker);
        if ($pos === false) {
            return '';
        }

        // intervalSeconds 초기화부터 aggregateCandles 호출 전까지 (~300자)
        return substr($src, $pos, 300);
    }
}
