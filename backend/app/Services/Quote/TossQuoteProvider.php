<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\Stock;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스증권 현재가 프로바이더 — QuoteProviderInterface 구현.
 *
 * Phase 4: 국내(KR) + 미국(US) 종목.
 *   - TossPriceFetcher::fetchSingle() 으로 국내 현재가 조회
 *   - TossPriceFetcher::fetchOverseasSingle() 으로 미국 현재가 조회
 *   - TossChangeCalculator 가 /candles 1d 직전봉 종가로 등락 계산
 *
 * 반환 배열 키:
 *   price          float       — 종목 원래 통화 현재가
 *   change_amount  float       — 전일 대비 등락폭
 *   change_percent float       — 전일 대비 등락률(%)
 *   regular_close  float|null  — 미국 전용: 최근 완료된 정규장 종가 (KR은 null)
 *   recorded_at    string      — 가격 기준 시각 (Y-m-d H:i:s, UTC)
 *
 * 폴백 캐시:
 *   KR: `kis_last_successful_price_{appSymbol}` (TTL 86400)
 *   US: `kis_last_successful_overseas_price_{appSymbol}` (TTL 86400)
 *   — TossPriceFetcher 가 조회 성공 시마다 갱신함.
 *
 * 설계 §2.2(TossQuoteProvider) · §2.3 · §2.4 준거.
 */
class TossQuoteProvider implements QuoteProviderInterface
{
    /** 국내 폴백 캐시 키 접두 (KIS 관례 하위호환 유지) */
    private const FALLBACK_KEY_PREFIX = 'kis_last_successful_price_';

    /** 미국 폴백 캐시 키 접두 (KIS 관례 하위호환 유지) */
    private const FALLBACK_KEY_PREFIX_US = 'kis_last_successful_overseas_price_';

    private TossPriceFetcher $priceFetcher;
    private TossChangeCalculator $changeCalculator;
    private TossSymbolMapper $mapper;

    public function __construct(
        TossPriceFetcher $priceFetcher,
        TossChangeCalculator $changeCalculator,
        TossSymbolMapper $mapper
    ) {
        $this->priceFetcher     = $priceFetcher;
        $this->changeCalculator = $changeCalculator;
        $this->mapper           = $mapper;
    }

    /**
     * 국내·미국 종목 현재가·등락을 반환한다.
     *
     * 지수 → null 반환.
     * KR → TossPriceFetcher::fetchSingle()
     * US → TossPriceFetcher::fetchOverseasSingle() (regular_close 포함)
     * API 실패 시: 폴백 캐시 반환 (graceful).
     *
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:float|null,recorded_at:string}|null
     */
    public function fetchQuote(Stock $stock, string $session): ?array
    {
        $appSymbol = $stock->symbol;

        // 지수 skip
        if ($this->mapper->shouldSkip($appSymbol)) {
            return null;
        }

        $market = $this->mapper->market($appSymbol);

        if ($market === 'US') {
            return $this->fetchUsQuote($appSymbol);
        }

        if ($market !== 'KR') {
            return null;
        }

        return $this->fetchKrQuote($appSymbol);
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 시장별 조회
    // ──────────────────────────────────────────────────────────────────

    /**
     * 국내(KR) 종목 현재가 조회.
     *
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:null,recorded_at:string}|null
     */
    private function fetchKrQuote(string $appSymbol): ?array
    {
        $fallbackKey = self::FALLBACK_KEY_PREFIX . $appSymbol;

        try {
            $result = $this->priceFetcher->fetchSingle($appSymbol);

            if ($result === null) {
                $fallback = Cache::get($fallbackKey);
                if ($fallback !== null) {
                    Log::debug("[TossQuoteProvider] KR 폴백 캐시 사용: {$appSymbol}");
                    return $this->toQuoteArray($fallback);
                }
                return null;
            }

            return $this->toQuoteArray($result);
        } catch (\Throwable $e) {
            Log::error("[TossQuoteProvider] {$appSymbol} KR 조회 예외: " . $e->getMessage());
            $fallback = Cache::get($fallbackKey);
            return $fallback !== null ? $this->toQuoteArray($fallback) : null;
        }
    }

    /**
     * 미국(US) 종목 현재가 조회.
     *
     * TossPriceFetcher::fetchOverseasSingle() 이 토스 → Yahoo → 24h 캐시 폴백 체인을 담당.
     *
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:float|null,recorded_at:string}|null
     */
    private function fetchUsQuote(string $appSymbol): ?array
    {
        $fallbackKey = self::FALLBACK_KEY_PREFIX_US . $appSymbol;

        try {
            $result = $this->priceFetcher->fetchOverseasSingle($appSymbol);

            if ($result === null) {
                $fallback = Cache::get($fallbackKey);
                if ($fallback !== null) {
                    Log::debug("[TossQuoteProvider] US 폴백 캐시 사용: {$appSymbol}");
                    return $this->toQuoteArray($fallback);
                }
                return null;
            }

            return $this->toQuoteArray($result);
        } catch (\Throwable $e) {
            Log::error("[TossQuoteProvider] {$appSymbol} US 조회 예외: " . $e->getMessage());
            $fallback = Cache::get($fallbackKey);
            return $fallback !== null ? $this->toQuoteArray($fallback) : null;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * TossPriceFetcher 반환 배열 → QuoteProviderInterface 반환 형식으로 변환.
     *
     * PriceService::upsertPrice 가 'recorded_at' 을 요구하므로 now() 채움.
     * regular_close: 미국 종목의 경우 토스/Yahoo 에서 채워지고, KR은 null.
     *
     * @param  array{price:float,change_amount:float,change_percent:float,regular_close?:float|null}  $raw
     * @return array{price:float,change_amount:float,change_percent:float,regular_close:float|null,recorded_at:string}
     */
    private function toQuoteArray(array $raw): array
    {
        return [
            'price'          => (float) ($raw['price'] ?? 0.0),
            'change_amount'  => (float) ($raw['change_amount'] ?? 0.0),
            'change_percent' => (float) ($raw['change_percent'] ?? 0.0),
            'regular_close'  => isset($raw['regular_close']) && (float) $raw['regular_close'] > 0
                ? (float) $raw['regular_close']
                : null,
            'recorded_at'    => now()->toDateTimeString(),
        ];
    }
}
