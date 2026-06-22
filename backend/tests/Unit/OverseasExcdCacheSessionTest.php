<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 미국 종목 EXCD 캐시 세션 분리 회귀 테스트.
 *
 * 배경:
 *   StockController::fetchOverseasPriceFromKis() 에서
 *   세션 그룹이 이진(regular/overnight)이라 프리마켓·정규장이 같은 "regular" 그룹으로 묶혔다.
 *   프리마켓 때 BAQ(Blue Ocean)가 "regular" 그룹에 캐싱되고,
 *   이후 정규장(같은 "regular" 그룹)에서 캐시 히트 → BAQ 장외 stale 값 반환.
 *
 * 수정 후:
 *   세션 그룹을 4분류(정규장/프리마켓/애프터마켓/주간거래/unknown)로 세분화.
 *   → 프리마켓 BAQ 캐시가 정규장 조회에 절대 히트하지 않음.
 *   → 정규장은 BAQ/BAY/BAA 목록에 아예 포함하지 않음.
 *
 * 검증 케이스:
 *   1. 정규장 거래소 목록에 BAQ/BAY/BAA 없음
 *   2. EXCD 캐시 키가 세션별로 분리됨 (정규장 vs 프리마켓 키가 다름)
 *   3. 프리/애프터 EXCD 캐시가 정규장 조회에 영향 없음 (소스 구조 검증)
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
    // 1. 정규장 거래소 목록에 BAQ/BAY/BAA 없음
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     *
     * 수정 전: $session === '주간거래' 인 경우만 BAQ 우선, else 는 $allExchanges 에 BAQ/BAY/BAA 포함.
     * 수정 후: '정규장' 분기는 ['NAS', 'NYS', 'AMS'] 만 포함하고 BAQ/BAY/BAA 를 제외한다.
     *
     * 검증: 소스에 정규장 분기 내 Blue Ocean 코드(BAQ/BAY/BAA)가 포함되지 않는 구조인지 확인.
     * 구체적으로: '$sessionGroup = '정규장'' 할당과 함께 ['NAS', 'NYS', 'AMS'] 만 쓰는 allExchanges 가 있어야 한다.
     */
    public function testRegularSessionExchangeListExcludesBlueOcean(): void
    {
        // 정규장 분기: sessionGroup = '정규장' 설정 확인
        $hasRegularGroup = (bool)preg_match(
            "/sessionGroup\s*=\s*'정규장'/u",
            $this->src
        );
        $this->assertTrue(
            $hasRegularGroup,
            "StockController 에 \$sessionGroup = '정규장' 할당이 없음. " .
            "정규장 세션 그룹이 '정규장'으로 세분화되지 않은 상태."
        );

        // 정규장 분기에서 $allExchanges 를 ['NAS', 'NYS', 'AMS'] 만으로 설정하는지 확인
        // BAQ/BAY/BAA 없이 NAS/NYS/AMS 만 포함한 배열 리터럴이 존재해야 함
        $hasRegularOnlyExchanges = (bool)preg_match(
            "/\\\$allExchanges\s*=\s*\['NAS',\s*'NYS',\s*'AMS'\]\s*;/u",
            $this->src
        );
        $this->assertTrue(
            $hasRegularOnlyExchanges,
            "\$allExchanges = ['NAS', 'NYS', 'AMS'] 패턴을 찾을 수 없음. " .
            "정규장은 NAS/NYS/AMS 만 사용해야 장외 stale 값 오염이 차단된다."
        );

        // 정규장 분기 if 블록만 추출: "session === '정규장'" 조건부터 첫 번째 "} elseif" 전까지
        // 주석 제거 후 정규장 블록 내에 BAQ 가 없어야 함
        $pos = mb_strpos($this->src, "\$session === '정규장'");
        $this->assertNotFalse((int)$pos > 0 ? true : false, "정규장 분기 조건을 찾을 수 없음");

        // 정규장 조건 이후 코드에서 최초 "} elseif" 전까지 잘라냄 (약 250자면 충분)
        $fromPos = mb_substr($this->src, (int)$pos, 300);
        // elseif 또는 } else 전까지만 자름
        $elseifPos = mb_strpos($fromPos, '} elseif');
        if ($elseifPos !== false) {
            $fromPos = mb_substr($fromPos, 0, (int)$elseifPos);
        }
        // 주석 줄(//) 제거 후 검사
        $codeOnly = (string)preg_replace('/\/\/[^\n]*/u', '', $fromPos);
        $this->assertStringNotContainsString(
            'BAQ',
            $codeOnly,
            "정규장 if 블록(elseif 이전) 코드에 'BAQ'(Blue Ocean 나스닥)가 포함됨. " .
            "정규장은 NAS/NYS/AMS 만 사용해야 장외 stale 값 오염이 차단된다."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. EXCD 캐시 키가 세션별로 분리됨
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     *
     * 수정 전: $sessionGroup = ($session === '주간거래') ? 'overnight' : 'regular'
     *   → 정규장/프리마켓/애프터마켓 모두 'regular' 그룹 → 캐시 키 동일
     * 수정 후: 4개 그룹(정규장/프리마켓/애프터마켓/주간거래/unknown)
     *   → "kis_excd_{ticker}_정규장" vs "kis_excd_{ticker}_프리마켓" 는 서로 다른 키
     *
     * 검증: 소스에 '정규장', '프리마켓', '애프터마켓' 세션 그룹 할당이 각각 존재하는지 확인.
     */
    public function testExcdCacheKeyIsSeparatedBySession(): void
    {
        // 정규장 그룹 할당 존재
        $hasRegular = (bool)preg_match(
            "/sessionGroup\s*=\s*'정규장'/u",
            $this->src
        );
        // 프리마켓 그룹 할당 존재
        $hasPre = (bool)preg_match(
            "/sessionGroup\s*=\s*'프리마켓'/u",
            $this->src
        );
        // 애프터마켓 그룹 할당 존재
        $hasAfter = (bool)preg_match(
            "/sessionGroup\s*=\s*'애프터마켓'/u",
            $this->src
        );

        $this->assertTrue(
            $hasRegular,
            "소스에 \$sessionGroup = '정규장' 이 없음. " .
            "세션 그룹이 세분화되지 않으면 '정규장' 키로 분리가 불가능."
        );
        $this->assertTrue(
            $hasPre,
            "소스에 \$sessionGroup = '프리마켓' 이 없음. " .
            "세션 그룹이 세분화되지 않으면 프리마켓 BAQ 캐시가 '정규장' 그룹을 오염."
        );
        $this->assertTrue(
            $hasAfter,
            "소스에 \$sessionGroup = '애프터마켓' 이 없음. " .
            "세션 그룹이 세분화되지 않으면 애프터마켓 BAQ 캐시가 '정규장' 그룹을 오염."
        );

        // 기존 이진 그룹 코드가 실행 코드(주석 아님)로 남아 있으면 수정 미완성
        // 주석 제거 후 검사
        $srcNoComments = (string)preg_replace('/\/\/[^\n]*/u', '', $this->src);
        $hasOldBinaryGroup = (bool)preg_match(
            "/sessionGroup\s*=\s*\(\s*\\\$session\s*===\s*'주간거래'\s*\)\s*\?\s*'overnight'\s*:\s*'regular'/u",
            $srcNoComments
        );
        $this->assertFalse(
            $hasOldBinaryGroup,
            "이전 이진 세션 그룹 코드(\$sessionGroup = (\$session === '주간거래') ? 'overnight' : 'regular')가 " .
            "실행 코드로 아직 남아 있음. 이 코드가 있으면 정규장/프리마켓이 같은 'regular' 캐시 키를 공유해 오염 발생."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. 프리/애프터 EXCD 캐시가 정규장 조회에 영향 없음 (소스 구조 검증)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     *
     * 캐시 키 패턴: "kis_excd_{ticker}_{sessionGroup}"
     * 정규장 캐시 키: "kis_excd_{ticker}_정규장"
     * 프리마켓 캐시 키: "kis_excd_{ticker}_프리마켓"
     *
     * → 두 키는 별도이므로 Cache::get("kis_excd_{ticker}_정규장") 은
     *   프리마켓 때 Cache::put("kis_excd_{ticker}_프리마켓", 'BAQ', ...) 로 저장된 값을 반환하지 않는다.
     *
     * 검증:
     *   (a) 캐시 키 형식이 $sessionGroup 을 포함함을 소스에서 확인
     *   (b) 정규장 $allExchanges 에 BAQ 가 없으므로, 캐시 히트 시에도 BAQ 가 합류할 수 없음을 확인
     *       (캐시 히트 값이 $allExchanges 필터를 통해 폴백 순서에만 추가되는 구조)
     */
    public function testPremarketCacheDoesNotAffectRegularSessionLookup(): void
    {
        // (a) 캐시 키가 $sessionGroup 포함하는지
        $hasSessionGroupInKey = (bool)preg_match(
            '/kis_excd_.*?\{.*?sessionGroup.*?\}/',
            $this->src
        );
        $this->assertTrue(
            $hasSessionGroupInKey,
            '"kis_excd_{ticker}_{sessionGroup}" 형태의 캐시 키를 소스에서 찾을 수 없음. ' .
            '세션별 분리 캐시 키가 없으면 프리마켓 BAQ 캐시가 정규장 조회에 히트한다.'
        );

        // (b) 캐시 히트 시 array_filter($allExchanges, ...) 로 필터링하는 구조가 있어야 함
        //     → $allExchanges 에 BAQ 없는 정규장에서는 캐시 값 BAQ 가 필터에서 탈락
        $hasCacheHitFilter = (bool)preg_match(
            '/array_filter\s*\(\s*\$allExchanges/',
            $this->src
        );
        $this->assertTrue(
            $hasCacheHitFilter,
            'array_filter($allExchanges, ...) 패턴을 찾을 수 없음. ' .
            '캐시 히트 시 $allExchanges 필터를 통과시켜야 정규장에서 BAQ 캐시가 탈락한다.'
        );

        // (c) 주간거래 그룹 할당도 존재 (완전한 4분류 보장)
        $hasOvernight = (bool)preg_match(
            "/sessionGroup\s*=\s*'주간거래'/u",
            $this->src
        );
        $this->assertTrue(
            $hasOvernight,
            "소스에 \$sessionGroup = '주간거래' 가 없음. " .
            "4분류 세션 그룹이 완전하지 않은 상태."
        );
    }
}
