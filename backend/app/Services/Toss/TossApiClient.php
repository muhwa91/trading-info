<?php

declare(strict_types=1);

namespace App\Services\Toss;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스증권 Open API 게이트웨이.
 *
 * 책임:
 *   - OAuth2 client_credentials 토큰 발급 · 23h 캐싱 (토큰 만료 86399s 대비 82800s 마진)
 *   - Bearer 헤더 공통 GET 래퍼
 *   - 엔드포인트 그룹별 rate-limit 가드 (최소 호출 간격 usleep)
 *
 * 사용:
 *   $client = app(TossApiClient::class);
 *   $data   = $client->get('/api/v1/candles', ['symbol' => '005930', 'interval' => '1d']);
 *
 * 설정:
 *   config('services.toss.api_url')  ← TOSS_API_URL
 *   config('services.toss.client_id')     ← TOSS_CLIENT_ID
 *   config('services.toss.client_secret') ← TOSS_CLIENT_SECRET
 *
 * 보안:
 *   토큰·시크릿은 로그에 평문 출력하지 않는다 (마스킹 처리).
 *   비밀값은 .env / config 에서만 읽는다.
 *
 * 토큰 공유 (2026-10-04, 관리자 결정 — 같은 PC 의 다른 로컬 앱과 같은 규약):
 *   토스는 계정당 키 1개·활성 토큰 1개라, 한쪽이 새로 발급하면 다른 쪽 토큰이 401 이 된다.
 *   Windows 로컬(%LOCALAPPDATA% 있음)에서는 `%LOCALAPPDATA%\chiikawa\toss_token.json` 을 정본으로 쓴다.
 *     ① 파일 토큰이 만료 5분 전보다 이르면 그대로 쓴다.
 *     ② 아니면 `toss_token.lock` 배타 생성(fopen 'x', 60초 넘은 잠금은 낡은 것으로 지움, 최대 10초 대기)
 *        → 다시 읽기 → 그래도 없으면 발급·원자 저장(임시파일 + rename) → 잠금 해제.
 *        잠금 해제는 «내가 쓴 내용일 때만» 지운다 — 내 잠금이 이미 낡음으로 회수됐다면 그 파일은 남의 것이다.
 *     ③ 401: 파일에 «내가 쓴 것과 다른» 토큰이 있으면 그걸로 1회 재시도(발급 안 함), 같으면 ②.
 *     ④ 재발급 상한 5분 1회 — `toss_issue.json` 에 시도 시각을 남긴다(실패한 발급도 센다).
 *        403(IP 미등록)·키 오류일 때 요청마다 잠금을 잡고 발급을 때려 다른 로컬 앱를 굶기는 것을 막는다.
 *        companion-app 는 같은 이름·같은 스키마(`issued_at`·`error`)를 자기 `data/` 에 둔다(앱별 카운터).
 *   %LOCALAPPDATA% 가 없는 곳(배포 서버 등)과 테스트(runningUnitTests)는 종전 Laravel Cache 그대로.
 *
 * rate-limit 기준 (토스 Open API 공식):
 *   MARKET_DATA       : 10 TPS
 *   MARKET_DATA_CHART : 5 TPS
 *   기타              : 2 TPS (보수적 기본값)
 *
 * KIS 관례 참고:
 *   FxService::getKisToken, KisOverseasQuoteProvider::getAccessToken — 동형 패턴.
 */
class TossApiClient
{
    /** 토큰 캐시 키 */
    private const TOKEN_CACHE_KEY = 'toss_access_token';

    /** 토큰 캐시 TTL (초) — 만료 86399s 대비 23h 마진 */
    private const TOKEN_TTL_SECONDS = 82800;

    /** 토큰 발급 락 키 */
    private const TOKEN_LOCK_KEY = 'toss_token_lock';

    /**
     * 엔드포인트 경로 prefix → 최소 호출 간격 (마이크로초).
     *
     * MARKET_DATA(10TPS) → 100ms, MARKET_DATA_CHART(5TPS) → 200ms, 기타 → 500ms.
     * KIS 의 usleep(100_000) 관례와 동일 수준 적용.
     *
     * 실제 토스 Open API 경로 기준 (실측 검증):
     *   /api/v1/prices       — 현재가·호가 (10TPS)
     *   /api/v1/candles      — 차트 봉 (5TPS)
     *   /api/v1/exchange-rate, /api/v1/stocks, /api/v1/orderbook,
     *   /api/v1/trades, /api/v1/price-limits — 기타 (기본값 500ms)
     */
    private const RATE_LIMIT_US = [
        '/api/v1/prices' => 100_000,  // MARKET_DATA 10TPS
        '/api/v1/candles' => 200_000,  // MARKET_DATA_CHART 5TPS
        '/api/v1/exchange-rate' => 500_000,  // 기타
        '/api/v1/stocks' => 500_000,  // 기타
        '/api/v1/orderbook' => 500_000,  // 기타
        '/api/v1/trades' => 500_000,  // 기타
        '/api/v1/price-limits' => 500_000,  // 기타
    ];

    /** 기본 rate-limit 간격 (마이크로초) — 기타 엔드포인트 */
    private const RATE_LIMIT_DEFAULT_US = 500_000;

    /** 직전 호출 시각 (경로 prefix → float microtime) */
    private array $lastCalledAt = [];

    private Client $httpClient;

    /** 공유 토큰 파일 이름·잠금 이름 (companion-app 와 같은 이름) */
    private const SHARED_TOKEN_FILE = 'toss_token.json';

    private const SHARED_LOCK_FILE = 'toss_token.lock';

    /** 재발급 상한 기록 — companion-app `core/toss.py` 와 같은 이름·스키마 */
    private const SHARED_ISSUE_FILE = 'toss_issue.json';

    /** 만료 이만큼(초) 전부터는 쓰지 않고 새로 받는다 */
    private const SHARED_EXPIRY_MARGIN = 300;

    /** 재발급 상한 — 이 시간(초) 안에 이미 발급을 시도했으면 다시 하지 않는다 (companion-app REISSUE_MIN_S) */
    private const SHARED_REISSUE_MIN = 300;

    /**
     * 이보다 오래된(초) 잠금은 죽은 프로세스가 남긴 것으로 본다.
     *
     * 🔴 값은 companion-app 와 합의된 공유 규약이라 바꾸지 않는다 — 테스트가 덮어쓸 수 있게 protected 일 뿐이다.
     */
    protected const SHARED_LOCK_STALE = 60;

    /** 잠금을 기다리는 최대 시간(초) — 값은 공유 규약(위와 같다) */
    protected const SHARED_LOCK_WAIT = 10;

    /** 공유 파일 폴더 — null 이면 종전 Laravel Cache 동작 */
    private ?string $sharedDir;

    /**
     * @param  string|null  $sharedTokenDir  공유 토큰 폴더(테스트가 임시 폴더를 주입). null 이면
     *                                       %LOCALAPPDATA%\chiikawa — 단 테스트 실행 중이거나 LOCALAPPDATA 가
     *                                       없으면(배포 서버) 공유하지 않는다.
     */
    public function __construct(?string $sharedTokenDir = null)
    {
        $this->httpClient = new Client([
            'base_uri' => rtrim((string) config('services.toss.api_url'), '/'),
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $this->sharedDir = $sharedTokenDir ?? self::defaultSharedDir();
    }

    private static function defaultSharedDir(): ?string
    {
        // 테스트는 실제 공유 파일(=실제 토큰)을 절대 건드리지 않는다 — 필요한 테스트는 폴더를 주입한다
        if (app()->runningUnitTests()) {
            return null;
        }
        $base = getenv('LOCALAPPDATA');

        return is_string($base) && $base !== '' ? $base . DIRECTORY_SEPARATOR . 'chiikawa' : null;
    }

    // ──────────────────────────────────────────────────────────────────
    // 공개 인터페이스
    // ──────────────────────────────────────────────────────────────────

    /**
     * 토스 API GET 요청.
     *
     * 성공 시 JSON 배열 반환. 4xx/5xx·네트워크 예외 시 빈 배열 반환 + 로그.
     * 401(invalid-token) 발생 시 토큰 캐시 삭제 후 1회 재발급·재시도.
     *
     * @param  string  $path  예: '/api/v1/candles'
     * @param  array<string,mixed>  $query  URL 쿼리 파라미터
     * @param  bool  $isRetry  내부 재시도 플래그 — 무한루프 방지용, 외부 호출 시 false
     * @return array<mixed>
     */
    public function get(string $path, array $query = [], bool $isRetry = false): array
    {
        // 🔴 Bearer 토큰이 base_uri 밖으로 나가는 것을 막는다 — Guzzle 은 절대 URL·'//host/x' 를 주면
        //    base_uri 를 무시하고 그 호스트로 보낸다. 토스 경로만 허용한다.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            Log::error('[TossApiClient] 토스 경로가 아니다 — 요청 거부', ['path' => $path]);

            return [];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            Log::warning('[TossApiClient] 토큰 없음 — 요청 건너뜀', ['path' => $path]);

            return [];
        }

        $this->applyRateLimit($path);

        try {
            $response = $this->httpClient->get($path, [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query' => $query,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            return is_array($data) ? $data : [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx — 요청 문제 (파라미터 오류·권한 등)
            $resp4xx = $e->getResponse();
            $status = $resp4xx ? $resp4xx->getStatusCode() : null;

            // 401 invalid-token: 토스는 client당 활성 토큰이 1개이므로
            // 다른 곳에서 재발급 시 기존 토큰이 무효화될 수 있다.
            // 캐시를 비우고 새 토큰을 발급받아 1회에 한해 재시도한다.
            if ($status === 401 && ! $isRetry) {
                Log::warning('[TossApiClient] 401 invalid-token — 토큰 재발급 후 1회 재시도', [
                    'path' => $path,
                ]);
                if ($this->sharedDir === null) {
                    Cache::forget(self::TOKEN_CACHE_KEY);
                    $this->getAccessToken(true);
                } else {
                    $this->refreshSharedAfter401($token);
                }

                return $this->get($path, $query, true);
            }

            Log::error('[TossApiClient] 4xx 오류', [
                'path' => $path,
                'status' => $status,
                'body' => $resp4xx ? substr((string) $resp4xx->getBody(), 0, 300) : null,
            ]);

            return [];
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // 5xx — 서버 오류
            $resp5xx = $e->getResponse();
            Log::error('[TossApiClient] 5xx 오류', [
                'path' => $path,
                'status' => $resp5xx ? $resp5xx->getStatusCode() : null,
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::error('[TossApiClient] 요청 예외', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 토큰 발급 · 캐싱
    // ──────────────────────────────────────────────────────────────────

    /**
     * 토스 OAuth2 액세스 토큰 반환 (캐시 우선).
     *
     * KisOverseasQuoteProvider::getAccessToken 과 동일 패턴:
     *   - 캐시 hit → 즉시 반환
     *   - 락 획득 → POST /oauth2/token → 캐시 저장
     */
    public function getAccessToken(bool $forceRefresh = false): ?string
    {
        if ($this->sharedDir !== null) {
            $shared = $forceRefresh ? null : $this->readSharedToken();

            return $shared ?? $this->issueShared($this->readSharedToken());
        }

        if ($forceRefresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        // 동시 다발 토큰 발급 방지 — 락 획득
        $lock = Cache::lock(self::TOKEN_LOCK_KEY, 15);
        $attempts = 0;

        try {
            while (! $lock->get() && $attempts < 10) {
                usleep(500_000);
                $cached = Cache::get(self::TOKEN_CACHE_KEY);
                if ($cached !== null) {
                    return $cached;
                }
                $attempts++;
            }

            // 락 획득 후 다시 한 번 확인 (race condition 방지)
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if ($cached !== null) {
                return $cached;
            }

            return $this->issueToken();
        } finally {
            $lock->release();
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * POST /oauth2/token — client_credentials 방식으로 토큰 발급.
     *
     * 실측 검증된 토스 OAuth2 엔드포인트:
     *   Content-Type: application/x-www-form-urlencoded
     *   body: grant_type=client_credentials&client_id=...&client_secret=...
     *   응답: { access_token, token_type: "Bearer", expires_in: 86399 }
     *
     * 공유 모드에서는 «5분에 1회» 상한을 지킨다(실패한 발급도 센다) — companion-app 와 같은 규약.
     */
    private function issueToken(): ?string
    {
        $clientId = (string) config('services.toss.client_id');
        $clientSecret = (string) config('services.toss.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error('[TossApiClient] TOSS_CLIENT_ID / TOSS_CLIENT_SECRET 미설정');

            return null;
        }

        if ($this->reissueTooSoon()) {
            return null;
        }

        // 시도 자체를 기록한다 — 실패한 발급도 상한에 넣어야 403·키오류 때 폭주하지 않는다
        $attempt = microtime(true);

        try {
            $response = $this->httpClient->post('/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (! isset($data['access_token'])) {
                // 오류 본문을 로그할 때 시크릿은 절대 포함하지 않는다
                Log::error('[TossApiClient] 토큰 발급 실패 — access_token 없음', [
                    'error' => $data['error'] ?? 'unknown',
                ]);
                $this->writeIssueRecord($attempt, 'toss_auth_failed');

                return null;
            }

            $token = $data['access_token'];
            if ($this->sharedDir !== null) {
                $this->writeSharedToken($token, $this->tokenLifetime($data));
                $this->writeIssueRecord($attempt, null);
            } else {
                Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL_SECONDS);
            }

            // 발급 성공 — 값 자체는 출력하지 않고 만료 시간만 로그
            Log::info('[TossApiClient] 토큰 발급 OK', [
                'expires_in' => $data['expires_in'] ?? 'unknown',
                'token_type' => $data['token_type'] ?? 'Bearer',
                'cached_ttl' => self::TOKEN_TTL_SECONDS,
            ]);

            return $token;
        } catch (\Throwable $e) {
            // 예외 메시지에도 시크릿이 섞이지 않도록 단순 메시지만
            Log::error('[TossApiClient] 토큰 발급 예외: ' . $e->getMessage());
            $status = $e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : null;
            // 코드 어휘는 companion-app 와 같다 (toss_ip_denied = 403 IP 미등록)
            $this->writeIssueRecord($attempt, $status === 403 ? 'toss_ip_denied' : 'toss_auth_failed');

            return null;
        }
    }

    /**
     * 만료까지 남은 초 — 토스가 0·음수·숫자 아닌 값을 주면 하한 300초로 본다.
     *
     * 하한이 없으면 `expires_at = now` 가 되어 요청마다 재발급 → 같은 키를 쓰는 다른 로컬 앱 토큰이 끊긴다.
     * (발급 폭주 자체는 위 재발급 상한이 막는다. 여기서는 과거 시각이 파일에 적히는 것만 막는다.)
     *
     * @param  array<mixed>  $data  토큰 응답 본문
     */
    private function tokenLifetime(array $data): int
    {
        $raw = $data['expires_in'] ?? null;

        return max(300, is_numeric($raw) ? (int) $raw : 3600);
    }

    /**
     * 공유 모드에서 «5분 안에 이미 발급을 시도했나». 공유하지 않으면 항상 false.
     */
    private function reissueTooSoon(): bool
    {
        if ($this->sharedDir === null) {
            return false;
        }
        $raw = @file_get_contents($this->sharedPath(self::SHARED_ISSUE_FILE));
        $data = $raw === false ? null : json_decode($raw, true);
        $issuedAt = is_array($data) ? ($data['issued_at'] ?? null) : null;
        if (! is_numeric($issuedAt) || microtime(true) - (float) $issuedAt >= self::SHARED_REISSUE_MIN) {
            return false;
        }
        Log::warning('[TossApiClient] 토큰 재발급 상한(5분 1회)에 걸림 — 이번 회차는 토큰 없음', [
            'last_error' => is_array($data) ? ($data['error'] ?? null) : null,
        ]);

        return true;
    }

    /** 발급 시도 기록 — 실패면 코드까지(companion-app `toss_issue.json` 과 같은 스키마). */
    private function writeIssueRecord(float $attempt, ?string $error): void
    {
        if ($this->sharedDir === null) {
            return;
        }
        $this->ensureSharedDir();
        $record = ['issued_at' => $attempt];
        if ($error !== null) {
            $record['error'] = $error;
        }
        if (@file_put_contents($this->sharedPath(self::SHARED_ISSUE_FILE), (string) json_encode($record)) === false) {
            Log::warning('[TossApiClient] 발급 기록 저장 실패 — 재발급 상한이 느슨해진다');
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 공유 토큰 파일 (companion-app 와 같은 규약 — 클래스 주석 참고)
    // ──────────────────────────────────────────────────────────────────

    /**
     * 401 뒤: 파일에 내가 쓴 것과 다른 토큰이 있으면 아무것도 안 한다(그걸로 재시도),
     * 같으면 잠금 안에서 새로 받는다.
     */
    private function refreshSharedAfter401(string $staleToken): void
    {
        $current = $this->readSharedToken();
        if ($current !== null && $current !== $staleToken) {
            Log::info('[TossApiClient] 공유 파일에 다른 앱이 받은 새 토큰 — 발급 없이 재시도');

            return;
        }
        $this->issueShared($staleToken);
    }

    /**
     * 잠금 → 다시 읽기(그사이 다른 쪽이 받았을 수 있다) → 그래도 없거나 $staleToken 이면 발급·저장.
     */
    private function issueShared(?string $staleToken): ?string
    {
        if (! $this->acquireSharedLock()) {
            Log::warning('[TossApiClient] 공유 토큰 잠금 대기 초과 — 이번 요청은 토큰 없음');

            return null;
        }
        try {
            $current = $this->readSharedToken();
            if ($current !== null && $current !== $staleToken) {
                return $current;
            }

            return $this->issueToken();
        } finally {
            $this->releaseSharedLock();
        }
    }

    /**
     * 🔴 내가 쓴 내용일 때만 지운다 — 내 잠금이 이미 «낡음»(30초)으로 companion-app 에 회수됐다면
     *    그 파일은 상대의 것이다. 남의 잠금을 끊으면 양쪽이 서로의 토큰을 끊는다(2026-10-04 06:12 사고).
     *    읽기 실패·불일치면 조용히 넘긴다(해제에서 예외를 밖으로 내지 않는다).
     */
    private function releaseSharedLock(): void
    {
        $lock = $this->sharedPath(self::SHARED_LOCK_FILE);
        $owner = @file_get_contents($lock);
        if ($owner === $this->lockOwnerTag()) {
            @unlink($lock);
        }
    }

    /** 잠금 파일에 쓰는 소유자 표시 — companion-app 의 `f"{ISSUER} {os.getpid()}"` 와 같은 형식. */
    private function lockOwnerTag(): string
    {
        return 'trading-info ' . getmypid();
    }

    private function sharedPath(string $name): string
    {
        return $this->sharedDir . DIRECTORY_SEPARATOR . $name;
    }

    /** 공유 폴더 보장 — 토큰이 들어가므로 소유자만(0700). POSIX 호스트에서 world-writable 금지. */
    private function ensureSharedDir(): void
    {
        if (! is_dir($this->sharedDir)) {
            @mkdir($this->sharedDir, 0700, true);
        }
    }

    /** 공유 파일의 쓸 수 있는 토큰(만료 5분 전까지). 없거나 깨졌으면 null. */
    private function readSharedToken(): ?string
    {
        $raw = @file_get_contents($this->sharedPath(self::SHARED_TOKEN_FILE));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresAt = is_array($data) ? ($data['expires_at'] ?? null) : null;
        if (! is_string($token) || $token === '' || ! is_numeric($expiresAt)) {
            return null;
        }

        return (float) $expiresAt - self::SHARED_EXPIRY_MARGIN > microtime(true) ? $token : null;
    }

    /** 임시파일에 쓰고 rename 으로 원자 교체 — 읽는 쪽이 반쯤 쓴 파일을 보지 않게. */
    private function writeSharedToken(string $token, int $expiresIn): void
    {
        $this->ensureSharedDir();
        $now = time();
        $json = json_encode([
            'access_token' => $token,
            'expires_at' => $now + $expiresIn,
            'issued_at' => $now,
            'issuer' => 'trading-info',
        ]);
        $tmp = $this->sharedPath('.toss_token.' . bin2hex(random_bytes(6)) . '.tmp');
        // 🔴 rename 전에 0600 — POSIX 기본 umask 면 토큰 파일이 world-readable(0644) 로 남는다.
        //    chmod 실패(윈도우 등)는 저장 자체를 막지 않는다 — 토큰을 잃는 쪽이 더 나쁘다.
        $written = $json !== false && @file_put_contents($tmp, $json) !== false;
        if ($written) {
            @chmod($tmp, 0600);
        }
        if (! $written || ! @rename($tmp, $this->sharedPath(self::SHARED_TOKEN_FILE))) {
            @unlink($tmp);
            Log::warning('[TossApiClient] 공유 토큰 파일 저장 실패');  // 값은 남기지 않는다
        }
    }

    /** `toss_token.lock` 배타 생성. 60초 넘은 잠금은 지우고 다시, 최대 10초 기다린다. */
    private function acquireSharedLock(): bool
    {
        $this->ensureSharedDir();
        $lock = $this->sharedPath(self::SHARED_LOCK_FILE);
        $deadline = microtime(true) + static::SHARED_LOCK_WAIT;
        while (true) {
            $handle = @fopen($lock, 'x');
            if ($handle !== false) {
                fwrite($handle, $this->lockOwnerTag());
                fclose($handle);

                return true;
            }
            clearstatcache(true, $lock);
            $mtime = @filemtime($lock);
            if ($mtime !== false && time() - $mtime > static::SHARED_LOCK_STALE) {
                Log::warning('[TossApiClient] 낡은 공유 토큰 잠금 제거(30초 초과)');
                if (@unlink($lock)) {
                    continue;  // 지웠다 — 바로 다시 잡아 본다
                }
                // 못 지웠다(윈도우 점유·권한·남이 먼저 지움) → 아래 deadline·usleep 을 반드시 지난다.
                // 🔴 여기서 continue 하면 지울 수 없는 잠금에 무한 회전한다(companion-app 쪽과 같은 규약).
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(200_000);
        }
    }

    /**
     * 엔드포인트별 rate-limit 가드 — 최소 호출 간격을 usleep 으로 보장.
     *
     * KIS 의 usleep(100_000) 관례를 기반으로 토스 TPS 에 맞게 조정.
     */
    private function applyRateLimit(string $path): void
    {
        // 경로 prefix 로 간격 결정
        $intervalUs = self::RATE_LIMIT_DEFAULT_US;
        foreach (self::RATE_LIMIT_US as $prefix => $us) {
            if (strncmp($path, $prefix, strlen($prefix)) === 0) {
                $intervalUs = $us;
                break;
            }
        }

        $now = microtime(true);
        $last = $this->lastCalledAt[$path] ?? 0.0;
        $elapsedUs = (int) (($now - $last) * 1_000_000);

        if ($elapsedUs < $intervalUs) {
            usleep($intervalUs - $elapsedUs);
        }

        $this->lastCalledAt[$path] = microtime(true);
    }
}
