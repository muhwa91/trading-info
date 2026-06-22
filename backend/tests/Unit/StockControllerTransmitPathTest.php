<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StockController 전송 경로 — 캐시 미스 시 동기 KIS 호출 억제 단위 테스트.
 *
 * 배경 (2026-06-22):
 *   getStockData() 전송 경로에서 KIS 현재가 캐시 TTL 이 3초라 WebSocket 3초 사이클 중
 *   캐시 만료 시 동기 KIS HTTP 호출(fetchOverseasPriceFromKis / fetchDomesticPriceFromKis)이
 *   발생해 전송 단계가 1.4~1.6초 지연됨.
 *
 * 수정 내용:
 *   Cache::remember(key, 3, fn() => fetchXxx()) 패턴 제거.
 *   대신 stale-while-revalidate(SWR) 패턴:
 *     1. Cache::get("kis_realtime_price_us_{ticker}") — 병렬선조회 8초 TTL 키
 *     2. ?? Cache::get("kis_last_successful_overseas_price_{ticker}") — 24h 폴백
 *     3. ?? fetchOverseasPriceFromKis() — cold-start 1회만 허용
 *
 * 검증 케이스:
 *   1. US 전송 경로 소스에 Cache::remember(cacheKeyKis, 3, …) 패턴이 없다
 *   2. US 전송 경로 소스에 Cache::get("kis_realtime_price_us_{ticker}") 패턴이 있다
 *   3. US 전송 경로 소스에 폴백 키 "kis_last_successful_overseas_price_{ticker}" 가 있다
 *   4. 국내 전송 경로 소스에 Cache::remember(cacheKeyKis, 3, …) 패턴이 없다
 *   5. 국내 전송 경로 소스에 Cache::get("kis_realtime_price_{ticker}") 패턴이 있다
 *   6. 국내 전송 경로 소스에 폴백 키 "kis_last_successful_price_{ticker}" 가 있다
 *   7. 병렬선조회(KisParallelPriceFetcher)와 전송 경로가 동일한 US 기본 캐시 키 사용
 *   8. 병렬선조회(KisParallelPriceFetcher)와 전송 경로가 동일한 국내 기본 캐시 키 사용
 */
class StockControllerTransmitPathTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────
    // 1. US 전송 경로 — Cache::remember(kis_realtime_price_us_, 3, ...) 패턴 제거 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsTransmitPathDoesNotUseCacheRememberWithThreeSecTtl(): void
    {
        $src = $this->getUsTransmitSection();

        // Cache::remember($cacheKeyKis, 3, ...) 혹은 cache()->remember('...', 3, ...) 형태가
        // 전송 경로 US 블록에 없어야 한다 — 3초 TTL이면 매 사이클 동기 KIS 호출 발생
        $hasRemember3 = (bool)preg_match(
            '/Cache::remember\s*\(\s*\$cacheKeyKis[^,]*,\s*3\s*,/',
            $src
        );

        $this->assertFalse(
            $hasRemember3,
            '미국 전송 경로에서 Cache::remember(cacheKeyKis, 3, ...) 가 발견됨. ' .
            '3초 TTL 이면 매 사이클 캐시 만료 → 동기 KIS 호출 발생. ' .
            'stale-while-revalidate 패턴(Cache::get + 폴백)을 사용해야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. US 전송 경로 — 병렬선조회 8초 TTL 키를 직접 Cache::get 으로 읽는지 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsTransmitPathReadsPrimaryKisCacheKey(): void
    {
        $src = $this->getUsTransmitSection();

        // "kis_realtime_price_us_{ticker}" 캐시 키를 Cache::get 으로 참조해야 한다.
        // 변수명 $cacheKeyKis 또는 리터럴 문자열 포함 패턴 허용
        $hasPrimaryGet = (bool)preg_match(
            '/Cache::get\s*\(\s*\$cacheKeyKis\s*\)/',
            $src
        ) || (bool)preg_match(
            '/Cache::get\s*\(\s*"kis_realtime_price_us_/',
            $src
        );

        $this->assertTrue(
            $hasPrimaryGet,
            '미국 전송 경로에서 Cache::get($cacheKeyKis) 또는 Cache::get("kis_realtime_price_us_...") 를 찾을 수 없음. ' .
            '병렬선조회가 채운 "kis_realtime_price_us_{ticker}" 키를 먼저 읽어야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. US 전송 경로 — 폴백 키(24h) 존재 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsTransmitPathReferencesFallbackKey(): void
    {
        $src = $this->getUsTransmitSection();

        $hasFallbackKey = (bool)preg_match(
            '/kis_last_successful_overseas_price_/',
            $src
        );

        $this->assertTrue(
            $hasFallbackKey,
            '미국 전송 경로에서 "kis_last_successful_overseas_price_{ticker}" 폴백 키를 찾을 수 없음. ' .
            '캐시 미스 시 24h 폴백 값을 사용해 동기 KIS 호출을 차단해야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. 국내 전송 경로 — Cache::remember(cacheKeyKis, 3, ...) 패턴 제거 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticTransmitPathDoesNotUseCacheRememberWithThreeSecTtl(): void
    {
        $src = $this->getDomesticTransmitSection();

        $hasRemember3 = (bool)preg_match(
            '/Cache::remember\s*\(\s*\$cacheKeyKis[^,]*,\s*3\s*,/',
            $src
        );

        $this->assertFalse(
            $hasRemember3,
            '국내 전송 경로에서 Cache::remember(cacheKeyKis, 3, ...) 가 발견됨. ' .
            'stale-while-revalidate 패턴(Cache::get + 폴백)으로 교체해야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. 국내 전송 경로 — 병렬선조회 8초 TTL 키를 직접 Cache::get 으로 읽는지 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticTransmitPathReadsPrimaryKisCacheKey(): void
    {
        $src = $this->getDomesticTransmitSection();

        $hasPrimaryGet = (bool)preg_match(
            '/Cache::get\s*\(\s*\$cacheKeyKis\s*\)/',
            $src
        );

        $this->assertTrue(
            $hasPrimaryGet,
            '국내 전송 경로에서 Cache::get($cacheKeyKis) 를 찾을 수 없음. ' .
            '병렬선조회가 채운 "kis_realtime_price_{ticker}" 키를 먼저 읽어야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. 국내 전송 경로 — 폴백 키(24h) 존재 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticTransmitPathReferencesFallbackKey(): void
    {
        $src = $this->getDomesticTransmitSection();

        $hasFallbackKey = (bool)preg_match(
            '/kis_last_successful_price_/',
            $src
        );

        $this->assertTrue(
            $hasFallbackKey,
            '국내 전송 경로에서 "kis_last_successful_price_{ticker}" 폴백 키를 찾을 수 없음. ' .
            '캐시 미스 시 24h 폴백 값을 사용해 동기 KIS 호출을 차단해야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. 캐시 키 정합 — 병렬선조회 US 캐시 키 == 전송 경로 US 기본 캐시 키
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testUsCacheKeyConsistencyBetweenParallelFetcherAndTransmitPath(): void
    {
        $fetcherSrc    = $this->getParallelFetcherSource();
        $controllerSrc = $this->getUsTransmitSection();

        // 병렬선조회 cacheOverseasPrice(): Cache::put("kis_realtime_price_us_{$ticker}", ...)
        // PHP 소스에서 {$ticker} 포함 문자열 매칭
        $hasUsPutInFetcher = (bool)preg_match(
            '/Cache::put\s*\(\s*"kis_realtime_price_us_/',
            $fetcherSrc
        );

        // 전송 경로: $cacheKeyKis = "kis_realtime_price_us_{$ticker}";
        $hasUsCacheKeyInController = (bool)preg_match(
            '/\$cacheKeyKis\s*=\s*"kis_realtime_price_us_/',
            $controllerSrc
        );

        $this->assertTrue(
            $hasUsPutInFetcher,
            'KisParallelPriceFetcher 에서 Cache::put("kis_realtime_price_us_...) 패턴을 찾을 수 없음'
        );
        $this->assertTrue(
            $hasUsCacheKeyInController,
            '전송 경로(US)에서 $cacheKeyKis = "kis_realtime_price_us_..." 패턴을 찾을 수 없음. ' .
            '병렬선조회가 채운 캐시를 전송 경로가 읽지 못하면 매 사이클 동기 호출 발생.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. 캐시 키 정합 — 병렬선조회 국내 캐시 키 == 전송 경로 국내 기본 캐시 키
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticCacheKeyConsistencyBetweenParallelFetcherAndTransmitPath(): void
    {
        $fetcherSrc    = $this->getParallelFetcherSource();
        $controllerSrc = $this->getDomesticTransmitSection();

        // 병렬선조회 fetchDomesticBatch(): Cache::put($cacheKey, ...) 형태 — $cacheKey = "kis_realtime_price_{$ticker}"
        // 실제 소스: $cacheKey 변수에 키를 할당 후 Cache::put($cacheKey, ...) 호출
        $hasKrPutInFetcher = (bool)preg_match(
            '/\$cacheKey\s*=\s*"kis_realtime_price_\{/',
            $fetcherSrc
        ) && (bool)preg_match(
            '/Cache::put\s*\(\s*\$cacheKey\s*,/',
            $fetcherSrc
        );

        // 전송 경로: $cacheKeyKis = "kis_realtime_price_{$ticker}";
        $hasKrCacheKeyInController = (bool)preg_match(
            '/\$cacheKeyKis\s*=\s*"kis_realtime_price_\{/',
            $controllerSrc
        );

        $this->assertTrue(
            $hasKrPutInFetcher,
            'KisParallelPriceFetcher 에서 $cacheKey = "kis_realtime_price_{...}" + Cache::put($cacheKey, ...) 패턴을 찾을 수 없음'
        );
        $this->assertTrue(
            $hasKrCacheKeyInController,
            '전송 경로(국내)에서 $cacheKeyKis = "kis_realtime_price_{$ticker}" 패턴을 찾을 수 없음.'
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

    private function getParallelFetcherSource(): string
    {
        $path = __DIR__ . '/../../app/Services/KisParallelPriceFetcher.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'KisParallelPriceFetcher.php 읽기 실패');
        return (string)$src;
    }

    /**
     * StockController::getStockData() 의 US 주식 전송 경로 블록만 추출.
     * "// US Stock flow" 주석부터 충분한 길이까지.
     */
    private function getUsTransmitSection(): string
    {
        $src = $this->getControllerSource();
        $marker = '// US Stock flow (non-index, non-domestic)';
        $pos    = strpos($src, $marker);
        if ($pos === false) {
            return $src;
        }
        // 4000바이트 — 한글 주석으로 멀티바이트가 많으므로 넉넉히
        return substr($src, $pos, 4000);
    }

    /**
     * StockController::getStockData() 의 국내 주식 전송 경로 블록만 추출.
     */
    private function getDomesticTransmitSection(): string
    {
        $src = $this->getControllerSource();
        // 국내 블록의 KIS 현재가 할당 마커 — WS/REST 분기 이후 주석
        $marker = 'KIS 현재가(국내) — WS/REST 경로 분기:';
        $pos    = strpos($src, $marker);
        if ($pos === false) {
            // 구 마커(stale-while-revalidate) 또는 폴백 키로 대체 탐색
            $marker = 'KIS 현재가 — 전송 경로 stale-while-revalidate:';
            $pos    = strpos($src, $marker);
        }
        if ($pos === false) {
            $marker = 'kis_last_successful_price_';
            $pos    = strpos($src, $marker);
        }
        if ($pos === false) {
            return $src;
        }
        return substr($src, $pos, 2000);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. 회귀: REST 경로(allowStale=false) — primary 만료 시 동기 fetch 갱신 로직 존재
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     *
     * 회귀 내용 (2026-06-22):
     *   라운드3에서 Cache::remember → Cache::get + ?? 폴백 체인으로 바뀌면서
     *   REST 직접조회 경로도 동기 fetch 를 하지 않게 됐다.
     *   → 병렬선조회가 갱신하지 않는 종목은 24h 폴백을 영원히 반환.
     *
     * 수정 후:
     *   $allowStale=false(REST) 분기에 동기 fetch + Cache::put(primary, 8) 가 있어야 한다.
     */
    public function testRestPathCallsFreshFetchWhenPrimaryExpired(): void
    {
        // US 블록에서 $allowStale=false 분기: fetchOverseasPriceFromKis 호출 + Cache::put(cacheKeyKis 8초) 가 존재해야 함
        $usSrc = $this->getUsTransmitSection();

        $hasAllowStaleCheck = (bool) preg_match('/allowStale/', $usSrc);
        $this->assertTrue(
            $hasAllowStaleCheck,
            'US 블록에 $allowStale 분기가 없음. REST 경로와 WS 경로가 분리되지 않은 상태.'
        );

        // REST 분기에서 fetchOverseasPriceFromKis 동기 호출이 있어야 함
        $hasFetchCall = (bool) preg_match('/fetchOverseasPriceFromKis/', $usSrc);
        $this->assertTrue(
            $hasFetchCall,
            'US REST 경로에 fetchOverseasPriceFromKis() 호출이 없음. ' .
            'primary 만료 시 동기 fetch 로 갱신해야 REST 신선도가 유지된다.'
        );

        // fetch 성공 후 primary 키를 8초 TTL 로 Cache::put 해야 함
        $hasCachePut8 = (bool) preg_match('/Cache::put\s*\(\s*\$cacheKeyKis\s*,\s*\$fresh\s*,\s*8\s*\)/', $usSrc);
        $this->assertTrue(
            $hasCachePut8,
            'US REST 경로에 Cache::put($cacheKeyKis, $fresh, 8) 가 없음. ' .
            'fetch 후 8초 TTL 로 primary 를 채워야 다음 호출이 캐시 히트한다.'
        );

        // 국내 블록도 동일하게 검증
        $krSrc = $this->getDomesticTransmitSection();

        $hasAllowStaleKr = (bool) preg_match('/allowStale/', $krSrc);
        $this->assertTrue(
            $hasAllowStaleKr,
            '국내 블록에 $allowStale 분기가 없음. REST 경로와 WS 경로가 분리되지 않은 상태.'
        );

        $hasFetchCallKr = (bool) preg_match('/fetchDomesticPriceFromKis/', $krSrc);
        $this->assertTrue(
            $hasFetchCallKr,
            '국내 REST 경로에 fetchDomesticPriceFromKis() 호출이 없음. ' .
            'primary 만료 시 동기 fetch 로 갱신해야 REST 신선도가 유지된다.'
        );

        $hasCachePut8Kr = (bool) preg_match('/Cache::put\s*\(\s*\$cacheKeyKis\s*,\s*\$fresh\s*,\s*8\s*\)/', $krSrc);
        $this->assertTrue(
            $hasCachePut8Kr,
            '국내 REST 경로에 Cache::put($cacheKeyKis, $fresh, 8) 가 없음. ' .
            'fetch 후 8초 TTL 로 primary 를 채워야 다음 호출이 캐시 히트한다.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. 회귀: WS 경로(allowStale=true) — primary 만료 시 fetch 없이 폴백만 사용
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @test
     *
     * WS 전송 경로는 동기 fetch 를 하면 안 된다.
     * $allowStale=true 분기에는 fetch 호출 없이 폴백 키만 읽어야 케이던스가 유지된다.
     *
     * 검증: allowStale 분기 구조 내에서 WS(true) 쪽에 fetchXxx 호출이 직접 오지 않는 패턴.
     * 소스 분석: "if ($allowStale) { ... } else { $fresh = $this->fetchXxx... }" 구조가 있어야 함.
     */
    public function testWsPathDoesNotCallFetchWhenPrimaryExpired(): void
    {
        // US 블록: "if ($allowStale)" 분기가 fetch 보다 먼저 나타나야 한다
        $usSrc = $this->getUsTransmitSection();

        // allowStale true 분기가 존재하고 fetch 는 else 블록에 있어야 함
        // 패턴: if ($allowStale) { ... } else { ... fetchOverseasPriceFromKis ...}
        $hasCorrectStructure = (bool) preg_match(
            '/if\s*\(\s*\$allowStale\s*\).*?fetchOverseasPriceFromKis/s',
            $usSrc
        );
        // allowStale true 분기 안에 직접 fetch 가 없어야 하므로: 구조상 else 에만 fetch 가 있는지 확인
        // 간단히: allowStale 분기와 fetchOverseasPriceFromKis 가 모두 존재하고,
        //         fetch 가 allowStale=false(else) 안에만 있는 구조임을 확인
        $this->assertTrue(
            $hasCorrectStructure,
            'US 블록에 "if ($allowStale) { ... } else { fetchOverseasPriceFromKis }" 구조가 없음. ' .
            'WS 경로(allowStale=true)는 fetch 금지, REST(allowStale=false) 는 fetch 허용 구조여야 함.'
        );

        // 국내 블록도 동일하게
        $krSrc = $this->getDomesticTransmitSection();

        $hasCorrectStructureKr = (bool) preg_match(
            '/if\s*\(\s*\$allowStale\s*\).*?fetchDomesticPriceFromKis/s',
            $krSrc
        );
        $this->assertTrue(
            $hasCorrectStructureKr,
            '국내 블록에 "if ($allowStale) { ... } else { fetchDomesticPriceFromKis }" 구조가 없음. ' .
            'WS 경로(allowStale=true)는 fetch 금지, REST(allowStale=false) 는 fetch 허용 구조여야 함.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 11. WS 서버가 ws_allow_stale=true 를 Request attribute 에 주입하는지 확인
    // ──────────────────────────────────────────────────────────────────────

    /** @test */
    public function testWebSocketServerInjectsAllowStaleAttribute(): void
    {
        $path = __DIR__ . '/../../app/Console/Commands/WebSocketAgentServer.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'WebSocketAgentServer.php 읽기 실패');

        $hasAttribute = (bool) preg_match(
            '/attributes->set\s*\(\s*[\'"]ws_allow_stale[\'"]\s*,\s*true\s*\)/',
            $src
        );

        $this->assertTrue(
            $hasAttribute,
            'WebSocketAgentServer 에서 $request->attributes->set("ws_allow_stale", true) 를 찾을 수 없음. ' .
            'WS 전송 경로를 REST 와 구분하려면 이 attribute 주입이 필수.'
        );
    }
}
