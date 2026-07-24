<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\KrStockResolver;
use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossStockMaster;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KrStockResolver — SQLite :memory: + RefreshDatabase Feature 테스트.
 *
 * phpunit.xml 에서 DB_CONNECTION=sqlite / DB_DATABASE=:memory: 가 주입되므로
 * 실 DB(TiDB Cloud hachiware)에는 절대 접근하지 않는다.
 *
 * 네트워크 미접촉(hermetic):
 *   Stock 의 name·type accessor 는 app(TossStockMaster) → TossApiClient 로 캐시 미스 시
 *   실제 토스 API 를 때린다(phpunit.xml CACHE_DRIVER=array 라 캐시는 항상 미스).
 *   그래서 setUp 에서 HTTP 대역 클라이언트로 만든 TossStockMaster 를 컨테이너에
 *   꽂아, accessor 가 부르는 app(TossStockMaster) 가 이 인스턴스를 받게 한다.
 *   TossStockMaster·Cache·accessor 는 실제 코드가 그대로 돌고 HTTP 만 대역이다.
 *   ※ TossApiClient 만 교체하면 안 된다 — RefreshDatabase 부팅 중 TossStockMaster
 *     싱글턴이 이미 실 클라이언트를 물고 resolve 되므로 교체가 늦는다(실측).
 *   대역 픽스처의 종목명은 실 토스가 절대 반환하지 않는 값('테스트…-XYZ' 등)이라,
 *   단언이 통과했다는 것 자체가 "대역이 이겼다 = 네트워크를 안 탔다"의 증거다.
 *
 * 검증 항목:
 *   1. resolveOrCreate(): 접미사 있는 심볼 → 정규화 코드로 저장됨
 *   2. resolveOrCreate(): 접미사 없는 심볼 → 동일 Stock 반환 (중복 생성 안 됨)
 *   3. name 은 nameHint 가 아니라 토스 마스터에서 온다 (nameHint 는 미반영)
 *   4-6. type 은 토스 securityType(ETF/STOCK)에서 온다 (이름 문자열 판정 아님)
 *   7. market='KR', currency='KRW' 고정
 *   8. .KS 접미사 → exchange='KOSPI' (접미사 폴백)
 *   9. .KQ 접미사 → exchange='KOSDAQ'
 *  10. 동일 코드 두 번 호출 → DB row 1개만 (firstOrCreate 보장)
 *
 * PHP 7.4 환경: named arguments 사용 금지.
 */
class KrStockResolverDbTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 토스 /stocks 대역 픽스처 — 실 토스가 절대 반환하지 않는 이름을 쓴다.
     * 여기 없는 코드는 대역이 빈 응답을 주므로 폴백 경로(심볼 그대로 / 'stock')가 검증된다.
     */
    private const TOSS_FIXTURE = [
        '005930' => ['name' => '테스트종목-XYZ',    'securityType' => 'STOCK'],
        '000660' => ['name' => '테스트종목-HYNIX',  'securityType' => 'STOCK'],
        '069500' => ['name' => '테스트ETF-KODEX',   'securityType' => 'ETF'],
        '0167A0' => ['name' => '테스트ETF-SOL',     'securityType' => 'ETF'],
        '102110' => ['name' => '테스트ETF-TIGER',   'securityType' => 'ETF'],
    ];

    /** @var KrStockResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // HTTP 대역 클라이언트 → 실 토스 호출 구조적으로 불가.
        $client = $this->createMock(TossApiClient::class);
        $client->method('get')->willReturnCallback(function (string $path, array $query = []): array {
            return ['result' => $this->fixtureFor($query['symbols'] ?? '')];
        });

        // accessor 가 부르는 app(TossStockMaster) 가 대역 클라이언트 버전을 받도록 교체.
        $this->app->instance(TossStockMaster::class, new TossStockMaster($client, new TossSymbolMapper));

        $this->resolver = new KrStockResolver;
    }

    /**
     * 요청된 symbols(콤마 구분) 중 픽스처에 있는 것만 토스 응답 형식으로 반환.
     *
     * @return array<int, array<string, string>>
     */
    private function fixtureFor(string $symbolsParam): array
    {
        $items = [];
        foreach (explode(',', $symbolsParam) as $symbol) {
            if (isset(self::TOSS_FIXTURE[$symbol])) {
                $items[] = ['symbol' => $symbol, 'currency' => 'KRW'] + self::TOSS_FIXTURE[$symbol];
            }
        }

        return $items;
    }

    // ──────────────────────────────────────────────────────────────
    // 1. 접미사 포함 심볼 → 정규화 코드로 DB 저장
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_resolve_or_create_strips_ks_suffix(): void
    {
        $stock = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        $this->assertInstanceOf(Stock::class, $stock);
        $this->assertSame('005930', $stock->symbol);
        $this->assertDatabaseHas('stocks', ['symbol' => '005930', 'market' => 'KR']);
    }

    #[Test]
    public function test_resolve_or_create_strips_kq_suffix(): void
    {
        $stock = $this->resolver->resolveOrCreate('0167A0.KQ', 'SOL AI반도체TOP2플러스');

        $this->assertSame('0167A0', $stock->symbol);
        $this->assertDatabaseHas('stocks', ['symbol' => '0167A0', 'market' => 'KR']);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. 접미사 없는 심볼 → 이미 있는 Stock 반환(중복 없음)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_resolve_or_create_no_suffix_reuses_same_stock(): void
    {
        // 접미사 있는 버전으로 먼저 생성
        $first = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        // 접미사 없는 버전으로 다시 조회
        $second = $this->resolver->resolveOrCreate('005930', '삼성전자');

        $this->assertSame($first->id, $second->id, '동일 Stock을 반환해야 한다');
        $this->assertSame(1, Stock::where('symbol', '005930')->where('market', 'KR')->count());
    }

    // ──────────────────────────────────────────────────────────────
    // 3. name 출처 — 토스 마스터 (nameHint 는 반영되지 않는다)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_name_hint_is_stored_as_name(): void
    {
        // nameHint 를 넘겨도 무시된다 — resolveOrCreate()는 exchange 만 저장하고
        // name 은 Stock accessor 가 토스 마스터에서 가져온다(nameHint 는 죽은 파라미터).
        $stock = $this->resolver->resolveOrCreate('000660.KS', 'SK하이닉스');

        $this->assertSame('테스트종목-HYNIX', $stock->name, 'nameHint 가 아니라 토스 마스터 값이어야 한다');
    }

    #[Test]
    public function test_name_hint_null_falls_back_to_map(): void
    {
        // nameHint 없이도 name 은 토스 마스터에서 온다 (KR_NAME_MAP 은 Phase 7 에서 삭제됨).
        $stock = $this->resolver->resolveOrCreate('005930', null);

        $this->assertSame('테스트종목-XYZ', $stock->name);
    }

    #[Test]
    public function test_name_hint_null_unknown_code_falls_back_to_code(): void
    {
        // 토스가 모르는 코드 → 마스터 응답 없음
        $stock = $this->resolver->resolveOrCreate('999999.KS', null);

        // 코드 자체('999999')로 폴백
        $this->assertSame('999999', $stock->name);
    }

    // ──────────────────────────────────────────────────────────────
    // 4-6. type 판정 — 토스 securityType (ETF→etf, STOCK→stock).
    //      이름 문자열(KODEX/SOL/TIGER) 판정은 Phase 7 에서 제거됨.
    //      각 케이스는 같은 마스터 항목의 고유 이름도 함께 단언해
    //      type 이 대역에서 왔음(네트워크 미접촉)을 드러낸다.
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_kodex_name_creates_etf_type(): void
    {
        $stock = $this->resolver->resolveOrCreate('069500.KS', 'KODEX 200');

        $this->assertSame('etf', $stock->type);
        $this->assertSame('테스트ETF-KODEX', $stock->name);
    }

    #[Test]
    public function test_sol_name_creates_etf_type(): void
    {
        $stock = $this->resolver->resolveOrCreate('0167A0.KQ', 'SOL AI반도체TOP2플러스');

        $this->assertSame('etf', $stock->type);
        $this->assertSame('테스트ETF-SOL', $stock->name);
    }

    #[Test]
    public function test_tiger_name_creates_etf_type(): void
    {
        $stock = $this->resolver->resolveOrCreate('102110.KS', 'TIGER 200');

        $this->assertSame('etf', $stock->type);
        $this->assertSame('테스트ETF-TIGER', $stock->name);
    }

    #[Test]
    public function test_ordinary_name_creates_stock_type(): void
    {
        $stock = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        $this->assertSame('stock', $stock->type);
        $this->assertSame('테스트종목-XYZ', $stock->name);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. market='KR', currency='KRW' 고정
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_market_is_always_kr(): void
    {
        $stock = $this->resolver->resolveOrCreate('035420.KS', 'NAVER');

        $this->assertSame('KR', $stock->market);
    }

    #[Test]
    public function test_currency_is_always_krw(): void
    {
        $stock = $this->resolver->resolveOrCreate('035420.KS', 'NAVER');

        $this->assertSame('KRW', $stock->currency);
    }

    // ──────────────────────────────────────────────────────────────
    // 8-9. exchange 추론 (접미사 폴백 — .KS→KOSPI, .KQ→KOSDAQ)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_ks_suffix_gives_kospi_exchange(): void
    {
        $stock = $this->resolver->resolveOrCreate('999991.KS', '테스트종목A');

        $this->assertSame('KOSPI', $stock->exchange);
    }

    #[Test]
    public function test_kq_suffix_gives_kosdaq_exchange(): void
    {
        $stock = $this->resolver->resolveOrCreate('999992.KQ', '테스트종목B');

        $this->assertSame('KOSDAQ', $stock->exchange);
    }

    // ──────────────────────────────────────────────────────────────
    // 10. 동일 코드 두 번 호출 → DB row 1개 (firstOrCreate)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_first_or_create_does_not_duplicate(): void
    {
        $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        $this->resolver->resolveOrCreate('005930', '삼성전자');

        $count = Stock::where('symbol', '005930')->where('market', 'KR')->count();
        $this->assertSame(1, $count, '같은 코드로 여러 번 호출해도 row 1개');
    }

    // ──────────────────────────────────────────────────────────────
    // 추가: US 심볼은 KrStockResolver 를 쓰지 않으므로 직접 DB 생성 테스트
    // (KR/US 심볼이 stocks 테이블에 공존 가능한지 확인)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_kr_and_us_stock_can_coexist_in_stocks_table(): void
    {
        // KR 종목
        $kr = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        // US 종목 직접 생성
        $us = Stock::firstOrCreate(
            ['symbol' => 'MU', 'market' => 'US'],
            [
                'name' => '마이크론 테크놀로지',
                'type' => 'stock',
                'currency' => 'USD',
                'exchange' => 'NAS',
            ]
        );

        $this->assertNotSame($kr->id, $us->id);
        $this->assertSame(2, Stock::count());
        $this->assertDatabaseHas('stocks', ['symbol' => 'MU', 'market' => 'US']);
        $this->assertDatabaseHas('stocks', ['symbol' => '005930', 'market' => 'KR']);

        // currency 는 Phase 7 에서 컬럼이 삭제됐다 — accessor(market 유도)로만 존재하므로
        // DB 조회인 assertDatabaseHas 로는 검증할 수 없다. US 분기만 accessor 로 확인
        // (KR 분기는 testCurrencyIsAlwaysKrw 가 이미 커버).
        $this->assertSame('USD', $us->currency);
    }

    #[Test]
    public function test_us_lazy_create_first_or_create(): void
    {
        // 같은 US 심볼 두 번 firstOrCreate → 1개만
        Stock::firstOrCreate(
            ['symbol' => 'AAPL', 'market' => 'US'],
            ['name' => '애플', 'type' => 'stock', 'currency' => 'USD', 'exchange' => 'NAS']
        );
        Stock::firstOrCreate(
            ['symbol' => 'AAPL', 'market' => 'US'],
            ['name' => '애플', 'type' => 'stock', 'currency' => 'USD', 'exchange' => 'NAS']
        );

        $this->assertSame(1, Stock::where('symbol', 'AAPL')->where('market', 'US')->count());
    }
}
