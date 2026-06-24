<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossSymbolMapper;
use PHPUnit\Framework\TestCase;

/**
 * TossSymbolMapper — 심볼 정규화·시장분류·지수 skip 단위 테스트.
 *
 * 검증 대상 (설계 §2.3, Phase 1 요구사항):
 *   toTossSymbol(): 정규화 + 지수 시 null
 *   market(): KR / US / INDEX 분류
 *   isIndex() / shouldSkip(): 지수 판정
 *   toTossSymbols(): 복수 변환 + 지수 필터링
 *
 * 대표 케이스:
 *   TSLA       → TSLA  (US)
 *   005930.KS  → 005930 (KR)
 *   005935.KS  → 005935 (KR, 우선주)
 *   0167A0     → 0167A0 (KR, 신형 영숫자)
 *   0167AO     → 0167A0 (KR, 오타교정 O→0)
 *   NQ=F       → null   (INDEX skip)
 *   KOSPI200   → null   (INDEX skip)
 */
class TossSymbolMapperTest extends TestCase
{
    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TossSymbolMapper();
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 미국 티커
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsTicker_TSLA_PassesThrough(): void
    {
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('TSLA'));
    }

    /** @test */
    public function testUsTicker_MU_PassesThrough(): void
    {
        $this->assertSame('MU', $this->mapper->toTossSymbol('MU'));
    }

    /** @test */
    public function testUsTicker_SOXL_PassesThrough(): void
    {
        $this->assertSame('SOXL', $this->mapper->toTossSymbol('SOXL'));
    }

    /** @test */
    public function testUsTicker_TQQQ_PassesThrough(): void
    {
        $this->assertSame('TQQQ', $this->mapper->toTossSymbol('TQQQ'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 국내 종목 (.KS/.KQ 접미사 제거)
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomestic_005930KS_RemovesSuffix(): void
    {
        $this->assertSame('005930', $this->mapper->toTossSymbol('005930.KS'));
    }

    /** @test */
    public function testDomestic_PreferredStock_005935KS_RemovesSuffix(): void
    {
        $this->assertSame('005935', $this->mapper->toTossSymbol('005935.KS'));
    }

    /** @test */
    public function testDomestic_KQSuffix_RemovesSuffix(): void
    {
        $this->assertSame('000660', $this->mapper->toTossSymbol('000660.KQ'));
    }

    /** @test */
    public function testDomestic_LowercaseSuffix_RemovesSuffix(): void
    {
        $this->assertSame('005930', $this->mapper->toTossSymbol('005930.ks'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 신형 영숫자 코드
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testAlphanumericCode_0167A0_PassesThrough(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167A0'));
    }

    /** @test */
    public function testAlphanumericCode_0167A0_WithKQSuffix_RemovesSuffix(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167A0.KQ'));
    }

    /** @test */
    public function testTypoCorrection_0167AO_ConvertsOToZero(): void
    {
        // 0167AO (영문 O) → 0167A0 (숫자 0) 오타교정
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167AO'));
    }

    /** @test */
    public function testTypoCorrection_0167AO_WithKSSuffix(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167AO.KS'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 지수 skip (null 반환)
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testIndex_NQF_ReturnsNull(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('NQ=F'));
    }

    /** @test */
    public function testIndex_KS200_ReturnsNull(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('^KS200'));
    }

    /** @test */
    public function testIndex_USDKRW_ReturnsNull(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('USDKRW=X'));
    }

    /** @test */
    public function testIndex_KOSPI_NIGHT_ReturnsNull(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('KOSPI_NIGHT'));
    }

    /** @test */
    public function testIndex_KOSPI200_ReturnsNull(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('KOSPI200'));
    }

    // ──────────────────────────────────────────────────────────────────
    // market() — 시장 분류
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testMarket_USTicker_ReturnsUS(): void
    {
        $this->assertSame('US', $this->mapper->market('TSLA'));
        $this->assertSame('US', $this->mapper->market('MU'));
        $this->assertSame('US', $this->mapper->market('SOXL'));
    }

    /** @test */
    public function testMarket_DomesticKS_ReturnsKR(): void
    {
        $this->assertSame('KR', $this->mapper->market('005930.KS'));
        $this->assertSame('KR', $this->mapper->market('005935.KS'));
    }

    /** @test */
    public function testMarket_AlphanumericCode_ReturnsKR(): void
    {
        $this->assertSame('KR', $this->mapper->market('0167A0'));
    }

    /** @test */
    public function testMarket_PureNumericCode_ReturnsKR(): void
    {
        $this->assertSame('KR', $this->mapper->market('005930'));
        $this->assertSame('KR', $this->mapper->market('000660'));
    }

    /** @test */
    public function testMarket_IndexSymbols_ReturnsINDEX(): void
    {
        $this->assertSame('INDEX', $this->mapper->market('NQ=F'));
        $this->assertSame('INDEX', $this->mapper->market('KOSPI200'));
        $this->assertSame('INDEX', $this->mapper->market('KOSPI_NIGHT'));
    }

    // ──────────────────────────────────────────────────────────────────
    // isIndex() / shouldSkip()
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testIsIndex_IndexSymbols_ReturnsTrue(): void
    {
        $this->assertTrue($this->mapper->isIndex('NQ=F'));
        $this->assertTrue($this->mapper->isIndex('^KS200'));
        $this->assertTrue($this->mapper->isIndex('USDKRW=X'));
        $this->assertTrue($this->mapper->isIndex('KOSPI_NIGHT'));
        $this->assertTrue($this->mapper->isIndex('KOSPI200'));
    }

    /** @test */
    public function testIsIndex_NormalSymbols_ReturnsFalse(): void
    {
        $this->assertFalse($this->mapper->isIndex('TSLA'));
        $this->assertFalse($this->mapper->isIndex('005930.KS'));
        $this->assertFalse($this->mapper->isIndex('0167A0'));
    }

    /** @test */
    public function testShouldSkip_AliasOfIsIndex(): void
    {
        $this->assertTrue($this->mapper->shouldSkip('KOSPI200'));
        $this->assertFalse($this->mapper->shouldSkip('TSLA'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbols() — 복수 변환 + 지수 필터
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testToTossSymbols_FiltersIndexAndNormalizesRest(): void
    {
        $input = [
            'TSLA',        // US → TSLA
            '005930.KS',   // KR → 005930
            '0167AO',      // 오타교정 → 0167A0
            'NQ=F',        // INDEX → 제거
            'KOSPI200',    // INDEX → 제거
        ];

        $result = $this->mapper->toTossSymbols($input);

        $this->assertSame(['TSLA', '005930', '0167A0'], $result);
    }

    /** @test */
    public function testToTossSymbols_EmptyInput_ReturnsEmpty(): void
    {
        $this->assertSame([], $this->mapper->toTossSymbols([]));
    }

    /** @test */
    public function testToTossSymbols_AllIndexSkipped_ReturnsEmpty(): void
    {
        $result = $this->mapper->toTossSymbols(['NQ=F', 'KOSPI200', '^KS200']);
        $this->assertSame([], $result);
    }

    // ──────────────────────────────────────────────────────────────────
    // 엣지 케이스
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testWhitespace_Trimmed(): void
    {
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('  TSLA  '));
        $this->assertSame('005930', $this->mapper->toTossSymbol('  005930.KS  '));
    }

    /** @test */
    public function testLowercaseUsTicker_UppercasedToUS(): void
    {
        // 소문자 미국 티커 → 대문자화 (US 분류 유지)
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('tsla'));
        $this->assertSame('US', $this->mapper->market('tsla'));
    }
}
