<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 지수/환율 캐시 TTL 단축 + meta.regularMarketPrice 보정 — 회귀 테스트 (2026-06-23)
 *
 * 배경:
 *   NQ=F 등 지수 차트가 ~90초마다 한 번만 점프하고 사이에 멈춰 보이는 문제.
 *   원인: Yahoo 캔들 캐시 TTL=90초, KIS 현재가 오버레이 없음.
 *   해결:
 *     1. 지수/환율(NQ=F·^KS200·USDKRW=X·KOSPI200) 캐시 TTL을 15초로 단축.
 *     2. meta.regularMarketPrice 를 분봉 마지막 봉 close·current_price 에 반영
 *        (캔들 캐시 갱신 사이에도 현재가가 최신을 가리키게).
 *     3. WS stale-while-revalidate: freshness TTL 도 15초(지수)/90초(개별) 분기.
 *
 * 검증 케이스:
 *   1. 지수 분기(NQ=F/^KS200/USDKRW=X) — Cache::remember TTL 이 15(초) 이하
 *   2. KOSPI200 분기 — Cache::remember TTL 이 15(초) 이하
 *   3. 개별주식 — Yahoo 캐시 TTL 90초 유지(회귀 방지)
 *   4. 미국주식 — Yahoo 캐시 TTL 90초 유지(회귀 방지)
 *   5. meta.regularMarketPrice 보정 블록 — getStockData 지수 분기에 존재
 *   6. 1d 타임프레임 제외 — 1d 에서는 meta 보정을 하지 않는다
 *   7. change_amount/change_percent 는 보정 블록에서 변경하지 않는다
 *   8. WS refreshYahooCache — 지수용 freshnessTtl=15 분기 존재
 */
class IndexCacheTtlAndMetaPriceTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 1. 지수 분기(NQ=F/^KS200/USDKRW=X) — Cache::remember TTL ≤ 15초
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testIndexYahooCacheTtlIsReducedTo15(): void
    {
        $src = $this->getIndexBranchSource();

        // Cache::remember($cacheKey, 15, ...) 패턴이 있어야 한다
        $this->assertMatchesRegularExpression(
            '/Cache::remember\s*\(\s*\$cacheKey\s*,\s*15\s*,/',
            $src,
            'NQ=F/^KS200/USDKRW=X 지수 분기의 Cache::remember TTL 이 15초가 아님. ' .
            '지수 캔들 캐시 TTL 을 15초로 단축해야 차트가 ~15~30초 간격으로 전진한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. KOSPI200 분기 — Cache::remember TTL ≤ 15초
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testKospi200CacheTtlIsReducedTo15(): void
    {
        $src = $this->getKospi200BranchSource();

        // $candleTtl = 15 로 설정돼야 한다
        $this->assertMatchesRegularExpression(
            '/\$candleTtl\s*=\s*15\s*;/',
            $src,
            'KOSPI200 분기의 $candleTtl 이 15가 아님. ' .
            'KIS 분봉 집계도 TTL 15초로 단축해야 한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. 국내 개별주식 — Yahoo 캐시 TTL 90초 유지 (회귀 방지)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticStockYahooCacheTtlRemainsAt90(): void
    {
        $src = $this->getDomesticStockBranchSource();

        // yahoo_stock_data_{ticker}_{timeframe}_raw 캐시 키 + TTL 90 패턴
        $this->assertMatchesRegularExpression(
            '/Cache::remember\s*\(\s*\$cacheKey\s*,\s*90\s*,/',
            $src,
            '국내 개별주식 분기의 Yahoo 캐시 TTL 이 90초가 아님 — 회귀. ' .
            '개별주식은 KIS 현재가 오버레이가 따로 있으므로 TTL 90초를 유지해야 한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. 미국 개별주식 — Yahoo 캐시 TTL 90초 유지 (회귀 방지)
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsStockYahooCacheTtlRemainsAt90(): void
    {
        $src = $this->getUsStockBranchSource();

        // US Stock flow 주석 직후의 Cache::remember TTL 이 90이어야 한다
        $this->assertMatchesRegularExpression(
            '/Cache::remember\s*\(\s*\$cacheKey\s*,\s*90\s*,/',
            $src,
            '미국 개별주식 분기의 Yahoo 캐시 TTL 이 90초가 아님 — 회귀. ' .
            '미국주식도 KIS 현재가 오버레이가 별도이므로 TTL 90초를 유지해야 한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. meta.regularMarketPrice 보정 블록 — 지수 분기에 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testIndexBranchAppliesMetaRegularMarketPriceCorrection(): void
    {
        $src = $this->getIndexBranchSource();

        // regularMarketPrice 키 참조가 있어야 한다
        $this->assertStringContainsString(
            'regularMarketPrice',
            $src,
            '지수 분기에 meta.regularMarketPrice 보정 코드가 없음. ' .
            '캔들 캐시 갱신 사이에도 현재가가 최신값을 가리키게 보정해야 한다.'
        );

        // $livePrice 로 current_price 를 갱신하는 코드 존재
        $this->assertStringContainsString(
            "current_price",
            $src,
            '지수 분기에 current_price 갱신 코드가 없음. ' .
            'meta.regularMarketPrice 를 current_price 에도 반영해야 한다.'
        );

        // 마지막 봉 close 갱신 패턴
        $this->assertMatchesRegularExpression(
            "/\\\$content\['candles'\]\s*\[\s*\\\$lastIdx\s*\]\s*\['close'\]\s*=/",
            $src,
            '지수 분기에 마지막 봉 close 갱신 코드가 없음. ' .
            'meta.regularMarketPrice 를 마지막 봉 close 에 반영해야 한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. 1d 타임프레임 — meta 보정 블록 제외 조건 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testMetaCorrectionExcludesDailyTimeframe(): void
    {
        $src = $this->getIndexBranchSource();

        // "!== '1d'" 또는 "=== '1d'" 등으로 1d 를 제외하는 조건이 있어야 한다
        $this->assertMatchesRegularExpression(
            "/\\\$timeframe\s*!==\s*'1d'/",
            $src,
            '지수 분기 meta 보정 블록에 1d 제외 조건($timeframe !== \'1d\')이 없음. ' .
            '1d 일봉에서는 meta.regularMarketPrice 보정을 하지 않아야 한다(등락률 기준 혼동 방지).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. change_amount / change_percent — 보정 블록에서 변경 금지
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testMetaCorrectionDoesNotAlterChangeFields(): void
    {
        // 보정 블록 내에서 change_amount 나 change_percent 를 쓰는 코드가 없어야 한다.
        // 등락률은 getYahooChartData() 내부(prevClose 기반)에서 이미 계산 완료.
        $src = $this->getMetaCorrectionBlockSource();

        $this->assertStringNotContainsString(
            'change_amount',
            $src,
            'meta 보정 블록 내에 change_amount 수정 코드가 있음 — ' .
            '등락률은 prevClose 기반으로 getYahooChartData() 내에서 이미 계산 완료이므로 건드리지 않는다.'
        );

        $this->assertStringNotContainsString(
            'change_percent',
            $src,
            'meta 보정 블록 내에 change_percent 수정 코드가 있음 — ' .
            '등락률은 prevClose 기반으로 getYahooChartData() 내에서 이미 계산 완료이므로 건드리지 않는다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. WS refreshYahooCache — 지수용 freshnessTtl = 15 분기 존재
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testRefreshYahooCacheHasIndexFreshnessTtl15(): void
    {
        $src = $this->getRefreshYahooCacheSource();

        // 지수 freshness TTL 15 값 확인
        $this->assertStringContainsString(
            '15',
            $src,
            'refreshYahooCache 에 지수용 freshness TTL 15 값이 없음. ' .
            'NQ=F 등 지수 freshness TTL 도 15초로 단축해야 갱신 루프가 빠르게 돈다.'
        );

        // $freshnessTtl 변수로 TTL 을 결정해야 한다
        $this->assertStringContainsString(
            '$freshnessTtl',
            $src,
            'refreshYahooCache 가 $freshnessTtl 변수 없이 고정 TTL 을 사용 중 — ' .
            '지수 15초·개별주식 90초 분기를 $freshnessTtl 로 구현해야 한다.'
        );

        // in_array 로 지수 목록 분기
        $this->assertStringContainsString(
            'in_array($ticker, $indexTickers',
            $src,
            'refreshYahooCache 에 $indexTickers in_array 분기가 없음. ' .
            '지수와 개별주식 freshness TTL 을 분기하는 코드가 필요하다.'
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

    private function getServerSource(): string
    {
        $path = __DIR__ . '/../../app/Console/Commands/WebSocketAgentServer.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'WebSocketAgentServer.php 읽기 실패');
        return (string)$src;
    }

    /**
     * StockController::getStockData() 내 지수 분기 소스 추출.
     * NQ=F || ^KS200 || USDKRW=X 조건부터 그다음 큰 if 블록 직전까지.
     */
    private function getIndexBranchSource(): string
    {
        $src = $this->getControllerSource();

        // "NQ=F' || $ticker === '^KS200'" 조건이 등장하는 지점을 찾는다
        $start = strpos($src, "ticker === 'NQ=F' || \$ticker === '^KS200'");
        if ($start === false) {
            return '';
        }

        // if 블록 시작 { 찾기
        $openPos = strpos($src, '{', $start);
        if ($openPos === false) {
            return '';
        }

        // 중괄호 매칭으로 블록 끝 탐색
        $depth  = 0;
        $end    = $openPos;
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
     * StockController::getStockData() 내 KOSPI200 분기 소스 추출.
     */
    private function getKospi200BranchSource(): string
    {
        $src   = $this->getControllerSource();
        $start = strpos($src, "ticker === 'KOSPI200'");
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
     * StockController::getStockData() 내 국내 개별주식(KS/KQ) 분기 소스 추출.
     * "yahoo_stock_data_{$ticker}_{$timeframe}_raw" 캐시키 구문을 마커로 사용해
     * preg_match 패턴 내의 중괄호 혼동을 피한다.
     */
    private function getDomesticStockBranchSource(): string
    {
        $src = $this->getControllerSource();
        // yahoo_stock_data_{$ticker}_{$timeframe}_raw 는 국내 개별주식 분기에만 나타남
        $marker = '"yahoo_stock_data_{$ticker}_{$timeframe}_raw"';
        $start  = strpos($src, $marker);
        if ($start === false) {
            // 약 200자 앞으로 당겨서 Cache::remember 포함 영역 반환
            return '';
        }
        // 이 지점 직후에 Cache::remember($cacheKey, 90, ...) 가 있으므로 300자면 충분
        return substr($src, $start, 300);
    }

    /**
     * StockController::getStockData() 내 미국 개별주식(US Stock flow) 분기 소스 추출.
     */
    private function getUsStockBranchSource(): string
    {
        $src    = $this->getControllerSource();
        $marker = '// US Stock flow (non-index, non-domestic)';
        $start  = strpos($src, $marker);
        if ($start === false) {
            return '';
        }

        // 다음 private/public function 직전까지 (약 3000자)
        return substr($src, $start, 3000);
    }

    /**
     * 지수 분기 내 meta 보정 블록만 추출 (regularMarketPrice 언급 전후 500자).
     */
    private function getMetaCorrectionBlockSource(): string
    {
        $src = $this->getIndexBranchSource();
        $pos = strpos($src, 'regularMarketPrice');
        if ($pos === false) {
            return '';
        }
        // 보정 블록 시작은 보통 if ( 직전이므로 앞 200자 + 뒤 800자
        $start = max(0, $pos - 200);
        return substr($src, $start, 1000);
    }

    /**
     * WebSocketAgentServer::refreshYahooCache() 소스 추출.
     */
    private function getRefreshYahooCacheSource(): string
    {
        $src = $this->getServerSource();
        $pos = strpos($src, 'function refreshYahooCache(');
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($src, '{', $pos);
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

        return substr($src, $pos, $end - $pos + 1);
    }
}
