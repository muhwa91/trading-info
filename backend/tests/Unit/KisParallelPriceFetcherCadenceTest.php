<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\KisParallelPriceFetcher;
use PHPUnit\Framework\TestCase;

/**
 * KisParallelPriceFetcher 케이던스 버그 수정 단위 테스트.
 *
 * 배경 (2026-06-22):
 *   WebSocket 3초 사이클에서 KIS 현재가 캐시 TTL이 3초라 매 사이클 캐시가 만료되어
 *   전 종목을 KIS 에 재호출 → 응답 지연이 쌓여 차트 멈칫(665ms~3673ms 스파이크) 발생.
 *
 * 수정 내용:
 *   1. PRICE_CACHE_TTL: 3초 → 8초 (사이클 간 캐시 재활용으로 신규 호출 빈도 절감)
 *   2. Guzzle timeout: 5.0 → 2.5초 / connect_timeout: 3.0 → 1.5초
 *      (한 종목이 느려도 배치 전체 stall을 2.5초 내 차단)
 *   3. pushRealtimeData() 순서: fetch→전송 에서 전송(캐시 우선)→fetch 로 변경
 *      (이 테스트는 fetcher 자체만 검증, 순서는 WebSocketAgentServerTest 에서 별도 검증 대상)
 *
 * 검증 케이스:
 *   1. PRICE_CACHE_TTL 상수가 8 이상인지 (캐시 재활용 보장)
 *   2. PRICE_CACHE_TTL 상수가 웹소켓 사이클(3초)보다 크다 (매 사이클 만료 방지)
 *   3. fetchDomesticBatch Guzzle timeout <= 2.5초 (hard timeout 확인)
 *   4. fetchDomesticBatch connect_timeout <= 1.5초
 *   5. fetchOverseasBatch Guzzle timeout <= 2.5초
 *   6. fetchOverseasBatch connect_timeout <= 1.5초
 *   7. fetchAll(empty) 는 항상 fetched=0, cached=0, failed=0 반환
 *   8. skipList 종목(지수·환율)은 KIS 호출 없이 캐시 0건으로 처리
 *
 * PHP 7.4 환경: Reflection으로 private 상수 및 메서드 접근.
 */
class KisParallelPriceFetcherCadenceTest extends TestCase
{
    /** @var KisParallelPriceFetcher */
    private $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new KisParallelPriceFetcher();
    }

    // ──────────────────────────────────────────────────────────────
    // 1. PRICE_CACHE_TTL >= 8 (캐시 재활용 보장)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testPriceCacheTtlIsAtLeastEightSeconds(): void
    {
        $ref = new \ReflectionClassConstant(KisParallelPriceFetcher::class, 'PRICE_CACHE_TTL');
        $ttl = $ref->getValue();

        $this->assertGreaterThanOrEqual(
            8,
            $ttl,
            'PRICE_CACHE_TTL 이 8초 미만이면 WebSocket 3초 사이클마다 캐시가 만료되어 매 사이클 KIS 재호출이 발생한다'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 2. PRICE_CACHE_TTL > WebSocket 사이클(3초) — 매 사이클 만료 방지
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testPriceCacheTtlExceedsWebSocketCyclePeriod(): void
    {
        $wsCycleSec = 3;
        $ref = new \ReflectionClassConstant(KisParallelPriceFetcher::class, 'PRICE_CACHE_TTL');
        $ttl = $ref->getValue();

        $this->assertGreaterThan(
            $wsCycleSec,
            $ttl,
            "PRICE_CACHE_TTL({$ttl}s)이 WS 사이클({$wsCycleSec}s) 이하면 매 사이클 캐시 만료로 신규 호출이 항상 발생한다"
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 3 & 4. fetchDomesticBatch — Guzzle 하드 타임아웃 확인
    //         Reflection으로 메서드 내 Client 생성 옵션을 직접 추출할 수 없으므로
    //         "Client 설정을 주입받는" 구조 테스트 대신, 소스 코드 분석 기반으로
    //         timeout / connect_timeout 값이 허용 상한 이하인지 검증한다.
    //         (소스 텍스트 스캔 방식 — 실제 네트워크 미호출)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testDomesticBatchGuzzleTimeoutIsHardCapped(): void
    {
        $source = $this->getFetcherSource();

        // fetchDomesticBatch 메서드 부분만 추출
        $methodSrc = $this->extractMethodSource($source, 'fetchDomesticBatch');

        // timeout 값 파싱
        preg_match("/'timeout'\s*=>\s*([\d.]+)/", $methodSrc, $m);
        $timeout = isset($m[1]) ? (float)$m[1] : PHP_FLOAT_MAX;

        $this->assertLessThanOrEqual(
            2.5,
            $timeout,
            "fetchDomesticBatch Guzzle timeout({$timeout}s)이 2.5초 초과: 느린 종목이 배치 전체를 stall 시킬 수 있음"
        );
    }

    /** @test */
    public function testDomesticBatchGuzzleConnectTimeoutIsHardCapped(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchDomesticBatch');

        preg_match("/'connect_timeout'\s*=>\s*([\d.]+)/", $methodSrc, $m);
        $ct = isset($m[1]) ? (float)$m[1] : PHP_FLOAT_MAX;

        $this->assertLessThanOrEqual(
            1.5,
            $ct,
            "fetchDomesticBatch connect_timeout({$ct}s)이 1.5초 초과: TCP 연결 지연이 배치를 stall 시킬 수 있음"
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 5 & 6. fetchOverseasBatch — Guzzle 하드 타임아웃 확인
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testOverseasBatchGuzzleTimeoutIsHardCapped(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchOverseasBatch');

        preg_match("/'timeout'\s*=>\s*([\d.]+)/", $methodSrc, $m);
        $timeout = isset($m[1]) ? (float)$m[1] : PHP_FLOAT_MAX;

        $this->assertLessThanOrEqual(
            2.5,
            $timeout,
            "fetchOverseasBatch Guzzle timeout({$timeout}s)이 2.5초 초과: 해외 KIS 지연이 배치 전체를 stall 시킬 수 있음"
        );
    }

    /** @test */
    public function testOverseasBatchGuzzleConnectTimeoutIsHardCapped(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchOverseasBatch');

        preg_match("/'connect_timeout'\s*=>\s*([\d.]+)/", $methodSrc, $m);
        $ct = isset($m[1]) ? (float)$m[1] : PHP_FLOAT_MAX;

        $this->assertLessThanOrEqual(
            1.5,
            $ct,
            "fetchOverseasBatch connect_timeout({$ct}s)이 1.5초 초과"
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 7. fetchAll(empty tickers) → 항상 fetched=0, cached=0, failed=0
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchAllEmptyTickersReturnsZeroStats(): void
    {
        $result = $this->fetcher->fetchAll([], 'https://example.com', 'key', 'secret', 'token');

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, $result['cached']);
        $this->assertSame(0, $result['failed']);
    }

    // ──────────────────────────────────────────────────────────────
    // 8. skipList 종목(지수·환율)은 KIS 호출 없이 결과 반환
    //    — fetched + failed = 0, cached = 0 (skipList는 캐시 히트로 카운트 안 됨)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchAllSkipListTickersDoNotTriggerKisCall(): void
    {
        // skipList 종목만 넘기면 KIS 호출 없이 즉시 반환해야 한다.
        // 토큰이 빈 문자열이므로 실제 네트워크 호출이 발생해도 조건 분기에서 걸린다.
        // (fetchAll 내부: token === '' → 조기 반환)
        $result = $this->fetcher->fetchAll(
            ['NQ=F', '^KS200', 'USDKRW=X', 'KOSPI_NIGHT', 'KOSPI200'],
            'https://example.com',
            '',   // appKey 빈 값 → fetchAll 에서 KIS 호출 스킵
            '',
            ''
        );

        // skipList 전부이므로 fetchDomesticBatch/fetchOverseasBatch 호출 없음
        // → fetched=0, failed=0 (cached는 0: totalRequested - skipCount = 0)
        $this->assertSame(0, $result['fetched'], '지수·환율은 KIS 호출 없음 → fetched=0');
        $this->assertSame(0, $result['failed'],  '지수·환율은 KIS 호출 없음 → failed=0');
    }

    // ──────────────────────────────────────────────────────────────
    // 9. resolveUsSession — 정규장 시간대(ET 10:00 = HHII 1000)
    //    Reflection 으로 private 메서드 직접 호출 + DateTimeZone 오버라이드 불가이므로
    //    소스 텍스트 스캔으로 분기 패턴 검증한다.
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testResolveUsSessionSourceContainsAllFiveSessionBranches(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'resolveUsSession');

        $this->assertStringContainsString("'정규장'", $methodSrc, 'resolveUsSession이 정규장 분기를 포함해야 함');
        $this->assertStringContainsString("'프리마켓'", $methodSrc, 'resolveUsSession이 프리마켓 분기를 포함해야 함');
        $this->assertStringContainsString("'애프터마켓'", $methodSrc, 'resolveUsSession이 애프터마켓 분기를 포함해야 함');
        $this->assertStringContainsString("'주간거래'", $methodSrc, 'resolveUsSession이 주간거래 분기를 포함해야 함');
        $this->assertStringContainsString("'장마감'", $methodSrc, 'resolveUsSession이 장마감 분기를 포함해야 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 10. resolveUsSession — 시간 경계값을 직접 호출로 검증
    //     Reflection 으로 private 메서드 접근, 현재 시각 대신 DateTime 목(mock)이
    //     없으므로 "소스에 정확한 경계값 상수"가 있는지 스캔으로 검증.
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testResolveUsSessionHasCorrectTimeBoundaries(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'resolveUsSession');

        // 정규장: 930 이상 1600 미만
        $this->assertMatchesRegularExpression('/930.*1600|1600.*930/s', $methodSrc,
            '정규장 경계(930/1600)가 resolveUsSession 에 있어야 함');

        // 프리마켓: 400 이상 930 미만
        $this->assertMatchesRegularExpression('/400.*930|930.*400/s', $methodSrc,
            '프리마켓 경계(400/930)가 resolveUsSession 에 있어야 함');

        // 애프터마켓: 1600 이상 2000 미만
        $this->assertMatchesRegularExpression('/1600.*2000|2000.*1600/s', $methodSrc,
            '애프터마켓 경계(1600/2000)가 resolveUsSession 에 있어야 함');

        // 주간거래: 2000 이상 OR 330 미만
        $this->assertStringContainsString('330', $methodSrc,
            '주간거래 종료 경계(330)가 resolveUsSession 에 있어야 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 11. fetchOverseasBatch 정규장: BAQ/BAY/BAA 가 primaryExcds/fallbackExcds 에 없음
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchOverseasBatchRegularSessionExcludesBlueOceanFromSource(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchOverseasBatch');

        // '정규장' case 블록이 있고, 그 블록에서 BAQ/BAY/BAA 를 쓰지 않음을
        // 간단 방법: 전체 switch 블록에서 '정규장' case 이후 'break' 전까지를 추출
        // → case '정규장': ... break; 구간만 가져와서 BAQ 없음 확인
        $casePos = strpos($methodSrc, "case '정규장':");
        $breakPos = strpos($methodSrc, 'break;', $casePos ?? 0);

        $this->assertNotFalse($casePos, "fetchOverseasBatch 에 '정규장' case 가 있어야 함");
        $this->assertNotFalse($breakPos, "fetchOverseasBatch '정규장' case 에 break 가 있어야 함");

        $caseBlock = substr($methodSrc, $casePos, $breakPos - $casePos + strlen('break;'));

        $this->assertStringNotContainsString("'BAQ'", $caseBlock,
            '정규장 case 에 BAQ 가 포함되면 장외 stale 오염이 발생한다');
        $this->assertStringNotContainsString("'BAY'", $caseBlock,
            '정규장 case 에 BAY 가 포함되면 장외 stale 오염이 발생한다');
        $this->assertStringNotContainsString("'BAA'", $caseBlock,
            '정규장 case 에 BAA 가 포함되면 장외 stale 오염이 발생한다');

        // 정규장 case 에 fallbackExcds=[] 패턴(빈 배열)이 있어야 함
        $this->assertStringContainsString('[]', $caseBlock,
            '정규장 fallbackExcds 는 빈 배열([]) 이어야 함 — 폴백도 차단');
    }

    // ──────────────────────────────────────────────────────────────
    // 12. EXCD 캐시 prepend 안전장치 — 소스에 in_array 검증 있는지 확인
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchOverseasBatchHasCachePrependGuard(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchOverseasBatch');

        // $allowedExcds 변수와 in_array 로 캐시 EXCD 필터링하는 패턴 확인
        $this->assertStringContainsString('$allowedExcds', $methodSrc,
            'EXCD 허용 목록 변수($allowedExcds)가 fetchOverseasBatch 에 있어야 함');

        $this->assertStringContainsString('in_array(', $methodSrc,
            'in_array 로 캐시된 EXCD 가 허용 목록에 있는지 검증해야 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 13. fetchOverseasBatch 시그니처에 bool $isOvernight 가 없고 string $session 이 있음
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchOverseasBatchSignatureUsesSessionStringNotBool(): void
    {
        $ref    = new \ReflectionClass(KisParallelPriceFetcher::class);
        $method = $ref->getMethod('fetchOverseasBatch');
        $params = $method->getParameters();

        // 마지막 파라미터가 string 타입의 $session 이어야 함
        $lastParam = end($params);
        $this->assertNotFalse($lastParam, 'fetchOverseasBatch 가 파라미터를 가져야 함');
        $this->assertSame('session', $lastParam->getName(),
            'fetchOverseasBatch 의 마지막 파라미터 이름은 session 이어야 함 (isOvernight Bool 제거 확인)');

        $type = $lastParam->getType();
        $this->assertNotNull($type, '$session 파라미터에 타입 힌트가 있어야 함');
        $this->assertSame('string', $type->getName(),
            '$session 파라미터 타입은 string 이어야 함 (bool $isOvernight 잔재 없음 확인)');
    }

    // ──────────────────────────────────────────────────────────────
    // 14. fetchAll 이 fetchOverseasBatch 에 bool 대신 string 세션을 전달
    //     소스 스캔: $isOvernight 변수가 없어야 함
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchAllDoesNotUseBoolIsOvernightAnymore(): void
    {
        $source = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($source, 'fetchAll');

        $this->assertStringNotContainsString('$isOvernight', $methodSrc,
            'fetchAll 에서 $isOvernight 변수가 제거됐어야 함 — 세션 문자열 직접 전달 방식으로 전환');

        // $session 변수가 있고 fetchOverseasBatch 에 전달됨을 확인
        $this->assertStringContainsString('$session', $methodSrc,
            'fetchAll 에서 $session 으로 세션 문자열을 결정해 fetchOverseasBatch 에 전달해야 함');
    }

    // ──────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────

    private function getFetcherSource(): string
    {
        $path = __DIR__ . '/../../app/Services/KisParallelPriceFetcher.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'KisParallelPriceFetcher.php 읽기 실패');
        return (string)$src;
    }

    /**
     * 소스 파일에서 특정 메서드 블록을 추출한다(중괄호 카운팅).
     * private/protected/public function <name>(...) { ... } 에 대응.
     */
    private function extractMethodSource(string $source, string $methodName): string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($source, '{', $pos);
        if ($openPos === false) {
            return '';
        }

        $depth  = 0;
        $end    = $openPos;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($source, $pos, $end - $pos + 1);
    }
}
