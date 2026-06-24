<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use App\Services\Toss\TossFxProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * USD→KRW 환율 관리 서비스.
 *
 * 소스 우선순위 (Phase 2 교체 후):
 *   1순위 — 토스증권 Open API (/api/v1/exchange-rate)
 *   2순위 — DB 직전 캐시값 (신선도 FX_STALE_SECONDS 이내)
 *   3순위 — DB 최후 저장값 (신선도 만료여도 반환, 완전 폴백)
 *
 * exchange_rates 테이블에 (from_currency, to_currency) 고유 키로 최신 1건 upsert.
 * 신선도: FX_STALE_SECONDS(기본 300초=5분) 이내 데이터는 외부 호출 생략.
 *
 * 설계 참조: docs/features/toss-api-migration/01-계획.md §2.2 (FxService 행), Phase 2.
 */
class FxService
{
    /** 환율 신선도 임계값(초). 5분. */
    private const FX_STALE_SECONDS = 300;

    private const FROM = 'USD';
    private const TO   = 'KRW';

    private TossFxProvider $tossFxProvider;

    public function __construct(TossFxProvider $tossFxProvider)
    {
        $this->tossFxProvider = $tossFxProvider;
    }

    /**
     * USD→KRW 최신 환율 반환.
     *
     * 신선하면 DB값 그대로, 오래됐으면:
     *   1. 토스 API 호출
     *   2. 실패 시 DB 직전값 (신선도 무관)
     *   3. DB도 없으면 null
     *
     * @return array{rate:float,recorded_at:string,source:string}|null
     */
    public function getUsdKrw(): ?array
    {
        $row = ExchangeRate::where('from_currency', self::FROM)
            ->where('to_currency', self::TO)
            ->first();

        $staleThreshold = Carbon::now()->subSeconds(self::FX_STALE_SECONDS);

        // DB 값이 신선하면 외부 호출 생략
        if ($row !== null && $row->recorded_at !== null && $row->recorded_at->gt($staleThreshold)) {
            return [
                'rate'        => (float) $row->rate,
                'recorded_at' => $row->recorded_at->toDateTimeString(),
                'source'      => (string) ($row->source ?? 'cached'),
            ];
        }

        // ── 1순위: 토스 환율 API ─────────────────────────────────────────
        $fetched = $this->tossFxProvider->fetchUsdKrw();

        // ── 2순위: DB 직전값 (토스 실패 시) ────────────────────────────────
        if ($fetched === null) {
            Log::warning('[FxService] 토스 환율 취득 실패 — DB 직전값 사용');
            if ($row !== null) {
                return [
                    'rate'        => (float) $row->rate,
                    'recorded_at' => $row->recorded_at ? $row->recorded_at->toDateTimeString() : null,
                    'source'      => 'db_fallback',
                ];
            }

            // ── 3순위: DB 완전 폴백 (값은 있지만 신선도 만료) ────────────────
            Log::warning('[FxService] DB값 없음 — 환율 취득 불가');
            return null;
        }

        $this->upsertRate($fetched['rate'], $fetched['recorded_at'], $fetched['source']);

        return $fetched;
    }

    /**
     * exchange_rates upsert: (from_currency, to_currency) 기준 최신 1건 유지.
     */
    public function upsertRate(float $rate, string $recordedAt, string $source = 'unknown'): void
    {
        DB::table('exchange_rates')->upsert(
            [
                'from_currency' => self::FROM,
                'to_currency'   => self::TO,
                'rate'          => $rate,
                'recorded_at'   => $recordedAt,
                'source'        => $source,
            ],
            ['from_currency', 'to_currency'],
            ['rate', 'recorded_at', 'source']
        );
    }
}
