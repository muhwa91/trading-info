<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossFxProvider;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TossFxProvider — 환율 응답 파싱 단위 테스트.
 *
 * TossApiClient 는 Mockery 로 스텁하여 HTTP 호출 없이 파싱 로직만 검증.
 * Log 파사드는 spy() 로 기록만 — 실제 출력 없이 통과.
 *
 * 검증 대상:
 *   - 정상 응답: rate 문자열 → float 파싱, source 고정값
 *   - 빈 응답([]): null 반환
 *   - result 키 없음: null 반환
 *   - rate 필드 없음: null 반환
 *   - rate = "0" 또는 음수: null 반환
 *   - 소수점 포함 rate: round(4) 적용
 *   - 클라이언트 예외: null 반환
 */
class TossFxProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    // ──────────────────────────────────────────────────────────────────
    // 정상 케이스
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchUsdKrw_ValidResponse_ReturnsFloat(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => [
                'baseCurrency'   => 'USD',
                'quoteCurrency'  => 'KRW',
                'rate'           => '1548.82',
                'midRate'        => '1548.32',
                'rateChangeType' => 'DOWN',
                'validFrom'      => '2026-06-24T00:00:00',
                'validUntil'     => '2026-06-24T23:59:59',
            ],
        ]);

        $provider = new TossFxProvider($client);
        $result   = $provider->fetchUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(1548.82, $result['rate']);
        $this->assertSame('Toss_ExchangeRate', $result['source']);
        $this->assertArrayHasKey('recorded_at', $result);
    }

    /** @test */
    public function testFetchUsdKrw_RateWithManyDecimals_RoundedTo4(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['rate' => '1548.12345678'],
        ]);

        $provider = new TossFxProvider($client);
        $result   = $provider->fetchUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(round(1548.12345678, 4), $result['rate']);
    }

    /** @test */
    public function testFetchUsdKrw_IntegerRateString_ParsedCorrectly(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['rate' => '1500'],
        ]);

        $provider = new TossFxProvider($client);
        $result   = $provider->fetchUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(1500.0, $result['rate']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 실패 케이스 → null
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchUsdKrw_EmptyResponse_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([]);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_MissingResultKey_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn(['error' => 'some_error']);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_MissingRateField_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['baseCurrency' => 'USD', 'quoteCurrency' => 'KRW'],
        ]);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_RateZero_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['rate' => '0'],
        ]);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_NegativeRate_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['rate' => '-100.0'],
        ]);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_EmptyRateString_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturn([
            'result' => ['rate' => '   '],
        ]);

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }

    /** @test */
    public function testFetchUsdKrw_ClientThrowsException_ReturnsNull(): void
    {
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willThrowException(new \RuntimeException('network error'));

        $provider = new TossFxProvider($client);
        $this->assertNull($provider->fetchUsdKrw());
    }
}
