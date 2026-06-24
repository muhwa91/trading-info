<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TossApiClient 단위 테스트.
 *
 * 검증 대상:
 *   - 401 응답 시 토큰 캐시 삭제 후 1회 재시도 (자동복구)
 *   - 재시도 후 성공하면 데이터 반환
 *   - 재시도도 401이면 빈 배열 반환 (무한루프 없음)
 *   - 401 외 4xx(403/404)는 재시도 없이 즉시 빈 배열 반환
 */
class TossApiClientTest extends TestCase
{
    private const TOKEN_CACHE_KEY = 'toss_access_token';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // 헬퍼: Guzzle MockHandler 로 TossApiClient 생성
    // ──────────────────────────────────────────────────────────────────

    /**
     * MockHandler 를 주입한 TossApiClient 인스턴스 생성.
     *
     * TossApiClient 는 생성자에서 Guzzle Client 를 new 하므로,
     * 리플렉션으로 httpClient 프로퍼티를 교체한다.
     *
     * @param  array<\GuzzleHttp\Psr7\Response|\Exception>  $responses
     */
    private function makeClientWithMock(array $responses): TossApiClient
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $http    = new Client(['handler' => $handler]);

        $client = new TossApiClient();

        // private $httpClient 교체 (PHP 7.4 리플렉션)
        $ref  = new \ReflectionClass($client);
        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, $http);

        return $client;
    }

    /**
     * Guzzle ClientException 생성 헬퍼.
     */
    private function makeClientException(int $status, string $body = '{}'): ClientException
    {
        $request  = new Request('GET', '/test');
        $response = new Response($status, [], $body);
        return new ClientException("HTTP {$status}", $request, $response);
    }

    // ──────────────────────────────────────────────────────────────────
    // 401 자동복구 — 핵심 검증
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testGet_401ThenSuccess_RetriesTokenAndReturnsData(): void
    {
        // 토큰을 캐시에 미리 세팅 (401 전 상태)
        Cache::put(self::TOKEN_CACHE_KEY, 'old-invalid-token', 3600);

        // 1차 호출: 401 ClientException
        // 2차 호출(토큰 재발급 후): 정상 200 응답
        $successBody = json_encode(['result' => [['symbol' => '005930', 'lastPrice' => 71000]]]);
        $client = $this->makeClientWithMock([
            $this->makeClientException(401, '{"error":"invalid-token"}'),
            // getAccessToken(true) 는 POST /oauth2/token 를 호출 — 토큰 발급 응답
            new Response(200, [], json_encode([
                'access_token' => 'new-valid-token',
                'token_type'   => 'Bearer',
                'expires_in'   => 86399,
            ])),
            // 재시도 GET 성공
            new Response(200, [], $successBody),
        ]);

        $result = $client->get('/api/v1/prices', ['symbols' => '005930']);

        // 데이터를 정상 반환해야 한다
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('result', $result);

        // 토큰 캐시가 새 토큰으로 교체됐어야 한다
        $this->assertSame('new-valid-token', Cache::get(self::TOKEN_CACHE_KEY));
    }

    /** @test */
    public function testGet_401ThenAgain401_ReturnsEmptyArrayNoLoop(): void
    {
        Cache::put(self::TOKEN_CACHE_KEY, 'old-token', 3600);

        $client = $this->makeClientWithMock([
            $this->makeClientException(401, '{"error":"invalid-token"}'),
            // 토큰 재발급 응답
            new Response(200, [], json_encode([
                'access_token' => 'new-token',
                'token_type'   => 'Bearer',
                'expires_in'   => 86399,
            ])),
            // 재시도도 401 → 더 이상 재시도 없이 빈 배열 반환
            $this->makeClientException(401, '{"error":"invalid-token"}'),
        ]);

        $result = $client->get('/api/v1/prices', ['symbols' => '005930']);

        // 무한루프 없이 빈 배열 반환
        $this->assertSame([], $result);
    }

    /** @test */
    public function testGet_403Forbidden_NoRetryReturnsEmpty(): void
    {
        Cache::put(self::TOKEN_CACHE_KEY, 'valid-token', 3600);

        $client = $this->makeClientWithMock([
            $this->makeClientException(403, '{"error":"forbidden"}'),
        ]);

        $result = $client->get('/api/v1/prices', ['symbols' => '005930']);

        // 403은 재시도 없이 즉시 빈 배열
        $this->assertSame([], $result);
        // 토큰 캐시는 유지 (403은 토큰 문제가 아님)
        $this->assertSame('valid-token', Cache::get(self::TOKEN_CACHE_KEY));
    }

    /** @test */
    public function testGet_404NotFound_NoRetryReturnsEmpty(): void
    {
        Cache::put(self::TOKEN_CACHE_KEY, 'valid-token', 3600);

        $client = $this->makeClientWithMock([
            $this->makeClientException(404, '{"error":"not-found"}'),
        ]);

        $result = $client->get('/api/v1/candles', ['symbol' => 'INVALID']);

        $this->assertSame([], $result);
        // 토큰 캐시 유지
        $this->assertSame('valid-token', Cache::get(self::TOKEN_CACHE_KEY));
    }

    /** @test */
    public function testGet_401NoToken_CacheIsCleared(): void
    {
        // 401 발생 후 토큰 캐시가 삭제되는지 검증
        // (토큰 재발급 POST 가 실패해도 캐시는 지워져야 함)
        Cache::put(self::TOKEN_CACHE_KEY, 'old-token', 3600);

        $client = $this->makeClientWithMock([
            $this->makeClientException(401, '{"error":"invalid-token"}'),
            // POST /oauth2/token 실패 (5xx)
            new \GuzzleHttp\Exception\ServerException(
                'Server Error',
                new Request('POST', '/oauth2/token'),
                new Response(500)
            ),
        ]);

        $result = $client->get('/api/v1/prices', ['symbols' => '005930']);

        // 토큰 발급 실패 → 재시도 불가 → 빈 배열
        $this->assertSame([], $result);
        // 토큰 캐시는 삭제된 상태
        $this->assertNull(Cache::get(self::TOKEN_CACHE_KEY));
    }
}
