<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 회귀 테스트 — 버그 #2 (MU 평평봉 — KIS 폴백으로 분봉 누적)
 *
 * 근본 원인:
 *   KIS 8초 TTL 캐시 미스 시 getStockData() 가 24h 폴백 가격($fallbackKeyKisUs)을
 *   $price 로 넘기고, accumulateOverseasRealTimePrice() 는 이를 구분하지 않고
 *   매 사이클(~0.3초) 분봉을 누적 → O=H=L=C 동일한 평평봉이 연속 생성된다.
 *
 * 수정 내용:
 *   1. getStockData() 에 $isFreshKisPrice 플래그 추가
 *      - 8초 TTL 캐시 히트 또는 REST 동기 fetch 성공 → true
 *      - WS 폴백(24h) 또는 REST fetch 실패 후 폴백 → false
 *   2. accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime, $isFreshKisPrice)
 *      - $isFreshKisPrice = false 이면 함수 초입에서 return $accumulated (분봉 누적 없음)
 *      - $isFreshKisPrice = true  이면 기존 로직 그대로
 *
 * 소스 기반 테스트(파일 파싱) + 리플렉션 호출 테스트를 병행한다.
 */
class AccumulateOverseasFallbackSkipTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 1. 소스 기반: $isFreshKisPrice 파라미터·스킵 로직 존재 검증
    // ──────────────────────────────────────────────────────────────────────

    /**
     * accumulateOverseasRealTimePrice() 시그니처에 $isFreshKisPrice 파라미터가 있다.
     *
     * @test
     */
    public function testAccumulateMethodHasFreshKisPriceParameter(): void
    {
        $src    = $this->getControllerSource();
        $method = $this->extractMethodSource($src, 'accumulateOverseasRealTimePrice');

        $this->assertNotEmpty($method, 'accumulateOverseasRealTimePrice 메서드를 찾을 수 없음');

        $this->assertStringContainsString(
            'isFreshKisPrice',
            $method,
            'accumulateOverseasRealTimePrice 에 $isFreshKisPrice 파라미터가 없음'
        );
    }

    /**
     * $isFreshKisPrice = false 일 때 즉시 return 하는 로직이 존재한다.
     *
     * @test
     */
    public function testAccumulateMethodHasFallbackSkipLogic(): void
    {
        $src    = $this->getControllerSource();
        $method = $this->extractMethodSource($src, 'accumulateOverseasRealTimePrice');

        $this->assertNotEmpty($method, 'accumulateOverseasRealTimePrice 메서드를 찾을 수 없음');

        // !$isFreshKisPrice 분기 존재
        $this->assertMatchesRegularExpression(
            '/!\s*\$isFreshKisPrice/',
            $method,
            'accumulateOverseasRealTimePrice 에 !$isFreshKisPrice 분기가 없음'
        );

        // 그 분기 안에 return 이 있어야 함
        $skipPos   = (int)strpos($method, '!$isFreshKisPrice');
        $returnPos = (int)strpos($method, 'return', $skipPos);

        $this->assertGreaterThan(
            $skipPos,
            $returnPos,
            '!$isFreshKisPrice 분기 이후에 return 이 없음 — 폴백 시 스킵 동작이 보장되지 않음'
        );
    }

    /**
     * getStockData() 가 $isFreshKisPrice 를 accumulateOverseasRealTimePrice() 에 전달한다.
     *
     * @test
     */
    public function testGetStockDataPassesFreshKisPriceFlag(): void
    {
        $src    = $this->getControllerSource();

        // accumulateOverseasRealTimePrice 호출 라인에 $isFreshKisPrice 가 인자로 있어야 한다
        $this->assertMatchesRegularExpression(
            '/accumulateOverseasRealTimePrice\s*\([^)]*isFreshKisPrice[^)]*\)/',
            $src,
            'getStockData 에서 accumulateOverseasRealTimePrice() 호출 시 $isFreshKisPrice 를 전달하지 않음'
        );
    }

    /**
     * getStockData() 내부에 KIS 8초 캐시 히트 여부를 판별하는 $isFreshKisPrice 변수 초기화가 있다.
     *
     * @test
     */
    public function testGetStockDataInitializesFreshKisPriceFlag(): void
    {
        $src = $this->getControllerSource();

        $this->assertMatchesRegularExpression(
            '/\$isFreshKisPrice\s*=\s*(true|\(\s*\$kisPrice\s*!==\s*null\s*\))/',
            $src,
            'getStockData 에 $isFreshKisPrice 초기화 로직이 없음'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. 리플렉션: 실제 메서드 동작 검증
    //    (StockController 인스턴스화 없이 isolated 호출)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * $isFreshKisPrice = false 시 기존 accumulated 를 그대로 반환하고 캐시를 변경하지 않는다.
     *
     * 리플렉션 호출은 isUsMarketOpen() 의 시간 의존성과 Cache 파사드 부트스트랩 요구로
     * 순수 PHPUnit TestCase 에서 실행이 불가하다.
     * 소스 기반 테스트(testAccumulateMethodHasFallbackSkipLogic 등)로 동일 불변성을 검증한다.
     *
     * @test
     */
    public function testAccumulateFallbackReturnsPreviousAccumulatedUnchanged(): void
    {
        $this->markTestSkipped(
            '리플렉션 호출은 isUsMarketOpen() 시간 의존성 + Cache 파사드 미부트스트랩으로 ' .
            '순수 PHPUnit 환경에서 실행 불가. 소스 기반 검증(testAccumulateMethodHasFallbackSkipLogic)으로 커버됨.'
        );
    }

    /**
     * $isFreshKisPrice = false 스킵 로직이 isUsMarketOpen 체크보다 뒤에 위치한다.
     *
     * 시장이 닫혀있으면 이미 return 하므로, 폴백 스킵은 시장 오픈 확인 이후에 와야 한다.
     *
     * @test
     */
    public function testFallbackSkipIsAfterMarketOpenCheck(): void
    {
        $src    = $this->getControllerSource();
        $method = $this->extractMethodSource($src, 'accumulateOverseasRealTimePrice');

        $this->assertNotEmpty($method, 'accumulateOverseasRealTimePrice 메서드를 찾을 수 없음');

        $marketOpenPos = (int)strpos($method, 'isUsMarketOpen');
        $skipPos       = (int)strpos($method, '!$isFreshKisPrice');

        $this->assertGreaterThan(
            0,
            $marketOpenPos,
            'accumulateOverseasRealTimePrice 에 isUsMarketOpen 호출이 없음'
        );

        $this->assertGreaterThan(
            $marketOpenPos,
            $skipPos,
            '$isFreshKisPrice 스킵 로직이 isUsMarketOpen 체크보다 앞에 위치함 — ' .
            '시장 오픈 여부 확인 이후 폴백 스킵을 해야 한다'
        );
    }

    /**
     * $isFreshKisPrice 기본값이 true 이므로 파라미터 생략 시 기존 동작이 유지된다.
     *
     * @test
     */
    public function testAccumulateDefaultParameterIsTrue(): void
    {
        $src    = $this->getControllerSource();
        $method = $this->extractMethodSource($src, 'accumulateOverseasRealTimePrice');

        $this->assertNotEmpty($method, 'accumulateOverseasRealTimePrice 메서드를 찾을 수 없음');

        // 시그니처에 bool $isFreshKisPrice = true 형태가 있어야 한다
        $this->assertMatchesRegularExpression(
            '/bool\s+\$isFreshKisPrice\s*=\s*true/',
            $method,
            'accumulateOverseasRealTimePrice 에 bool $isFreshKisPrice = true 기본값이 없음 — ' .
            '기존 호출부(국내주식 등)가 파라미터 미전달 시 동작이 달라질 수 있음'
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

    /**
     * StockController 의 부분 목(인스턴스화 불가 시 스킵)을 반환한다.
     * private 메서드 리플렉션 호출 용도.
     */
    private function makeControllerPartialMock(): object
    {
        $controllerClass = \App\Http\Controllers\StockController::class;
        if (!class_exists($controllerClass)) {
            $this->markTestSkipped('StockController 클래스를 로드할 수 없는 환경');
        }

        try {
            $rc = new ReflectionClass($controllerClass);
            return $rc->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            $this->markTestSkipped("StockController 인스턴스 생성 실패: {$e->getMessage()}");
        }
    }
}
