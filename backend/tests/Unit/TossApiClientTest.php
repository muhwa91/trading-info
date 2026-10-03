<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
     * @param  array<Response|\Exception>  $responses
     */
    private function makeClientWithMock(array $responses): TossApiClient
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $http = new Client(['handler' => $handler]);

        $client = new TossApiClient;

        // private $httpClient 교체 (PHP 7.4 리플렉션)
        $ref = new \ReflectionClass($client);
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
        $request = new Request('GET', '/test');
        $response = new Response($status, [], $body);

        return new ClientException("HTTP {$status}", $request, $response);
    }

    // ──────────────────────────────────────────────────────────────────
    // 401 자동복구 — 핵심 검증
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_get_401_then_success_retries_token_and_returns_data(): void
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
                'token_type' => 'Bearer',
                'expires_in' => 86399,
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

    #[Test]
    public function test_get_401_then_again401_returns_empty_array_no_loop(): void
    {
        Cache::put(self::TOKEN_CACHE_KEY, 'old-token', 3600);

        $client = $this->makeClientWithMock([
            $this->makeClientException(401, '{"error":"invalid-token"}'),
            // 토큰 재발급 응답
            new Response(200, [], json_encode([
                'access_token' => 'new-token',
                'token_type' => 'Bearer',
                'expires_in' => 86399,
            ])),
            // 재시도도 401 → 더 이상 재시도 없이 빈 배열 반환
            $this->makeClientException(401, '{"error":"invalid-token"}'),
        ]);

        $result = $client->get('/api/v1/prices', ['symbols' => '005930']);

        // 무한루프 없이 빈 배열 반환
        $this->assertSame([], $result);
    }

    #[Test]
    public function test_get_403_forbidden_no_retry_returns_empty(): void
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

    #[Test]
    public function test_get_404_not_found_no_retry_returns_empty(): void
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

    #[Test]
    public function test_get_401_no_token_cache_is_cleared(): void
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

    // ──────────────────────────────────────────────────────────────────
    // 공유 토큰 파일 (%LOCALAPPDATA%\chiikawa — companion-app 와 같은 규약, 2026-10-04)
    //   테스트는 임시 폴더를 주입한다 — 실제 공유 파일(=실제 토큰)은 건드리지 않는다.
    // ──────────────────────────────────────────────────────────────────

    private string $sharedDir = '';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function tearDown(): void
    {
        if ($this->sharedDir !== '' && is_dir($this->sharedDir)) {
            foreach (glob($this->sharedDir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->sharedDir);
        }
        parent::tearDown();
    }

    /**
     * @param  array<mixed>  $responses
     * @param  class-string<TossApiClient>  $clientClass  잠금 상수를 덮은 테스트용 서브클래스(아래 참고)
     */
    private function makeSharedClient(array $responses, string $clientClass = TossApiClient::class): TossApiClient
    {
        config([
            'services.toss.client_id' => 'test-id',
            'services.toss.client_secret' => 'test-secret',
        ]);
        $this->sharedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'toss-shared-' . bin2hex(random_bytes(4));
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $client = new $clientClass($this->sharedDir);
        // private 속성은 선언한 부모 클래스로 찾아야 한다(하위 테스트 클래스로는 안 보인다)
        $ref = new \ReflectionClass(TossApiClient::class);
        $prop = $ref->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($client, new Client(['handler' => $stack]));

        return $client;
    }

    private function writeShared(string $token, int $expiresAt, string $issuer = 'companion-app'): void
    {
        if (! is_dir($this->sharedDir)) {
            mkdir($this->sharedDir, 0777, true);
        }
        file_put_contents($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.json', json_encode([
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'issued_at' => time(),
            'issuer' => $issuer,
        ]));
    }

    /** @return array<string, mixed> */
    private function readShared(): array
    {
        return json_decode((string) file_get_contents($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.json'), true);
    }

    private function lockPath(): string
    {
        return $this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.lock';
    }

    /** 재발급 상한 기록 — companion-app `toss_issue.json` 과 같은 이름·스키마 */
    private function issuePath(): string
    {
        return $this->sharedDir . DIRECTORY_SEPARATOR . 'toss_issue.json';
    }

    /** @param array<string, mixed> $record */
    private function writeIssue(array $record): void
    {
        if (! is_dir($this->sharedDir)) {
            mkdir($this->sharedDir, 0700, true);
        }
        file_put_contents($this->issuePath(), json_encode($record));
    }

    /** @return array<string, mixed> */
    private function readIssue(): array
    {
        return json_decode((string) file_get_contents($this->issuePath()), true);
    }

    private function tokenResponse(string $token): Response
    {
        return new Response(200, [], json_encode(['access_token' => $token, 'expires_in' => 86399]));
    }

    /** @return list<string> 요청별 "메서드 경로 Bearer토큰" */
    private function sent(): array
    {
        return array_map(function (array $t): string {
            $r = $t['request'];

            return $r->getMethod() . ' ' . $r->getUri()->getPath() . ' ' . $r->getHeaderLine('Authorization');
        }, $this->history);
    }

    #[Test]
    public function test_default_shared_dir_is_off_during_unit_tests(): void
    {
        $prop = (new \ReflectionClass(TossApiClient::class))->getProperty('sharedDir');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue(new TossApiClient));
    }

    #[Test]
    public function test_shared_uses_other_apps_token_without_issuing(): void
    {
        $client = $this->makeSharedClient([new Response(200, [], '{"result":[]}')]);
        $this->writeShared('other-token', time() + 3600);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame(['GET /api/v1/prices Bearer other-token'], $this->sent());
    }

    #[Test]
    public function test_shared_issues_and_writes_file_when_missing(): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{"result":[]}')]);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $saved = $this->readShared();
        $this->assertSame('ti-new', $saved['access_token']);
        $this->assertSame('trading-info', $saved['issuer']);
        $this->assertEqualsWithDelta(time() + 86399, $saved['expires_at'], 5);
        $this->assertFileDoesNotExist($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.lock');
        $this->assertSame([], glob($this->sharedDir . DIRECTORY_SEPARATOR . '.toss_token.*.tmp'));
        // 공유 모드에서는 Laravel Cache 를 쓰지 않는다
        $this->assertNull(Cache::get(self::TOKEN_CACHE_KEY));
    }

    #[Test]
    public function test_shared_nearly_expired_token_is_reissued(): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{}')]);
        $this->writeShared('old', time() + 200);  // 만료 5분 안 — 쓰지 않는다

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame('POST /oauth2/token ', $this->sent()[0]);
        $this->assertSame('ti-new', $this->readShared()['access_token']);
    }

    #[Test]
    public function test_shared_401_with_other_token_in_file_retries_without_issuing(): void
    {
        $client = $this->makeSharedClient([
            // 우리 요청이 401 을 받는 사이 다른 로컬 앱가 새 토큰을 받아 파일에 썼다
            function ($request) {
                $this->writeShared('other-fresh', time() + 86399);

                return Create::rejectionFor($this->makeClientException(401));
            },
            new Response(200, [], '{"result":[1]}'),
        ]);
        $this->writeShared('ti-old', time() + 3600, 'trading-info');

        $result = $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame(['result' => [1]], $result);
        $this->assertSame([
            'GET /api/v1/prices Bearer ti-old',
            'GET /api/v1/prices Bearer other-fresh',  // 발급(POST) 없이 파일 토큰으로
        ], $this->sent());
    }

    #[Test]
    public function test_shared_401_with_same_token_reissues_under_lock(): void
    {
        $client = $this->makeSharedClient([
            $this->makeClientException(401),
            $this->tokenResponse('ti-new'),
            new Response(200, [], '{"result":[1]}'),
        ]);
        $this->writeShared('ti-old', time() + 3600, 'trading-info');

        $this->assertSame(['result' => [1]], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame('POST /oauth2/token ', $this->sent()[1]);
        $this->assertSame('GET /api/v1/prices Bearer ti-new', $this->sent()[2]);
        $this->assertSame('ti-new', $this->readShared()['access_token']);
    }

    /**
     * 깨진·빈·만료시각 없는 공유 파일은 «토큰 없음»으로 보고 새로 발급한다.
     * (조용히 빈 토큰으로 요청을 보내면 토스가 401 을 주고 기능이 죽는다)
     */
    #[Test]
    #[DataProvider('brokenSharedFiles')]
    public function test_shared_broken_file_is_ignored_and_token_issued(string $raw): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{}')]);
        mkdir($this->sharedDir, 0777, true);
        file_put_contents($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.json', $raw);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame('POST /oauth2/token ', $this->sent()[0]);
        $this->assertSame('GET /api/v1/prices Bearer ti-new', $this->sent()[1]);
        $this->assertSame('ti-new', $this->readShared()['access_token']);
    }

    /** @return array<string, array{string}> */
    public static function brokenSharedFiles(): array
    {
        return [
            '빈 파일' => [''],
            '깨진 JSON' => ['{"access_token": "x", "expires_at": '],
            '토큰 빈 문자열' => ['{"access_token": "", "expires_at": 9999999999}'],
            '만료시각 없음' => ['{"access_token": "x"}'],
            '만료시각이 숫자가 아님' => ['{"access_token": "x", "expires_at": "내일"}'],
            'JSON 이 배열' => ['[1, 2]'],
        ];
    }

    /** 발급이 실패하면 파일의 기존 토큰을 덮지 않고, 잠금은 풀어 둔다. */
    #[Test]
    public function test_shared_issue_failure_keeps_file_and_releases_lock(): void
    {
        $client = $this->makeSharedClient([new Response(200, [], '{"error":"invalid_client"}')]);
        $this->writeShared('ti-old', time() + 100, 'trading-info');  // 만료 5분 안 — 발급 시도

        $this->assertSame([], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame('POST /oauth2/token ', $this->sent()[0]);
        $this->assertCount(1, $this->sent());  // 토큰이 없으면 GET 을 보내지 않는다
        $this->assertSame('ti-old', $this->readShared()['access_token']);
        $this->assertFileDoesNotExist($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.lock');
        $this->assertSame([], glob($this->sharedDir . DIRECTORY_SEPARATOR . '.toss_token.*.tmp'));
    }

    #[Test]
    public function test_shared_stale_lock_is_removed(): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{}')]);
        mkdir($this->sharedDir, 0777, true);
        $lock = $this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.lock';
        file_put_contents($lock, 'dead 1');
        touch($lock, time() - 61);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame('ti-new', $this->readShared()['access_token']);
        $this->assertFileDoesNotExist($lock);
    }

    // ──────────────────────────────────────────────────────────────────
    // 잠금 소유권 · 무한루프 · 재발급 상한 (2026-10-04 점검)
    //   companion-app 와 같은 파일을 공유하므로 규약이 어긋나면 서로의 토큰을 끊는다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * 내 잠금이 «낡음»으로 회수돼 companion-app 가 자기 잠금을 새로 만든 뒤라면,
     * 내 해제가 그 파일을 지워서는 안 된다 — 지우면 양쪽이 동시에 발급해 토큰이 서로 끊긴다.
     */
    #[Test]
    public function test_shared_lock_taken_over_by_other_app_is_not_deleted(): void
    {
        $client = $this->makeSharedClient([
            // 발급 왕복 사이에 잠금 주인이 바뀐다
            function () {
                file_put_contents($this->lockPath(), 'companion-app 4242');

                return $this->tokenResponse('ti-new');
            },
            new Response(200, [], '{}'),
        ]);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertFileExists($this->lockPath());
        $this->assertSame('companion-app 4242', file_get_contents($this->lockPath()));
    }

    /**
     * 지울 수 없는 낡은 잠금(폴더로 만들어 @unlink 가 실패하게 한다)에서 무한루프 하지 않는다.
     * 회귀하면 이 테스트는 영원히 끝나지 않는다 — 그게 고치기 전 코드의 증상이었다.
     */
    #[Test]
    public function test_shared_undeletable_stale_lock_gives_up_instead_of_looping(): void
    {
        $client = $this->makeSharedClient([], AlwaysStaleLockTossApiClient::class);
        mkdir($this->sharedDir, 0700, true);
        mkdir($this->lockPath());

        $this->assertSame([], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame([], $this->sent());  // 토큰이 없으니 발급도 요청도 없다
        $this->assertDirectoryExists($this->lockPath());

        rmdir($this->lockPath());  // tearDown 의 rmdir 가 성공하게
    }

    /** 잠금 대기 초과 → 발급·요청 없이 빈 배열, 남의 잠금은 건드리지 않는다. */
    #[Test]
    public function test_shared_lock_wait_timeout_returns_empty_and_keeps_lock(): void
    {
        $client = $this->makeSharedClient([], NoWaitTossApiClient::class);
        mkdir($this->sharedDir, 0700, true);
        file_put_contents($this->lockPath(), 'companion-app 4242');  // 방금 잡힌 남의 잠금 — 낡지 않았다

        $this->assertSame([], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame([], $this->sent());
        $this->assertSame('companion-app 4242', file_get_contents($this->lockPath()));
    }

    /** 5분 안에 이미 발급을 시도했으면 POST 를 보내지 않는다 — 403·키오류 때 다른 로컬 앱를 굶기지 않게. */
    #[Test]
    public function test_shared_reissue_within_5min_is_skipped(): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new')]);
        $this->writeIssue(['issued_at' => microtime(true) - 10, 'error' => 'toss_ip_denied']);

        $this->assertSame([], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame([], $this->sent());
        $this->assertFileDoesNotExist($this->lockPath());
        // 상한 창을 되돌리지 않는다(기록을 덮으면 5분이 계속 뒤로 밀린다)
        $this->assertSame('toss_ip_denied', $this->readIssue()['error']);
    }

    /** 실패한 발급도 상한에 센다 + 코드 어휘는 companion-app 와 같다(403 = toss_ip_denied). */
    #[Test]
    public function test_shared_failed_issue_is_recorded_with_error_code(): void
    {
        $client = $this->makeSharedClient([$this->makeClientException(403, '{"error":"access_denied"}')]);

        $this->assertSame([], $client->get('/api/v1/prices', ['symbols' => 'MU']));
        $this->assertSame(['POST /oauth2/token '], $this->sent());
        $this->assertSame('toss_ip_denied', $this->readIssue()['error']);
        $this->assertEqualsWithDelta(microtime(true), $this->readIssue()['issued_at'], 5);
        $this->assertFileDoesNotExist($this->lockPath());
    }

    /** 상한이 지났으면 정상 발급하고 기록을 갱신한다(성공이면 error 없음). */
    #[Test]
    public function test_shared_reissue_allowed_after_cap_window(): void
    {
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{}')]);
        $this->writeIssue(['issued_at' => microtime(true) - 400, 'error' => 'toss_auth_failed']);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame('ti-new', $this->readShared()['access_token']);
        $this->assertEqualsWithDelta(microtime(true), $this->readIssue()['issued_at'], 5);
        $this->assertArrayNotHasKey('error', $this->readIssue());
    }

    /**
     * expires_in 이 0·음수·숫자 아님이면 만료 시각이 과거가 되어 매 요청 재발급 → 다른 로컬 앱 토큰이 끊긴다.
     */
    #[Test]
    #[DataProvider('badExpiresIn')]
    public function test_shared_expires_in_has_minimum_lifetime(mixed $expiresIn, int $expectedLife): void
    {
        $client = $this->makeSharedClient([
            new Response(200, [], (string) json_encode(['access_token' => 'ti-new', 'expires_in' => $expiresIn])),
            new Response(200, [], '{}'),
        ]);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertEqualsWithDelta(time() + $expectedLife, $this->readShared()['expires_at'], 5);
    }

    /** @return array<string, array{mixed, int}> */
    public static function badExpiresIn(): array
    {
        return [
            '0초' => [0, 300],
            '음수' => [-100, 300],
            '숫자 아님' => ['내일', 3600],
            '없음' => [null, 3600],
        ];
    }

    /** 토큰 폴더·파일은 소유자만 — POSIX 호스트(배포·공개 코드)에서 토큰이 새지 않게. */
    #[Test]
    public function test_shared_dir_and_token_file_are_owner_only(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('윈도우는 POSIX 권한 비트를 쓰지 않는다 — 검증 대상은 POSIX 호스트');
        }
        $client = $this->makeSharedClient([$this->tokenResponse('ti-new'), new Response(200, [], '{}')]);

        $client->get('/api/v1/prices', ['symbols' => 'MU']);

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->sharedDir)), -4));
        $this->assertSame('0600', substr(
            sprintf('%o', fileperms($this->sharedDir . DIRECTORY_SEPARATOR . 'toss_token.json')),
            -4
        ));
    }

    /**
     * 토스 경로가 아니면 요청하지 않는다 — Guzzle 은 절대 URL·'//host' 를 주면 base_uri 를 무시하고
     * Bearer 토큰을 그 호스트로 보낸다.
     */
    #[Test]
    #[DataProvider('nonTossPaths')]
    public function test_get_rejects_non_toss_path(string $path): void
    {
        $client = $this->makeSharedClient([]);
        $this->writeShared('other-token', time() + 3600);

        $this->assertSame([], $client->get($path));
        $this->assertSame([], $this->sent());
    }

    /** @return array<string, array{string}> */
    public static function nonTossPaths(): array
    {
        return [
            '절대 URL' => ['https://evil.example/steal'],
            '프로토콜 상대 URL' => ['//evil.example/steal'],
            '상대 경로' => ['api/v1/prices'],
            '빈 문자열' => [''],
        ];
    }
}

/**
 * 잠금 대기만 0 으로 줄인 테스트용 — 실제 10초를 기다리지 않게.
 *
 * 🔴 10초·30초 값 자체는 companion-app 와 합의된 공유 규약이라 운영 코드에서는 그대로 둔다.
 */
class NoWaitTossApiClient extends TossApiClient
{
    protected const SHARED_LOCK_WAIT = 0;
}

/** 위에 더해 «무엇이든 낡은 잠금» — 회수 분기(지울 수 없는 잠금)를 태운다. */
class AlwaysStaleLockTossApiClient extends NoWaitTossApiClient
{
    protected const SHARED_LOCK_STALE = -1;
}
