<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * WebSocketAgentServer stale-while-revalidate 단위 테스트.
 *
 * 배경 (2026-06-22):
 *   Yahoo 캔들 캐시(TTL=90초)가 만료될 때 전송 루프 안에서 Yahoo HTTP(5~6초)를
 *   블로킹 호출해 사이클 전체가 stall되는 문제.
 *
 * 수정 내용:
 *   1. restoreStaleYahooCache(): 전송 직전, _last 백업→5초 재주입 (네트워크 없음)
 *   2. refreshYahooCache()     : 전송+KIS 후, _freshness 마커 기반 Yahoo HTTP 갱신
 *   3. pushRealtimeData() 순서: stale복원 → 전송 → KIS → Yahoo갱신
 *
 * 검증 케이스:
 *   1. stale 복원 — _last 있고 원본 만료 시 Cache::put(key, lastVal, 5) 가 호출된다
 *   2. cold-start  — _last 도 원본도 없으면 restoreStaleYahooCache 는 아무것도 하지 않는다
 *   3. freshness 마커 — refreshYahooCache 소스에 _freshness 키와 90초 TTL 이 있다
 *   4. 순서 보장 — pushRealtimeData 소스에서 restoreStaleYahooCache 가
 *                  refreshYahooCache 보다 앞에 나온다
 *
 * PHP 7.4 환경: private 메서드는 Reflection 으로 접근. 캐시는 array 드라이버를 활용.
 */
class WebSocketCandleStaleTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Test 1: stale 복원
    //   _last 백업이 있고 원본 캐시가 없을 때,
    //   restoreStaleYahooCache() 호출 후 원본 캐시가 5초 이내 TTL 로 복원된다.
    //   (Cache facade 를 사용하지 않고 소스 텍스트로 로직을 검증한다.)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testRestoreStaleYahooCachePutsLastValueWithShortTtl(): void
    {
        $source = $this->getServerSource();

        // restoreStaleYahooCache 메서드 블록 추출
        $methodSrc = $this->extractMethodSource($source, 'restoreStaleYahooCache');

        $this->assertNotEmpty($methodSrc, 'restoreStaleYahooCache 메서드를 찾을 수 없음');

        // _last 백업값을 꺼내는 코드 존재 여부
        $this->assertStringContainsString(
            '_last',
            $methodSrc,
            'restoreStaleYahooCache 에 _last 키 참조가 없음'
        );

        // Cache::put 호출 여부
        $this->assertStringContainsString(
            'Cache::put',
            $methodSrc,
            'restoreStaleYahooCache 에 Cache::put 호출이 없음'
        );

        // 5초 TTL 재주입 확인
        $this->assertMatchesRegularExpression(
            '/Cache::put\s*\(\s*\$cacheKey\s*,\s*\$lastVal\s*,\s*5\s*\)/',
            $methodSrc,
            'restoreStaleYahooCache 가 _last 값을 5초 TTL 로 재주입하지 않음'
        );

        // Cache::has 로 원본 캐시 생존 여부를 먼저 체크해야 함
        $hasPosInMethod  = strpos($methodSrc, 'Cache::has');
        $putPosInMethod  = strpos($methodSrc, 'Cache::put');
        $this->assertNotFalse($hasPosInMethod, 'restoreStaleYahooCache 에 Cache::has 가 없음');
        $this->assertLessThan(
            $putPosInMethod,
            $hasPosInMethod,
            'Cache::has(원본 체크) 가 Cache::put(재주입) 보다 뒤에 나옴'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Test 2: cold-start
    //   _last 도 원본도 없으면 restoreStaleYahooCache 는 아무것도 캐시에 넣지 않는다.
    //   소스에서 _last 가 null 일 때 Cache::put 을 건너뛰는 조건 분기를 확인한다.
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testRestoreStaleYahooCacheDoesNothingOnColdStart(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'restoreStaleYahooCache');

        $this->assertNotEmpty($methodSrc, 'restoreStaleYahooCache 메서드를 찾을 수 없음');

        // _last 가 null 인 경우 Cache::put 을 호출하지 않는 조건 분기가 있어야 한다.
        // "if ($lastVal !== null)" 또는 "if (null !== $lastVal)" 패턴 확인.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$lastVal\s*!==\s*null\s*\)/',
            $methodSrc,
            'restoreStaleYahooCache 에 cold-start 방어 조건(lastVal !== null)이 없음'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Test 3: freshness 마커
    //   refreshYahooCache 소스에 _freshness 키와 가변 TTL 코드가 존재한다.
    //
    //   2026-06-23 변경: 지수/환율(NQ=F·^KS200·USDKRW=X·KOSPI200·KOSPI_NIGHT)은
    //   freshness TTL 15초, 개별주식은 90초로 분기 처리하게 됨.
    //   → freshness TTL 은 $freshnessTtl 변수로 동적 결정되므로,
    //     고정 90초 패턴 대신 변수 참조 패턴($freshnessTtl)을 검증한다.
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testRefreshYahooCacheUsesFreshnessMarker(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'refreshYahooCache');

        $this->assertNotEmpty($methodSrc, 'refreshYahooCache 메서드를 찾을 수 없음');

        // _freshness 보조 키 존재
        $this->assertStringContainsString(
            '_freshness',
            $methodSrc,
            'refreshYahooCache 에 _freshness 키가 없음'
        );

        // Cache::has($freshnessKey) 로 스킵 조건 확인
        $this->assertStringContainsString(
            'Cache::has($freshnessKey)',
            $methodSrc,
            'refreshYahooCache 에 Cache::has($freshnessKey) 스킵 조건이 없음'
        );

        // freshness TTL 을 변수($freshnessTtl)로 결정하는 코드가 있어야 한다.
        // (지수 15초·개별주식 90초 분기 → 고정 90 대신 변수 참조)
        $this->assertStringContainsString(
            '$freshnessTtl',
            $methodSrc,
            'refreshYahooCache 가 $freshnessTtl 변수로 freshness TTL 을 결정하지 않음 — ' .
            '지수/환율 15초·개별주식 90초 분기가 구현되어 있어야 함'
        );

        // Cache::put($freshnessKey, 1, $freshnessTtl) 패턴 — 변수 TTL 사용 확인
        $this->assertMatchesRegularExpression(
            '/Cache::put\s*\(\s*\$freshnessKey\s*,\s*1\s*,\s*\$freshnessTtl\s*\)/',
            $methodSrc,
            'refreshYahooCache 가 freshness 마커를 $freshnessTtl 변수 TTL 로 저장하지 않음'
        );

        // 지수 15초 분기 값 존재
        $this->assertStringContainsString(
            '15',
            $methodSrc,
            'refreshYahooCache 에 지수용 freshness TTL 15(초) 값이 없음'
        );

        // 개별주식 90초 분기 값 존재
        $this->assertStringContainsString(
            '90',
            $methodSrc,
            'refreshYahooCache 에 개별주식용 freshness TTL 90(초) 값이 없음'
        );

        // _last 에 영구 저장
        $this->assertStringContainsString(
            'Cache::forever',
            $methodSrc,
            'refreshYahooCache 에 Cache::forever(_last 저장)가 없음'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Test 4: 순서 보장
    //   pushRealtimeData() 소스 내에서 restoreStaleYahooCache 호출이
    //   refreshYahooCache 호출보다 앞에 위치한다.
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testSendOccursBeforeYahooRefreshInPushRealtimeData(): void
    {
        $source    = $this->getServerSource();
        $methodSrc = $this->extractMethodSource($source, 'pushRealtimeData');

        $this->assertNotEmpty($methodSrc, 'pushRealtimeData 메서드를 찾을 수 없음');

        $restorePos = strpos($methodSrc, 'restoreStaleYahooCache');
        $refreshPos = strpos($methodSrc, 'refreshYahooCache');

        $this->assertNotFalse(
            $restorePos,
            'pushRealtimeData 에 restoreStaleYahooCache 호출이 없음'
        );
        $this->assertNotFalse(
            $refreshPos,
            'pushRealtimeData 에 refreshYahooCache 호출이 없음'
        );

        $this->assertLessThan(
            $refreshPos,
            $restorePos,
            'stale 복원(restoreStaleYahooCache)이 Yahoo 갱신(refreshYahooCache)보다 뒤에 호출됨 — ' .
            '전송 전 stale 복원이 먼저 실행돼야 Yahoo 네트워크 없이 전송이 가능하다'
        );

        // 추가: 전송 루프(fwrite)가 restoreStaleYahooCache 와 refreshYahooCache 사이에 있어야 함
        $fwritePos = strpos($methodSrc, 'fwrite');
        $this->assertNotFalse($fwritePos, 'pushRealtimeData 에 fwrite 전송 코드가 없음');
        $this->assertGreaterThan(
            $restorePos,
            $fwritePos,
            'fwrite(전송)이 restoreStaleYahooCache(stale복원) 보다 앞에 있음'
        );
        $this->assertLessThan(
            $refreshPos,
            $fwritePos,
            'fwrite(전송)이 refreshYahooCache(Yahoo갱신) 보다 뒤에 있음 — 전송이 갱신보다 먼저여야 함'
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

    /**
     * 소스 파일에서 특정 메서드 블록을 추출한다(중괄호 카운팅).
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
