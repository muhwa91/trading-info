<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\StockController;
use PHPUnit\Framework\TestCase;

/**
 * StockController::applyBadTickClamp() 단위 테스트.
 *
 * 배경:
 *   Yahoo Finance가 프리마켓/애프터마켓(volume=0) 봉의 low/high에
 *   직전 정규장 세션 극값을 잘못 복사(bad tick)해 차트에 비정상 spike wick이 생기는 버그.
 *   클램프 규칙(volume=0 봉 전용):
 *     low  < bodyMin * 0.9875(1.25% 미달) → bodyMin 으로 클램프
 *     high > bodyMax * 1.0125(1.25% 초과) → bodyMax 로 클램프
 *   volume>0(정규장) 봉은 절대 건드리지 않는다.
 *
 * 검증 케이스:
 *   1. volume=0, low가 1.25% 이상 낮으면 bodyMin으로 클램프
 *   2. volume=0, high가 1.25% 이상 높으면 bodyMax로 클램프
 *   3. volume=0, low와 high 모두 이상 → 둘 다 클램프
 *   4. volume>0(정규장) 봉은 큰 변동이어도 클램프 안 함 (false positive 방지)
 *   5. volume=0이지만 정상 범위 안의 low/high는 그대로 통과
 *   6. 임계치 경계: 정확히 1.25% 미달(= bodyMin * 0.9875) 은 클램프 안 함
 *   7. 임계치 경계: 정확히 1.25% 초과(= bodyMax * 1.0125) 는 클램프 안 함
 *   8. open > close인 음봉에서도 bodyMin/bodyMax가 올바르게 결정됨
 *   9. bodyMin = 0(division by zero 방어) → 클램프 안 함
 *
 * PHP 7.4 환경: Reflection으로 private 메서드 접근.
 */
class BadTickClampTest extends TestCase
{
    /** @var StockController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // StockController 생성자: MarketSessionService + TossPriceFetcher + TossCandleProvider + TossStockMaster (Phase 7 추가)
        $sessionService      = $this->createMock(\App\Services\MarketSessionService::class);
        $tossPriceFetcher    = $this->createMock(\App\Services\Toss\TossPriceFetcher::class);
        $tossCandleProvider  = $this->createMock(\App\Services\Toss\TossCandleProvider::class);
        $stockMaster         = $this->createMock(\App\Services\Toss\TossStockMaster::class);
        $this->controller = new StockController($sessionService, $tossPriceFetcher, $tossCandleProvider, $stockMaster);
    }

    /**
     * Reflection으로 private applyBadTickClamp 를 호출한다.
     *
     * @return array{float, float}
     */
    private function clamp(float $open, float $close, float $low, float $high, int $volume): array
    {
        $ref = new \ReflectionMethod(StockController::class, 'applyBadTickClamp');
        $ref->setAccessible(true);
        return $ref->invoke($this->controller, $open, $close, $low, $high, $volume);
    }

    // ──────────────────────────────────────────────────────────────
    // 1. volume=0, low가 1.25% 이상 낮으면 bodyMin으로 클램프
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testVolumeZeroLowBadTickClampedToBodyMin(): void
    {
        // 실측 케이스: MU 프리마켓 봉 open=1180, close=1181, low=1133.99 (4.38% 낮음)
        // bodyMin = min(1180, 1181) = 1180
        // 1133.99 < 1180 * 0.9875(=1165.25) → 클램프 대상
        [$low, $high] = $this->clamp(1180.0, 1181.0, 1133.99, 1182.0, 0);

        $this->assertSame(1180.0, $low, 'bad-tick low는 bodyMin(1180)으로 클램프되어야 한다');
        $this->assertSame(1182.0, $high, 'high는 정상이므로 그대로여야 한다');
    }

    /** @test */
    public function testVolumeZeroLowClampedAtExactlyOnePointThirtyPercent(): void
    {
        // bodyMin = 1000, low = 987.4 (1.26% 낮음) → 1.25% 임계치 초과이므로 클램프
        // bodyMin * 0.9875 = 987.5, 987.4 < 987.5 → 클램프
        [$low, $high] = $this->clamp(1000.0, 1005.0, 987.4, 1006.0, 0);

        $this->assertSame(1000.0, $low, '1.25% 이상 낮은 low는 bodyMin으로 클램프');
    }

    // ──────────────────────────────────────────────────────────────
    // 2. volume=0, high가 1.25% 이상 높으면 bodyMax로 클램프
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testVolumeZeroHighBadTickClampedToBodyMax(): void
    {
        // open=500, close=498 → bodyMax = 500
        // high = 510 (2% 높음) → 510 > 500 * 1.0125(=506.25) → 클램프
        [$low, $high] = $this->clamp(500.0, 498.0, 497.0, 510.0, 0);

        $this->assertSame(497.0, $low, 'low는 정상이므로 그대로여야 한다');
        $this->assertSame(500.0, $high, 'bad-tick high는 bodyMax(500)으로 클램프되어야 한다');
    }

    // ──────────────────────────────────────────────────────────────
    // 3. volume=0, low와 high 모두 bad tick → 둘 다 클램프
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testVolumeZeroBothLowAndHighClamped(): void
    {
        // open=200, close=202 → bodyMin=200, bodyMax=202
        // low=190 (5% 낮음), high=215 (6.4% 높음) → 둘 다 클램프
        [$low, $high] = $this->clamp(200.0, 202.0, 190.0, 215.0, 0);

        $this->assertSame(200.0, $low,  'low도 bodyMin으로 클램프되어야 한다');
        $this->assertSame(202.0, $high, 'high도 bodyMax로 클램프되어야 한다');
    }

    // ──────────────────────────────────────────────────────────────
    // 4. volume>0(정규장) 봉은 큰 변동이어도 클램프 안 함 (false positive 방지)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testVolumePositiveRegularSessionNotClamped(): void
    {
        // 정규장에서 실제로 하락 폭이 큰 봉: open=1180, close=1181, low=1133.99
        // volume>0 이므로 절대 클램프해선 안 된다
        [$low, $high] = $this->clamp(1180.0, 1181.0, 1133.99, 1200.0, 500000);

        $this->assertSame(1133.99, $low,  'volume>0 봉의 low는 클램프되지 않아야 한다');
        $this->assertSame(1200.0, $high, 'volume>0 봉의 high는 클램프되지 않아야 한다');
    }

    /** @test */
    public function testVolumePositiveHugeWickNotClamped(): void
    {
        // 정규장 50% 갭다운 같은 극단 케이스도 보존
        [$low, $high] = $this->clamp(100.0, 98.0, 50.0, 150.0, 1);

        $this->assertSame(50.0, $low);
        $this->assertSame(150.0, $high);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. volume=0 이지만 정상 범위(1.25% 미만) → 클램프 안 함
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testVolumeZeroNormalWickPassesThrough(): void
    {
        // bodyMin=1180, low=1179 (0.084% 낮음) → 임계치 이내이므로 통과
        [$low, $high] = $this->clamp(1180.0, 1181.0, 1179.0, 1182.0, 0);

        $this->assertSame(1179.0, $low,  '정상 범위 low는 그대로 통과해야 한다');
        $this->assertSame(1182.0, $high, '정상 범위 high는 그대로 통과해야 한다');
    }

    /** @test */
    public function testVolumeZeroLowAtOnePercentBelowPassesThrough(): void
    {
        // bodyMin=1000, low=990 (1.0% 낮음) → 임계치(1.25%) 이내 → 통과
        [$low, $high] = $this->clamp(1000.0, 1003.0, 990.0, 1004.0, 0);

        $this->assertSame(990.0, $low, '1.0% 낮은 low는 임계치 이내이므로 통과해야 한다');
    }

    // ──────────────────────────────────────────────────────────────
    // 6. low 임계치 경계: bodyMin * 0.9875 와 같으면 클램프 안 함
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testLowAtExactThresholdNotClamped(): void
    {
        // bodyMin=1000 → 임계치 = 1000 * 0.9875 = 987.5
        // low=987.5 → 조건은 low < 987.5 이므로 987.5 는 클램프되지 않는다
        [$low, $high] = $this->clamp(1000.0, 1002.0, 987.5, 1003.0, 0);

        $this->assertSame(987.5, $low, '정확히 경계값(bodyMin * 0.9875)은 클램프 안 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 7. high 임계치 경계: bodyMax * 1.0125 와 같으면 클램프 안 함
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testHighAtExactThresholdNotClamped(): void
    {
        // bodyMax=1000 → 임계치 = 1000 * 1.0125 = 1012.5
        // high=1012.5 → 조건은 high > 1012.5 이므로 1012.5 는 클램프되지 않는다
        [$low, $high] = $this->clamp(998.0, 1000.0, 997.0, 1012.5, 0);

        $this->assertSame(1012.5, $high, '정확히 경계값(bodyMax * 1.0125)은 클램프 안 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 8. 음봉(open > close)에서도 bodyMin/bodyMax 올바르게 결정
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testBearishCandleBodyMinMaxCorrect(): void
    {
        // 음봉: open=1200, close=1190 → bodyMin=1190, bodyMax=1200
        // low=1170 (1.68% 낮음) → 클램프 (1170 < 1190 * 0.9875 = 1175.125)
        // high=1215 (1.25% 높음) → 1215 > 1200 * 1.0125(=1215) 는 경계이므로 클램프 안 함
        [$low, $high] = $this->clamp(1200.0, 1190.0, 1170.0, 1215.0, 0);

        $this->assertSame(1190.0, $low,  '음봉 low bad-tick → bodyMin(close=1190)으로 클램프');
        $this->assertSame(1215.0, $high, '음봉 high가 경계값이면 클램프 안 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 9. bodyMin = 0 방어 (division by zero 없이 클램프 안 함)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testBodyMinZeroSkipsClamp(): void
    {
        // open=close=0 → bodyMin=bodyMax=0 → 조건 "$bodyMin > 0" 미충족 → 그대로
        [$low, $high] = $this->clamp(0.0, 0.0, -5.0, 5.0, 0);

        $this->assertSame(-5.0, $low,  'bodyMin=0이면 low 클램프 건너뜀');
        $this->assertSame(5.0,  $high, 'bodyMax=0이면 high 클램프 건너뜀');
    }
}
