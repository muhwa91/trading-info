<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossStockMaster;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TossStockMaster 단위 테스트.
 *
 * 검증 대상:
 *   - getInfo(): 토스 응답 파싱, 캐시 저장, 캐시 히트 시 API 미호출
 *   - getInfoBatch(): 미스 심볼만 배치 호출, 이미 캐시된 항목 재사용
 *   - getName(): 토스명 반환, 폴백(심볼 그대로)
 *   - getType(): stock/etf 매핑
 *   - 지수 심볼(NQ=F, KOSPI200) → null/graceful
 *   - 빈 응답 graceful 처리 (화면 깨짐 없음)
 *   - TTL 1일 캐시 저장 확인
 *   - securityType 매핑(STOCK→stock, ETF→etf, FUTURES→stock)
 *   - 국내 종목 심볼(.KS 접미사 포함) 처리
 */
class TossStockMasterTest extends TestCase
{
    private $clientMock;
    private TossStockMaster $master;
    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->mapper     = new TossSymbolMapper();

        $this->master = new TossStockMaster(
            $this->clientMock,
            $this->mapper
        );

        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // getInfo — 토스 응답 파싱 및 캐시
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getInfo_국내종목_한글명과_타입을_반환한다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => '005930',
                    'name'         => '삼성전자',
                    'englishName'  => 'Samsung Electronics',
                    'securityType' => 'STOCK',
                    'currency'     => 'KRW',
                ]],
            ]);

        $info = $this->master->getInfo('005930.KS');

        $this->assertNotNull($info);
        $this->assertSame('삼성전자', $info['name']);
        $this->assertSame('stock', $info['type']);
        $this->assertSame('KRW', $info['currency']);
        $this->assertFalse($info['isEtf']);
    }

    /** @test */
    public function getInfo_미국종목_영문명과_USD_반환한다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => 'AAPL',
                    'name'         => '애플',
                    'englishName'  => 'Apple Inc.',
                    'securityType' => 'STOCK',
                    'currency'     => 'USD',
                ]],
            ]);

        $info = $this->master->getInfo('AAPL');

        $this->assertNotNull($info);
        $this->assertSame('애플', $info['name']);
        $this->assertSame('stock', $info['type']);
        $this->assertSame('USD', $info['currency']);
    }

    /** @test */
    public function getInfo_ETF_securityType_etf로_매핑된다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => '0167A0',
                    'name'         => 'SOL AI반도체TOP2플러스',
                    'englishName'  => 'SOL AI Chip TOP2 Plus',
                    'securityType' => 'ETF',
                    'currency'     => 'KRW',
                ]],
            ]);

        $info = $this->master->getInfo('0167A0');

        $this->assertNotNull($info);
        $this->assertSame('etf', $info['type']);
        $this->assertTrue($info['isEtf']);
    }

    /** @test */
    public function getInfo_FUTURES_securityType_stock으로_폴백된다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => 'SOXL',
                    'name'         => 'SOXL',
                    'englishName'  => 'Direxion Daily Semiconductors Bull 3X',
                    'securityType' => 'FUTURES',
                    'currency'     => 'USD',
                ]],
            ]);

        $info = $this->master->getInfo('SOXL');

        $this->assertNotNull($info);
        $this->assertSame('stock', $info['type']); // FUTURES → stock 폴백
    }

    /** @test */
    public function getInfo_캐시_히트_시_API를_다시_호출하지_않는다(): void
    {
        $this->clientMock
            ->expects($this->once()) // 단 1회만 호출 기대
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => 'TSLA',
                    'name'         => '테슬라',
                    'securityType' => 'STOCK',
                    'currency'     => 'USD',
                ]],
            ]);

        // 첫 호출 — API 호출 발생, 캐시 저장
        $first = $this->master->getInfo('TSLA');
        // 두 번째 호출 — 캐시 히트, API 미호출
        $second = $this->master->getInfo('TSLA');

        $this->assertSame($first, $second);
    }

    /** @test */
    public function getInfo_TTL_1일로_캐시에_저장된다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => 'NVDA',
                    'name'         => '엔비디아',
                    'securityType' => 'STOCK',
                    'currency'     => 'USD',
                ]],
            ]);

        $this->master->getInfo('NVDA');

        // 캐시 키가 저장되어 있는지 확인
        $cached = Cache::get('toss_stock_master_NVDA');
        $this->assertNotNull($cached);
        $this->assertSame('엔비디아', $cached['name']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 지수 심볼 — null/graceful
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getInfo_지수_심볼은_null을_반환한다(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->master->getInfo('NQ=F'));
        $this->assertNull($this->master->getInfo('KOSPI200'));
        $this->assertNull($this->master->getInfo('^KS200'));
    }

    // ──────────────────────────────────────────────────────────────────
    // 빈 응답 / 토스 실패 — graceful
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getInfo_빈응답_graceful_처리(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]); // 토스 빈 응답

        $info = $this->master->getInfo('UNKNOWN');

        // null 반환 — getName/getType 폴백이 이어서 처리
        $this->assertNull($info);
    }

    // ──────────────────────────────────────────────────────────────────
    // getName — 폴백 = 심볼 그대로
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getName_토스_캐시에서_이름을_반환한다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => '000660',
                    'name'         => 'SK하이닉스',
                    'securityType' => 'STOCK',
                    'currency'     => 'KRW',
                ]],
            ]);

        $name = $this->master->getName('000660.KS');

        $this->assertSame('SK하이닉스', $name);
    }

    /** @test */
    public function getName_토스_실패시_심볼을_그대로_반환한다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]); // 빈 응답

        $name = $this->master->getName('UNKNOWN_TICKER');

        // 화면이 깨지지 않도록 심볼 자체를 반환
        $this->assertSame('UNKNOWN_TICKER', $name);
    }

    /** @test */
    public function getName_국내종목_접미사_포함_심볼도_처리된다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => '035420',
                    'name'         => 'NAVER',
                    'securityType' => 'STOCK',
                    'currency'     => 'KRW',
                ]],
            ]);

        $name = $this->master->getName('035420.KS');

        $this->assertSame('NAVER', $name);
    }

    // ──────────────────────────────────────────────────────────────────
    // getInfoBatch — N+1 방지
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getInfoBatch_복수_심볼을_단일_배치_호출로_처리한다(): void
    {
        $this->clientMock
            ->expects($this->once()) // 1회 배치 호출만 기대
            ->method('get')
            ->with(
                '/api/v1/stocks',
                $this->callback(function ($params) {
                    // 쉼표 구분 심볼 파라미터 확인
                    return isset($params['symbols']) && strpos($params['symbols'], ',') !== false;
                })
            )
            ->willReturn([
                'stocks' => [
                    ['symbol' => '005930', 'name' => '삼성전자', 'securityType' => 'STOCK', 'currency' => 'KRW'],
                    ['symbol' => '000660', 'name' => 'SK하이닉스', 'securityType' => 'STOCK', 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->master->getInfoBatch(['005930.KS', '000660.KS']);

        $this->assertArrayHasKey('005930', $result);
        $this->assertArrayHasKey('000660', $result);
        $this->assertSame('삼성전자', $result['005930']['name']);
        $this->assertSame('SK하이닉스', $result['000660']['name']);
    }

    /** @test */
    public function getInfoBatch_캐시된_항목은_API_호출에서_제외된다(): void
    {
        // 005930 을 사전에 캐시에 넣기
        Cache::put('toss_stock_master_005930', [
            'name' => '삼성전자', 'type' => 'stock', 'currency' => 'KRW', 'isEtf' => false,
        ], 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'stocks' => [
                    ['symbol' => '000660', 'name' => 'SK하이닉스', 'securityType' => 'STOCK', 'currency' => 'KRW'],
                ],
            ]);

        // 005930(캐시 히트) + 000660(캐시 미스) 배치
        $result = $this->master->getInfoBatch(['005930', '000660']);

        $this->assertArrayHasKey('005930', $result);
        $this->assertArrayHasKey('000660', $result);
        // 캐시에서 온 값이 그대로인지
        $this->assertSame('삼성전자', $result['005930']['name']);
    }

    /** @test */
    public function getInfoBatch_지수_심볼은_결과에서_제외된다(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'stocks' => [
                    ['symbol' => 'AAPL', 'name' => '애플', 'securityType' => 'STOCK', 'currency' => 'USD'],
                ],
            ]);

        // NQ=F 는 지수 — 결과에서 빠져야 함
        $result = $this->master->getInfoBatch(['AAPL', 'NQ=F', 'KOSPI200']);

        $this->assertArrayHasKey('AAPL', $result);
        $this->assertArrayNotHasKey('NQ=F', $result);
        $this->assertArrayNotHasKey('KOSPI200', $result);
    }

    /** @test */
    public function getInfoBatch_빈_배열_입력_시_빈_배열_반환(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->master->getInfoBatch([]);

        $this->assertSame([], $result);
    }

    // ──────────────────────────────────────────────────────────────────
    // getType — 폴백 = 'stock'
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getType_토스_실패시_stock으로_폴백한다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]);

        $type = $this->master->getType('ANY_SYMBOL');

        $this->assertSame('stock', $type);
    }

    // ──────────────────────────────────────────────────────────────────
    // 오타교정 심볼 (0167AO → 0167A0)
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function getInfo_오타교정심볼_0167AO_처리된다(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'stocks' => [[
                    'symbol'       => '0167A0',
                    'name'         => 'SOL AI반도체TOP2플러스',
                    'securityType' => 'ETF',
                    'currency'     => 'KRW',
                ]],
            ]);

        // 오타 포함 심볼 입력 (O는 영문 O, 토스 심볼은 0)
        $info = $this->master->getInfo('0167AO');

        $this->assertNotNull($info);
        $this->assertSame('SOL AI반도체TOP2플러스', $info['name']);
        $this->assertSame('etf', $info['type']);
    }
}
