<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 코스피 지수 차트 버그 수정 회귀 테스트 (2026-06-23)
 *
 * 버그 A — 1d stale: KIS FHPUP02120000 이 rt_cd=0 성공이지만 ~4개월 이전 데이터를
 *           반환할 때 값-기반(10% 괴리)으로 stale 판정 후 Yahoo ^KS11/^KS200 으로 폴백.
 *
 * 버그 B — intraday 1m: 분봉 intervalSeconds 블록에 '1m' 케이스가 없어
 *           1m 이 기본 180초(3m)로 집계되던 문제 수정.
 *
 * 검증 케이스 (소스 텍스트 기반 — 실제 KIS/Yahoo 네트워크 호출 없음):
 *   1. kospiYahooSymbol: '0001' → '^KS11'
 *   2. kospiYahooSymbol: '2001' → '^KS200'
 *   3. kospiYahooSymbol: 미지의 iscd → '^KS11' 폴백
 *   4. getKospiIndexData 1d 분기: stale 감지 로직 존재 (deviation 10% 초과 판정)
 *   5. getKospiIndexData 1d 분기: stale 시 Yahoo yahooSymbol 폴백 코드 존재
 *   6. getKospiIndexData 분봉 intervalSeconds 블록: '1m' → 60 케이스 존재
 *   7. getKospiIndexData 분봉 폴백: getYahooChartData($yahooSymbol) 패턴 (iscd 매핑 사용)
 *   8. getYahooChartData displayName: '^KS11' → '코스피 지수' 처리 포함
 *   9. getYahooChartData 1d prevClose: '^KS11' 도 mini 요청 지수 목록에 포함
 *  10. getYahooChartData 분봉: '^KS11' 에 대해 getPreviousClose 호출 안 함
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
    // 4. getKospiIndexData 1d 분기: stale 감지 로직 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexData1dHasStalenessDetection(): void
    {
        $src = $this->getKospiIndexData1dBranchSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 1d 분기를 찾을 수 없음');

        // deviation 계산 코드 존재
        $this->assertStringContainsString(
            '$deviation',
            $src,
            'getKospiIndexData 1d 분기에 $deviation 계산이 없음 — stale 판정 로직이 구현되어야 함'
        );

        // 10% 초과 조건
        $this->assertMatchesRegularExpression(
            '/\$deviation\s*>\s*0\.10/',
            $src,
            'getKospiIndexData 1d 분기에 10% 초과 stale 판정 조건($deviation > 0.10)이 없음'
        );

        // current 와 latestKisClose 비교 코드
        $this->assertStringContainsString(
            '$latestKisClose',
            $src,
            'getKospiIndexData 1d 분기에 $latestKisClose 변수가 없음 — KIS 최신 캔들 close 와 현재가를 비교해야 함'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. getKospiIndexData 1d 분기: stale 시 Yahoo yahooSymbol 폴백
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexData1dFallsBackToYahooOnStale(): void
    {
        $src = $this->getKospiIndexData1dBranchSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 1d 분기를 찾을 수 없음');

        // stale 판정 후 getYahooChartData($yahooSymbol) 호출 패턴
        $this->assertMatchesRegularExpression(
            '/getYahooChartData\s*\(\s*\$yahooSymbol\s*,\s*\$timeframe\s*\)/',
            $src,
            'getKospiIndexData 1d stale 시 getYahooChartData($yahooSymbol, $timeframe) 폴백 코드가 없음 — ' .
            '고정 KOSPI200/^KS200 대신 iscd 매핑 심볼을 써야 종합(^KS11)과 200(^KS200)이 각각 올바른 심볼로 폴백'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. getKospiIndexData 분봉 intervalSeconds: '1m' → 60 케이스 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataIntradayHas1mIntervalSeconds60(): void
    {
        $src = $this->getKospiIndexDataIntradayIntervalSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 분봉 intervalSeconds 블록을 찾을 수 없음');

        // "if ($timeframe === '1m') $intervalSeconds = 60;" 패턴
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$timeframe\s*===\s*[\'"]1m[\'"]\s*\)\s*\$intervalSeconds\s*=\s*60\s*;/',
            $src,
            "getKospiIndexData 분봉 intervalSeconds 블록에 '1m' → 60 케이스가 없음 — " .
            "이 케이스 누락으로 1m 차트가 실제로 3m(기본 180초)으로 집계되었음"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. getKospiIndexData 분봉 폴백: $yahooSymbol 사용 (iscd 매핑)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexDataIntradayFallbackUsesYahooSymbolVariable(): void
    {
        $src = $this->getKospiIndexDataIntradaySource();
        $this->assertNotEmpty($src, 'getKospiIndexData 분봉 섹션을 찾을 수 없음');

        // 분봉 섹션의 폴백이 고정 'KOSPI200' 이 아닌 $yahooSymbol 변수를 사용해야 한다
        $this->assertStringContainsString(
            '$yahooSymbol',
            $src,
            'getKospiIndexData 분봉 섹션에 $yahooSymbol 변수가 없음 — ' .
            "폴백이 고정 'KOSPI200'/'\\^KS200' 이 아닌 iscd 매핑 심볼을 써야 한다"
        );

        // 고정 'KOSPI200' 문자열로 폴백하는 패턴이 없어야 한다 (getYahooChartData('KOSPI200', ...) 형태)
        $this->assertDoesNotMatchRegularExpression(
            "/getYahooChartData\s*\(\s*'KOSPI200'\s*,/",
            $src,
            "getKospiIndexData 분봉 섹션에 고정 'KOSPI200' 폴백이 남아있음 — " .
            'iscd 에 따라 ^KS11 또는 ^KS200 을 쓰는 $yahooSymbol 변수로 교체해야 한다'
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
    // 10. getYahooChartData 분봉: '^KS11' 에 대해 getPreviousClose 스킵
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testYahooChartDataIntradaySkipsPreviousCloseForKs11(): void
    {
        $src = $this->getYahooChartDataSource();
        $this->assertNotEmpty($src, 'getYahooChartData 메서드를 찾을 수 없음');

        // getPreviousClose 스킵 조건에 '^KS11' 포함 여부 확인
        // "ticker !== 'NQ=F' && ... && ticker !== '^KS11'" 패턴
        $this->assertMatchesRegularExpression(
            "/\\\$ticker\s*!==\s*'\\^KS11'/",
            $src,
            "getYahooChartData 분봉 getPreviousClose 스킵 조건에 '\\^KS11' 이 없음 — " .
            "지수 심볼(^KS11)은 KIS getPreviousClose 대신 Yahoo meta prevClose 를 써야 한다"
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. 1d 분기: $liveCurrent 캡처 코드 존재 (폴백 전 라이브값 보존)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexData1dCapturesLiveCurrentBeforeFallback(): void
    {
        $src = $this->getKospiIndexData1dBranchSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 1d 분기를 찾을 수 없음');

        // $liveCurrent 변수 존재 — stale 판정 전 output1 라이브값 캡처
        $this->assertStringContainsString(
            '$liveCurrent',
            $src,
            'getKospiIndexData 1d 분기에 $liveCurrent 변수가 없음 — ' .
            'Yahoo 폴백 전에 output1 라이브 현재지수를 캡처해 두어야 한다'
        );

        // $liveChg, $livePct 도 캡처되어야 한다
        $this->assertStringContainsString(
            '$liveChg',
            $src,
            'getKospiIndexData 1d 분기에 $liveChg 변수가 없음 — 등락 역시 라이브값을 캡처해야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 12. 1d stale Yahoo 폴백 경로: applyLiveCurrentToIndexResponse 호출 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexData1dStalePathCallsApplyLiveCurrent(): void
    {
        $src = $this->getKospiIndexData1dBranchSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 1d 분기를 찾을 수 없음');

        // stale 폴백 경로에서 applyLiveCurrentToIndexResponse 호출
        $this->assertStringContainsString(
            'applyLiveCurrentToIndexResponse',
            $src,
            'getKospiIndexData 1d stale 폴백 경로에 applyLiveCurrentToIndexResponse 호출이 없음 — ' .
            'Yahoo 캔들 히스토리를 받은 뒤 KIS 라이브값으로 current_price/등락/마지막봉을 덮어써야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 13. 1d KIS 정상 경로도 applyLiveCurrentToIndexResponse 호출 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospiIndexData1dNormalPathCallsApplyLiveCurrent(): void
    {
        $src = $this->getKospiIndexData1dBranchSource();
        $this->assertNotEmpty($src, 'getKospiIndexData 1d 분기를 찾을 수 없음');

        // kisIndexResponse 를 감싼 뒤 applyLiveCurrentToIndexResponse 를 반환
        $this->assertMatchesRegularExpression(
            '/applyLiveCurrentToIndexResponse\s*\(\s*\$response/',
            $src,
            'getKospiIndexData 1d KIS 정상 경로에서 $response 를 applyLiveCurrentToIndexResponse 로 감싸지 않음 — ' .
            'KIS 정상 경로에서도 마지막 봉 close 를 라이브 현재지수로 갱신해야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 14. applyLiveCurrentToIndexResponse 메서드: 마지막 봉 close 덮어쓰기 구현
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testApplyLiveCurrentToIndexResponseUpdatesLastCandleClose(): void
    {
        $src = $this->extractMethodSource(
            $this->getControllerSource(),
            'applyLiveCurrentToIndexResponse'
        );
        $this->assertNotEmpty($src, 'applyLiveCurrentToIndexResponse 메서드를 찾을 수 없음');

        // 마지막 인덱스 추출
        $this->assertMatchesRegularExpression(
            '/count\s*\(\s*\$content\s*\[.candles.\]\s*\)\s*-\s*1/',
            $src,
            "applyLiveCurrentToIndexResponse 에 마지막 봉 인덱스(count-1) 계산이 없음"
        );

        // 마지막 봉 close 에 liveCurrent 할당
        $this->assertMatchesRegularExpression(
            '/\$content\s*\[.candles.\]\s*\[\s*\$lastIdx\s*\]\s*\[.close.\]\s*=/',
            $src,
            "applyLiveCurrentToIndexResponse 에 마지막 봉 close 덮어쓰기 코드가 없음 — " .
            "current_price == 마지막봉 close 가 일치해야 헤더와 차트가 동기화된다"
        );

        // high/low 범위 확장 코드
        $this->assertStringContainsString(
            "'high'",
            $src,
            "applyLiveCurrentToIndexResponse 에 high 범위 확장 코드가 없음"
        );
        $this->assertStringContainsString(
            "'low'",
            $src,
            "applyLiveCurrentToIndexResponse 에 low 범위 확장 코드가 없음"
        );

        // current_price 덮어쓰기
        $this->assertStringContainsString(
            "current_price",
            $src,
            "applyLiveCurrentToIndexResponse 에 current_price 덮어쓰기 코드가 없음"
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
