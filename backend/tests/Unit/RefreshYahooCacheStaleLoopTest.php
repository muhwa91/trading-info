<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 회귀 테스트 — 버그 #1 (NQ=F stale 악순환)
 *
 * 근본 원인:
 *   restoreStaleYahooCache() 가 _last 를 5초 TTL 로 원본 캐시 키에 재주입한 직후
 *   refreshYahooCache() 가 Cache::remember($cacheKey, ...) 를 호출할 때,
 *   재주입된 값이 아직 살아있으면 Cache::remember 가 클로저(getYahooChartData) 를 호출하지 않아
 *   stale 데이터가 _last 로 다시 저장되는 악순환이 발생한다.
 *
 * 수정 내용:
 *   refreshYahooCache() 내부에서 _freshness 키가 만료된 경우
 *   Cache::forget($cacheKey) 를 호출해 재주입된 5초 TTL 값을 제거한 뒤
 *   getStockData() (→ Cache::remember) 를 호출하도록 변경.
 *
 * 이 테스트는 소스 코드에 해당 방어 로직이 반드시 존재함을 검증한다.
 */
class RefreshYahooCacheStaleLoopTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 테스트: freshness 만료 시 Cache::forget 이 Cache::remember 보다 앞에 위치
    // ──────────────────────────────────────────────────────────────────────

    /**
     * refreshYahooCache() 소스에 Cache::forget 호출이 존재한다.
     *
     * freshness 만료 후 재주입된 값을 제거해야 fresh fetch 가 보장되므로
     * Cache::forget 이 반드시 있어야 한다.
     *
     * @test
     */
    public function testRefreshYahooCacheHasCacheForget(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        $this->assertStringContainsString(
            'Cache::forget',
            $methodSrc,
            'refreshYahooCache 에 Cache::forget 호출이 없음 — ' .
            '_freshness 만료 후 재주입된 5초 TTL 값을 제거하지 않으면 악순환이 재발한다'
        );
    }

    /**
     * Cache::forget($cacheKey) 가 getStockData() 호출(= Cache::remember) 보다 앞에 위치한다.
     *
     * 순서가 뒤바뀌면 forget 이 무의미해진다.
     *
     * @test
     */
    public function testCacheForgetPrecedesGetStockDataCall(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        $forgetPos      = strpos($methodSrc, 'Cache::forget');
        $getStockPos    = strpos($methodSrc, 'getStockData(');

        $this->assertNotFalse($forgetPos, 'refreshYahooCache 에 Cache::forget 가 없음');
        $this->assertNotFalse($getStockPos, 'refreshYahooCache 에 getStockData() 호출이 없음');

        $this->assertLessThan(
            (int)$getStockPos,
            (int)$forgetPos,
            'Cache::forget 이 getStockData() 호출보다 뒤에 위치함 — ' .
            '재주입된 캐시 제거가 Cache::remember 이후 발생하면 악순환 방지 효과가 없음'
        );
    }

    /**
     * Cache::forget 이 freshnessKey 확인 블록(= _freshness 만료 분기) 안에 위치한다.
     *
     * freshness 가 살아있을 때는 Cache::forget 이 호출되면 안 되므로
     * _freshness 검사(`Cache::has($freshnessKey)`)가 Cache::forget 보다 앞에 있어야 한다.
     *
     * @test
     */
    public function testCacheForgetIsInsideFreshnessExpiredBranch(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // freshnessKey 살아있으면 continue(스킵) 하는 패턴이 존재해야 한다
        $this->assertMatchesRegularExpression(
            '/Cache::has\(\s*\$freshnessKey\s*\)/',
            $methodSrc,
            'refreshYahooCache 에 Cache::has($freshnessKey) 패턴이 없음'
        );

        $hasPos    = (int)strpos($methodSrc, 'Cache::has($freshnessKey)');
        $forgetPos = (int)strpos($methodSrc, 'Cache::forget');

        // freshness 검사 이후에 Cache::forget 이 나와야 한다
        $this->assertGreaterThan(
            $hasPos,
            $forgetPos,
            'Cache::forget 이 Cache::has($freshnessKey) 보다 앞에 있음 — ' .
            'freshness 살아있어도 forget 이 실행될 수 있음'
        );
    }

    /**
     * Cache::forget 이 freshnessTtl 할당(= 메서드 진입 후 초반) 이후,
     * 그리고 getStockData() 직전에 위치하는 전체 순서를 검증한다.
     *
     * @test
     */
    public function testRefreshYahooCacheOrderIsForgetThenFetch(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        $forgetPos   = strpos($methodSrc, 'Cache::forget');
        $foreverPos  = strpos($methodSrc, 'Cache::forever');
        $putFreshPos = strpos($methodSrc, '$freshnessKey');

        $this->assertNotFalse($forgetPos, 'Cache::forget 없음');
        $this->assertNotFalse($foreverPos, 'Cache::forever(_last 저장) 없음');
        $this->assertNotFalse($putFreshPos, '$freshnessKey 없음');

        // forget → (getStockData fetch) → forever(_last) 순서
        $this->assertLessThan(
            (int)$foreverPos,
            (int)$forgetPos,
            'Cache::forget 이 Cache::forever(_last 저장)보다 뒤에 있음 — 순서 이상'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────────

    private function getServerSource(): string
    {
        $path = __DIR__ . '/../../app/Console/Commands/WebSocketAgentServer.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'WebSocketAgentServer.php 읽기 실패');
        return (string)$src;
    }

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
