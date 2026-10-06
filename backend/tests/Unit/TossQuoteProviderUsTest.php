<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\Quote\TossQuoteProvider;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TossQuoteProvider Phase 4 — 미국 종목 경로 단위 테스트.
 *
 * 검증 대상:
 *   - US 종목: fetchOverseasSingle 위임 경로 확인
 *   - US 종목: regular_close 필드 포함 여부
 *   - US 종목: API 실패 시 폴백 캐시 반환
 *   - KR 종목: 기존 경로 유지 (회귀 없음)
 *   - 지수: null 반환
 */
// 공용 priceFetcherMock 을 일부 테스트만 expects 로 검증 — setUp 공용 대역이라 클래스 단위로 허용
#[AllowMockObjectsWithoutExpectations]
class TossQuoteProviderUsTest extends TestCase
{
    private $priceFetcherMock;

    private TossQuoteProvider $provider;

    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priceFetcherMock = $this->createMock(TossPriceFetcher::class);
        $calculatorMock = $this->createStub(TossChangeCalculator::class);
        $this->mapper = new TossSymbolMapper;

        $this->provider = new TossQuoteProvider(
            $this->priceFetcherMock,
            $calculatorMock,
            $this->mapper
        );

        Cache::flush();
    }

    #[Test]
    public function test_fetch_quote_index_symbol_returns_null(): void
    {
        $stock = new Stock;
        $stock->symbol = 'NQ=F';
        $stock->market = 'US';

        $this->priceFetcherMock->expects($this->never())->method('fetchOverseasSingle');

        $result = $this->provider->fetchQuote($stock, 'regular');

        $this->assertNull($result);
    }

    #[Test]
    public function test_fetch_quote_us_stock_calls_fetch_overseas_single(): void
    {
        $stock = new Stock;
        $stock->symbol = 'TSLA';
        $stock->market = 'US';

        $this->priceFetcherMock
            ->expects($this->once())
            ->method('fetchOverseasSingle')
            ->with('TSLA')
            ->willReturn([
                'price' => 207.5,
                'change_amount' => -2.5,
                'change_percent' => -1.2,
                'regular_close' => 210.0,
            ]);

        $result = $this->provider->fetchQuote($stock, 'after');

        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
        $this->assertSame(-2.5, $result['change_amount']);
        $this->assertSame(-1.2, $result['change_percent']);
        $this->assertSame(210.0, $result['regular_close']);
        $this->assertArrayHasKey('recorded_at', $result);
    }

    #[Test]
    public function test_fetch_quote_us_stock_regular_close_passed_through(): void
    {
        $stock = new Stock;
        $stock->symbol = 'MU';
        $stock->market = 'US';

        $this->priceFetcherMock
            ->method('fetchOverseasSingle')
            ->willReturn([
                'price' => 101.0,
                'change_amount' => 1.0,
                'change_percent' => 1.0,
                'regular_close' => 100.0,  // 정규장 종가
            ]);

        $result = $this->provider->fetchQuote($stock, 'pre');

        $this->assertSame(100.0, $result['regular_close']);
    }

    #[Test]
    public function test_fetch_quote_us_stock_null_regular_close_returns_null_field(): void
    {
        $stock = new Stock;
        $stock->symbol = 'AAPL';
        $stock->market = 'US';

        $this->priceFetcherMock
            ->method('fetchOverseasSingle')
            ->willReturn([
                'price' => 195.0,
                'change_amount' => 0.5,
                'change_percent' => 0.26,
                'regular_close' => null,  // Yahoo 실패 등으로 null
            ]);

        $result = $this->provider->fetchQuote($stock, 'regular');

        $this->assertNull($result['regular_close']);
    }

    #[Test]
    public function test_fetch_quote_us_stock_fetcher_returns_null_uses_overseas_fallback_cache(): void
    {
        $stock = new Stock;
        $stock->symbol = 'TSLA';
        $stock->market = 'US';

        // 폴백 캐시 세팅
        $fallback = [
            'price' => 200.0,
            'change_amount' => -5.0,
            'change_percent' => -2.4,
            'regular_close' => 205.0,
        ];
        Cache::put('kis_last_successful_overseas_price_TSLA', $fallback, 86400);

        $this->priceFetcherMock
            ->method('fetchOverseasSingle')
            ->willReturn(null);  // 완전 실패

        $result = $this->provider->fetchQuote($stock, 'regular');

        $this->assertNotNull($result);
        $this->assertSame(200.0, $result['price']);
    }

    #[Test]
    public function test_fetch_quote_us_stock_null_fallback_returns_null(): void
    {
        $stock = new Stock;
        $stock->symbol = 'SOXL';
        $stock->market = 'US';

        $this->priceFetcherMock
            ->method('fetchOverseasSingle')
            ->willReturn(null);

        $result = $this->provider->fetchQuote($stock, 'regular');

        $this->assertNull($result);
    }

    #[Test]
    public function test_fetch_quote_kr_stock_calls_fetch_single_not_overseas(): void
    {
        // 국내 종목은 fetchSingle 경로 사용 — fetchOverseasSingle 호출 없어야 함
        $stock = new Stock;
        $stock->symbol = '005930';
        $stock->market = 'KR';

        $this->priceFetcherMock
            ->expects($this->never())
            ->method('fetchOverseasSingle');

        $this->priceFetcherMock
            ->expects($this->once())
            ->method('fetchSingle')
            ->with('005930')
            ->willReturn([
                'price' => 71000.0,
                'change_amount' => 500.0,
                'change_percent' => 0.71,
            ]);

        $result = $this->provider->fetchQuote($stock, 'regular');

        $this->assertNotNull($result);
        $this->assertSame(71000.0, $result['price']);
        $this->assertNull($result['regular_close']);  // KR은 regular_close 없음
    }
}
