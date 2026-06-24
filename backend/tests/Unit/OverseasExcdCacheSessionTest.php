<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 미국 종목 EXCD 캐시 세션 분리 회귀 테스트.
 *
 * 이력:
 *   원래 StockController::fetchOverseasPriceFromKis() 의 세션 그룹 분리를 검증했으나,
 *   KIS 완전 제거(Phase 5, 2026-06-24)로 fetchOverseasPriceFromKis 자체가 삭제됐다.
 *   이 테스트는 이제 해당 메서드들이 실제로 없음을 확인하는 구조 검증으로 대체된다.
 *
 * 검증 케이스:
 *   1. fetchOverseasPriceFromKis 가 StockController 에 없음 (KIS 제거 확인)
 *   2. fetchMinuteFromKis 가 StockController 에 없음 (KIS 제거 확인)
 *   3. getAccessToken 이 StockController 에 없음 (KIS 제거 확인)
 */
class OverseasExcdCacheSessionTest extends TestCase
{
    /** StockController 전체 소스 (캐싱) */
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__ . '/../../app/Http/Controllers/StockController.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'StockController.php 읽기 실패');
        $this->src = (string)$src;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. fetchOverseasPriceFromKis 삭제 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testRegularSessionExchangeListExcludesBlueOcean(): void
    {
        // KIS 완전 제거 후: fetchOverseasPriceFromKis 메서드가 없어야 한다.
        $this->assertStringNotContainsString(
            'function fetchOverseasPriceFromKis',
            $this->src,
            "fetchOverseasPriceFromKis 메서드가 StockController 에 남아있음 — " .
            "KIS 완전 제거 후 이 메서드는 삭제되어야 한다."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. fetchMinuteFromKis 삭제 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testExcdCacheKeyIsSeparatedBySession(): void
    {
        // KIS 완전 제거 후: fetchMinuteFromKis 메서드가 없어야 한다.
        $this->assertStringNotContainsString(
            'function fetchMinuteFromKis',
            $this->src,
            "fetchMinuteFromKis 메서드가 StockController 에 남아있음 — " .
            "KIS 완전 제거 후 이 메서드는 삭제되어야 한다."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. getAccessToken 삭제 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testPremarketCacheDoesNotAffectRegularSessionLookup(): void
    {
        // KIS 완전 제거 후: StockController::getAccessToken 메서드가 없어야 한다.
        $this->assertStringNotContainsString(
            'function getAccessToken',
            $this->src,
            "getAccessToken 메서드가 StockController 에 남아있음 — " .
            "KIS 완전 제거 후 이 메서드는 삭제되어야 한다."
        );
    }
}
