<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\KrStockResolver;
use PHPUnit\Framework\TestCase;

/**
 * KrStockResolver — DB 없이 순수 로직 단위 테스트.
 *
 * Phase 7 이후 변경 사항:
 *   - name·type·currency 컬럼이 stocks 테이블에서 삭제됨.
 *   - resolveType/resolveName 등 DB 저장용 private 메서드 제거.
 *   - ETF 타입 판정은 TossStockMaster accessor(getTypeAttribute) 가 담당.
 *   - 이 테스트는 여전히 유효한 normalize() 만 검증한다.
 *
 * 검증 항목:
 *   1. normalize(): 접미사 제거 (.KS/.KQ 대소문자 포함)
 *   2. normalize(): 접미사 없는 경우 그대로 반환
 *   3. normalize(): 공백 트림
 *   4. normalize(): 특수 코드 (0167A0.KQ)
 *
 * PHP 7.4 환경.
 */
class KrStockResolverPureTest extends TestCase
{
    /** @var KrStockResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KrStockResolver();
    }

    // ──────────────────────────────────────────────────────────────
    // 1. normalize: .KS 접미사 제거
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testNormalizeRemovesKsSuffix(): void
    {
        $this->assertSame('005930', $this->resolver->normalize('005930.KS'));
    }

    /** @test */
    public function testNormalizeRemovesKqSuffix(): void
    {
        $this->assertSame('0167A0', $this->resolver->normalize('0167A0.KQ'));
    }

    /** @test */
    public function testNormalizeCaseInsensitiveSuffix(): void
    {
        // 소문자 .ks 도 제거
        $this->assertSame('005930', $this->resolver->normalize('005930.ks'));
        // 소문자 .kq 도 제거
        $this->assertSame('000660', $this->resolver->normalize('000660.kq'));
    }

    /** @test */
    public function testNormalizeUppercasesResult(): void
    {
        // 알파벳 포함 코드: 소문자 입력이어도 대문자로
        $this->assertSame('0167A0', $this->resolver->normalize('0167a0.KQ'));
    }

    /** @test */
    public function testNormalizeNoSuffixPassThrough(): void
    {
        // 접미사 없는 6자리 코드 → 그대로(대문자화)
        $this->assertSame('005930', $this->resolver->normalize('005930'));
    }

    /** @test */
    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('005930', $this->resolver->normalize('  005930.KS  '));
    }

    /** @test */
    public function testNormalizeSpecialAlphanumericCode(): void
    {
        // 0167AO (오 아님 영문 O) 접미사 포함
        $this->assertSame('0167AO', $this->resolver->normalize('0167AO.KS'));
    }
}
