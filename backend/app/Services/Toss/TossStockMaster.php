<?php

declare(strict_types=1);

namespace App\Services\Toss;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스 /api/v1/stocks — 종목 마스터 정보 캐시.
 *
 * 책임:
 *   - 종목 이름(한글/영문), 타입(stock/etf), 통화를 토스 /stocks 엔드포인트에서 조회.
 *   - 결과를 키 toss_stock_master_{appSymbol}(TTL 1일) 에 캐싱.
 *   - N+1 방지: getInfoBatch()로 목록을 한 번에 워밍 후 캐시 히트 전제로 개별 접근.
 *   - graceful: 토스 실패 시 종목명 = 심볼 그대로 반환(화면 깨짐 방지).
 *
 * 응답 필드 매핑 (토스 /stocks 실측):
 *   symbol         → 토스 심볼 (005930, TSLA 등)
 *   name           → 한글명 (삼성전자)
 *   englishName    → 영문명 (Samsung Electronics)
 *   market         → 거래소 이름 (NASDAQ, KOSPI, KOSDAQ 등)
 *   securityType   → STOCK | ETF | FUTURES 등 → stock/etf 소문자 매핑
 *   currency       → KRW | USD
 *
 * 캐시 키 형식:
 *   toss_stock_master_{appSymbol}  (appSymbol 은 토스 심볼 변환 전 원본: 005930.KS, TSLA 등)
 *   정규화 후 저장은 toss_stock_master_{tossSymbol} 으로 통일.
 *
 * 보안:
 *   토큰·시크릿은 TossApiClient 가 관리 — 이 클래스는 읽기만.
 *
 * 참고:
 *   설계 §2.2 TossStockMaster 행, Phase 7 요구사항.
 *   TossApiClient::get()  — 토큰·rate-limit 공통 처리.
 *   TossSymbolMapper     — 심볼 정규화·지수 skip.
 */
class TossStockMaster
{
    /** 캐시 키 접두 */
    private const CACHE_PREFIX = 'toss_stock_master_';

    /** 캐시 TTL — 종목 마스터는 하루에 한 번이면 충분 */
    private const CACHE_TTL_SECONDS = 86400; // 1일

    /** /stocks 단일 호출 최대 심볼 수 (여유 마진 195) */
    private const BATCH_CHUNK_SIZE = 195;

    /** securityType → 내부 type 소문자 매핑 */
    private const TYPE_MAP = [
        'STOCK'   => 'stock',
        'ETF'     => 'etf',
        'FUTURES' => 'stock', // 선물은 stock 폴백
    ];

    private TossApiClient $client;
    private TossSymbolMapper $mapper;

    public function __construct(TossApiClient $client, TossSymbolMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    // ──────────────────────────────────────────────────────────────────
    // 공개 인터페이스
    // ──────────────────────────────────────────────────────────────────

    /**
     * 단일 종목 마스터 정보 반환 (캐시 우선).
     *
     * 캐시 미스 시 /stocks 를 단건 호출(권장: 사전에 getInfoBatch 로 워밍).
     *
     * @param  string $appSymbol  앱 내부 심볼 (예: 005930.KS, TSLA)
     * @return array{name:string, type:string, currency:string, isEtf:bool}|null
     *         지수(INDEX) 는 null 반환.
     */
    public function getInfo(string $appSymbol): ?array
    {
        if ($this->mapper->isIndex($appSymbol)) {
            return null;
        }

        $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
        if ($tossSymbol === null) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . $tossSymbol;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 캐시 미스 — 단건 조회
        $results = $this->fetchFromToss([$tossSymbol]);

        return $results[$tossSymbol] ?? null;
    }

    /**
     * 복수 종목 마스터 정보를 한 번에 캐시 워밍 후 반환 (N+1 방지).
     *
     * 이미 캐시된 항목은 건너뛰고, 미스된 심볼만 토스에 배치 호출한다.
     * 컨트롤러는 이 메서드로 목록 전체를 한 번에 워밍한 뒤
     * getName()/getType() 을 호출하면 모두 캐시 히트가 된다.
     *
     * @param  string[] $appSymbols  앱 내부 심볼 배열
     * @return array<string, array{name:string, type:string, currency:string, isEtf:bool}>
     *         키 = 앱 내부 심볼(tossSymbol), 지수/실패는 제외됨.
     */
    public function getInfoBatch(array $appSymbols): array
    {
        // 1) 지수 필터 + 토스 심볼 변환
        $tossSymbolMap = []; // tossSymbol => appSymbol
        foreach ($appSymbols as $appSymbol) {
            $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
            if ($tossSymbol !== null) {
                $tossSymbolMap[$tossSymbol] = $appSymbol;
            }
        }

        if (empty($tossSymbolMap)) {
            return [];
        }

        // 2) 캐시 히트 분리
        $result      = [];
        $missSymbols = [];

        foreach ($tossSymbolMap as $tossSymbol => $appSymbol) {
            $cacheKey = self::CACHE_PREFIX . $tossSymbol;
            $cached   = Cache::get($cacheKey);
            if ($cached !== null) {
                $result[$tossSymbol] = $cached;
            } else {
                $missSymbols[] = $tossSymbol;
            }
        }

        // 3) 캐시 미스 심볼만 배치 호출
        if (!empty($missSymbols)) {
            $fetched = $this->fetchFromToss($missSymbols);
            $result  = array_merge($result, $fetched);
        }

        return $result;
    }

    /**
     * 종목명 반환 (폴백 = 심볼 그대로).
     *
     * @param  string $appSymbol
     * @return string
     */
    public function getName(string $appSymbol): string
    {
        $info = $this->getInfo($appSymbol);
        return $info['name'] ?? $appSymbol;
    }

    /**
     * 종목 타입 반환 (폴백 = 'stock').
     *
     * @param  string $appSymbol
     * @return string  'stock' | 'etf'
     */
    public function getType(string $appSymbol): string
    {
        $info = $this->getInfo($appSymbol);
        return $info['type'] ?? 'stock';
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * 토스 /api/v1/stocks 배치 호출 (≤ BATCH_CHUNK_SIZE 청크 분할).
     *
     * 결과를 캐시에 저장하고 tossSymbol => info 맵으로 반환.
     *
     * @param  string[] $tossSymbols  정규화된 토스 심볼 목록
     * @return array<string, array{name:string, type:string, currency:string, isEtf:bool}>
     */
    private function fetchFromToss(array $tossSymbols): array
    {
        $result = [];
        $chunks = array_chunk($tossSymbols, self::BATCH_CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $symbolsParam = implode(',', $chunk);

            $data = $this->client->get('/api/v1/stocks', ['symbols' => $symbolsParam]);

            // 응답 형식: { result: [...] } (토스 표준) — prices/candles/exchange-rate 와 동일
            $items = [];
            if (isset($data['result']) && is_array($data['result'])) {
                $items = $data['result'];
            } elseif (isset($data['stocks']) && is_array($data['stocks'])) {
                $items = $data['stocks'];
            } elseif (is_array($data) && isset($data[0])) {
                $items = $data;
            }

            if (empty($items)) {
                Log::warning('[TossStockMaster] /stocks 응답 비어 있음', [
                    'symbols' => implode(',', array_slice($chunk, 0, 5)) . (count($chunk) > 5 ? '...' : ''),
                ]);
                continue;
            }

            foreach ($items as $item) {
                if (!isset($item['symbol'])) {
                    continue;
                }

                $tossSymbol = (string) $item['symbol'];
                $info       = $this->parseItem($item);

                Cache::put(self::CACHE_PREFIX . $tossSymbol, $info, self::CACHE_TTL_SECONDS);
                $result[$tossSymbol] = $info;
            }
        }

        return $result;
    }

    /**
     * 토스 /stocks 단일 항목을 내부 형식으로 변환.
     *
     * @param  array<string, mixed> $item
     * @return array{name:string, type:string, currency:string, isEtf:bool}
     */
    private function parseItem(array $item): array
    {
        // 이름: 한글명 우선, 없으면 영문명, 없으면 심볼
        $name = '';
        if (!empty($item['name'])) {
            $name = (string) $item['name'];
        } elseif (!empty($item['englishName'])) {
            $name = (string) $item['englishName'];
        } else {
            $name = (string) ($item['symbol'] ?? '');
        }

        // 타입: securityType → stock/etf
        $securityType = strtoupper((string) ($item['securityType'] ?? 'STOCK'));
        $type = self::TYPE_MAP[$securityType] ?? 'stock';

        // ETF 편의 플래그
        $isEtf = ($type === 'etf');

        // 통화
        $currency = strtoupper((string) ($item['currency'] ?? 'USD'));

        return [
            'name'     => $name,
            'type'     => $type,
            'currency' => $currency,
            'isEtf'    => $isEtf,
        ];
    }
}
