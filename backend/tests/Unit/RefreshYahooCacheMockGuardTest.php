<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 회귀 테스트 — 버그 #1 (NQ=F 차트 고착) + 버그 #2 (isUsMarketOpen 주간거래 경계 불일치)
 *
 * 버그 #1: refreshYahooCache() 에서 Yahoo API 실패 시 getMockStockData() 반환값이
 *   Cache::forever(_last) 에 영구 저장되는 문제.
 *   stale-restore 가 목 데이터를 5초 재주입 → 차트 고착.
 *   수정: source 에 'Mock' 포함 또는 candles 비어있으면 _last 저장 금지.
 *
 * 버그 #2: isUsMarketOpen() 주간거래 경계가 '400(04:00 ET)' 으로 하드코딩되어,
 *   getUsSession()/resolveUsSession() 의 '330(03:30 ET)' 과 불일치.
 *   03:30~04:00 구간에서 봉은 생성되지만 KIS 세션이 '장마감' 으로 전환되어 가격 고착.
 *   수정: isUsMarketOpen() 주간거래 상한을 330 으로 통일.
 */
class RefreshYahooCacheMockGuardTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // 버그 #1 — refreshYahooCache Mock 가드
    // ──────────────────────────────────────────────────────────────

    /**
     * refreshYahooCache 소스에 'Mock' 소스 검사 가드가 존재한다.
     *
     * getMockStockData() 는 source='Mock (...)' 을 반환한다.
     * refreshYahooCache() 는 _last 저장 전 source 에 'Mock' 이 포함되면 저장을 건너뛰어야 한다.
     *
     * @test
     */
    public function testRefreshYahooCacheHasMockGuard(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // 'Mock' 문자열 검사가 메서드 소스에 있어야 한다
        $this->assertStringContainsString(
            'Mock',
            $methodSrc,
            'refreshYahooCache 에 Mock 소스 방어 가드가 없음 — ' .
            'Yahoo 실패 시 getMockStockData 반환값이 _last 에 영구 저장될 수 있음'
        );
    }

    /**
     * refreshYahooCache 소스에 candles 비어있을 경우 저장 금지 가드가 존재한다.
     *
     * @test
     */
    public function testRefreshYahooCacheHasEmptyCandlesGuard(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // 'candles' 존재 여부 검사 코드가 있어야 한다
        $this->assertStringContainsString(
            'candles',
            $methodSrc,
            'refreshYahooCache 에 candles 비어있음 방어 가드가 없음'
        );
    }

    /**
     * refreshYahooCache 에서 _last 저장이 복합 조건(!$isMock && $hasCandles)으로 보호된다.
     *
     * @test
     */
    public function testRefreshYahooCacheLastStorageIsConditional(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // Cache::forever(_last 저장)와 isMock/$hasCandles 조건이 모두 존재해야 한다
        $this->assertStringContainsString(
            'Cache::forever',
            $methodSrc,
            'refreshYahooCache 에 Cache::forever(_last 저장)가 없음'
        );

        $foreverPos = strpos($methodSrc, 'Cache::forever');
        $mockPos    = strpos($methodSrc, 'Mock');

        $this->assertNotFalse($mockPos, 'refreshYahooCache 에 Mock 검사 코드가 없음');

        // Mock 검사 조건이 Cache::forever 보다 앞에 위치해야 한다 (guard-before-action)
        $this->assertLessThan(
            (int)$foreverPos,
            (int)$mockPos,
            'Mock 검사 가드가 Cache::forever(_last 저장) 보다 뒤에 위치함 — 가드가 저장 전에 있어야 함'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 버그 #2 — isUsMarketOpen 주간거래 경계 통일
    // ──────────────────────────────────────────────────────────────

    /**
     * isUsMarketOpen() 주간거래 상한이 330(03:30 ET) 으로 getUsSession 과 일치한다.
     *
     * 이전 버그: isUsMarketOpen 은 400, getUsSession 은 330 → 30분 불일치.
     * 수정 후: 두 메서드 모두 330 을 사용해야 한다.
     *
     * @test
     */
    public function testIsUsMarketOpenOvernightBoundaryMatchesGetUsSession(): void
    {
        $controllerSrc = $this->getControllerSource();

        $methodSrc = $this->extractMethodSource($controllerSrc, 'isUsMarketOpen');

        $this->assertNotEmpty($methodSrc, 'isUsMarketOpen 메서드를 찾을 수 없음');

        // 수정 후 기준: < 330 을 사용해야 한다
        $this->assertStringContainsString(
            '330',
            $methodSrc,
            'isUsMarketOpen 주간거래 종료 경계가 330(03:30 ET) 이 아님 — ' .
            'getUsSession/resolveUsSession 의 330 과 불일치해 03:30~04:00 구간에서 가격이 고착될 수 있음'
        );

        // 버그 값(400)이 주간거래 판정에 사용되지 않아야 한다
        // 주간거래 블록: "if ($timeVal >= 2000 || $timeVal < 330)"
        // 400 은 프리마켓 04:00 시작점으로 다른 로직에서 쓰일 수 있으므로,
        // 주간거래 판정 줄에 330 이 있으면 충분하다(위에서 검증 완료).
        $this->assertMatchesRegularExpression(
            '/\$timeVal\s*<\s*330/',
            $methodSrc,
            'isUsMarketOpen 주간거래 판정에 $timeVal < 330 패턴이 없음'
        );
    }

    /**
     * getUsSession(), isUsMarketOpen() 두 메서드 모두
     * 주간거래 종료 경계로 330 을 사용한다 (일관성 검증).
     *
     * KisParallelPriceFetcher::resolveUsSession 은 KIS 완전 제거로 파일이 삭제됐으므로 검증 대상에서 제외.
     *
     * @test
     */
    public function testAllOvernightBoundariesAreConsistentAt330(): void
    {
        $controllerSrc = $this->getControllerSource();
        $sessionSrc    = $this->getMarketSessionServiceSource();

        // StockController::isUsMarketOpen
        $isOpenSrc = $this->extractMethodSource($controllerSrc, 'isUsMarketOpen');
        $this->assertStringContainsString(
            '330',
            $isOpenSrc,
            'isUsMarketOpen 에 주간거래 경계 330 이 없음'
        );

        // MarketSessionService::getUsSession
        $getUsSessionSrc = $this->extractMethodSource($sessionSrc, 'getUsSession');
        $this->assertStringContainsString(
            '330',
            $getUsSessionSrc,
            'MarketSessionService::getUsSession 에 주간거래 경계 330 이 없음'
        );

        // KisParallelPriceFetcher 는 KIS 완전 제거로 삭제됨 — 파일 없음 검증
        $this->assertFalse(
            file_exists(__DIR__ . '/../../app/Services/KisParallelPriceFetcher.php'),
            'KisParallelPriceFetcher.php 가 존재함 — KIS 완전 제거 후 이 파일은 삭제되어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────

    private function getServerSource(): string
    {
        $path = __DIR__ . '/../../app/Console/Commands/WebSocketAgentServer.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'WebSocketAgentServer.php 읽기 실패');
        return (string)$src;
    }

    private function getControllerSource(): string
    {
        $path = __DIR__ . '/../../app/Http/Controllers/StockController.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'StockController.php 읽기 실패');
        return (string)$src;
    }

    private function getMarketSessionServiceSource(): string
    {
        $path = __DIR__ . '/../../app/Services/MarketSessionService.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'MarketSessionService.php 읽기 실패');
        return (string)$src;
    }

    // getKisParallelPriceFetcherSource() 는 KIS 완전 제거(Phase 5)로 삭제됨 — 파일 없음.

    /**
     * 소스 파일에서 특정 메서드 블록을 중괄호 카운팅으로 추출한다.
     */
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
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
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
}
