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
        '/api/v1/prices'        => 100_000,  // MARKET_DATA 10TPS
        '/api/v1/candles'       => 200_000,  // MARKET_DATA_CHART 5TPS
        '/api/v1/exchange-rate' => 500_000,  // 기타
        '/api/v1/stocks'        => 500_000,  // 기타
        '/api/v1/orderbook'     => 500_000,  // 기타
        '/api/v1/trades'        => 500_000,  // 기타
        '/api/v1/price-limits'  => 500_000,  // 기타
    ];

    /** 기본 rate-limit 간격 (마이크로초) — 기타 엔드포인트 */
    private const RATE_LIMIT_DEFAULT_US = 500_000;

    /** 직전 호출 시각 (경로 prefix → float microtime) */
    private array $lastCalledAt = [];

    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'base_uri' => rtrim((string) config('services.toss.api_url'), '/'),
            'timeout'  => 10,
            'headers'  => ['Accept' => 'application/json'],
        ]);
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
     * @param  string  $path   예: '/api/v1/candles'
     * @param  array<string,mixed>  $query  URL 쿼리 파라미터
     * @param  bool    $isRetry  내부 재시도 플래그 — 무한루프 방지용, 외부 호출 시 false
     * @return array<mixed>
     */
    public function get(string $path, array $query = [], bool $isRetry = false): array
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            Log::warning('[TossApiClient] 토큰 없음 — 요청 건너뜀', ['path' => $path]);
            return [];
        }

        $this->applyRateLimit($path);

        try {
            $response = $this->httpClient->get($path, [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query'   => $query,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            return is_array($data) ? $data : [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx — 요청 문제 (파라미터 오류·권한 등)
            $resp4xx   = $e->getResponse();
            $status    = $resp4xx ? $resp4xx->getStatusCode() : null;

            // 401 invalid-token: 토스는 client당 활성 토큰이 1개이므로
            // 다른 곳에서 재발급 시 기존 토큰이 무효화될 수 있다.
            // 캐시를 비우고 새 토큰을 발급받아 1회에 한해 재시도한다.
            if ($status === 401 && !$isRetry) {
                Log::warning('[TossApiClient] 401 invalid-token — 토큰 재발급 후 1회 재시도', [
                    'path' => $path,
                ]);
                Cache::forget(self::TOKEN_CACHE_KEY);
                $this->getAccessToken(true);
                return $this->get($path, $query, true);
            }

            Log::error('[TossApiClient] 4xx 오류', [
                'path'   => $path,
                'status' => $status,
                'body'   => $resp4xx ? substr((string) $resp4xx->getBody(), 0, 300) : null,
            ]);
            return [];
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // 5xx — 서버 오류
            $resp5xx = $e->getResponse();
            Log::error('[TossApiClient] 5xx 오류', [
                'path'   => $path,
                'status' => $resp5xx ? $resp5xx->getStatusCode() : null,
            ]);
            return [];
        } catch (\Throwable $e) {
            Log::error('[TossApiClient] 요청 예외', [
                'path'    => $path,
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
        if ($forceRefresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        // 동시 다발 토큰 발급 방지 — 락 획득
        $lock     = Cache::lock(self::TOKEN_LOCK_KEY, 15);
        $attempts = 0;

        try {
            while (!$lock->get() && $attempts < 10) {
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
     */
    private function issueToken(): ?string
    {
        $clientId     = (string) config('services.toss.client_id');
        $clientSecret = (string) config('services.toss.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error('[TossApiClient] TOSS_CLIENT_ID / TOSS_CLIENT_SECRET 미설정');
            return null;
        }

        try {
            $response = $this->httpClient->post('/oauth2/token', [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                // 오류 본문을 로그할 때 시크릿은 절대 포함하지 않는다
                Log::error('[TossApiClient] 토큰 발급 실패 — access_token 없음', [
                    'error' => $data['error'] ?? 'unknown',
                ]);
                return null;
            }

            $token = $data['access_token'];
            Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL_SECONDS);

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
            return null;
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

        $now  = microtime(true);
        $last = $this->lastCalledAt[$path] ?? 0.0;
        $elapsedUs = (int) (($now - $last) * 1_000_000);

        if ($elapsedUs < $intervalUs) {
            usleep($intervalUs - $elapsedUs);
        }

        $this->lastCalledAt[$path] = microtime(true);
    }
}
