<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Toss\TossStockMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7 — name·type·currency 컬럼 제거 후 accessor 로 토스 캐시 경유.
 *
 * 마이그레이션 실행 전후 모두 동작:
 *   - 컬럼이 남아 있는 동안: accessor 가 토스 캐시 우선, 없으면 DB 컬럼 폴백.
 *   - 컬럼 제거 후: 토스 캐시 우선, 없으면 안전 기본값.
 *
 * currency:
 *   market 에서 유도 (KR→KRW, US→USD). 토스 호출 불필요.
 *   getInfoBatch() 배치 워밍 후엔 토스 캐시 currency 도 사용 가능.
 *
 * N+1 방지:
 *   컨트롤러는 종목 목록 심볼을 모아 TossStockMaster::getInfoBatch() 로
 *   한 번에 캐시 워밍 후 accessor 를 호출하면 모두 캐시 히트.
 */
class Stock extends Model
{
    protected $fillable = [
        'symbol',
        'market',
        'exchange',
        // name·type·currency 컬럼은 Phase 7 마이그레이션으로 삭제됨.
        // accessor(getNameAttribute 등)가 토스 캐시 경유로 값을 제공한다.
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────
    // Accessors — 토스 캐시 경유 (컬럼 제거 후에도 동작)
    // ──────────────────────────────────────────────────────────────────

    /**
     * 종목명 accessor.
     *
     * 우선순위:
     *   1) 토스 캐시 (TossStockMaster::getName)
     *   2) DB 컬럼 name (마이그레이션 실행 전)
     *   3) symbol 그대로
     *
     * N+1 주의: 개별 호출 시 캐시 미스면 토스 단건 호출 발생.
     *           컨트롤러에서 getInfoBatch() 로 사전 워밍 권장.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        /** @var TossStockMaster $master */
        $master = app(TossStockMaster::class);
        $fromToss = $master->getName($this->symbol);

        // 토스가 심볼 그대로 반환했다면 폴백 확인
        if ($fromToss !== $this->symbol) {
            return $fromToss;
        }

        // 마이그레이션 실행 전 DB 컬럼 폴백
        $dbName = $this->attributes['name'] ?? null;
        if ($dbName !== null && $dbName !== '') {
            return (string) $dbName;
        }

        return $this->symbol;
    }

    /**
     * 종목 타입 accessor.
     *
     * 우선순위:
     *   1) 토스 캐시 (TossStockMaster::getType)
     *   2) DB 컬럼 type (마이그레이션 실행 전)
     *   3) 'stock'
     *
     * @return string  'stock' | 'etf'
     */
    public function getTypeAttribute(): string
    {
        /** @var TossStockMaster $master */
        $master   = app(TossStockMaster::class);
        $info     = $master->getInfo($this->symbol);

        if ($info !== null) {
            return $info['type'];
        }

        // 마이그레이션 실행 전 DB 컬럼 폴백
        $dbType = $this->attributes['type'] ?? null;
        if ($dbType !== null) {
            return (string) $dbType;
        }

        return 'stock';
    }

    /**
     * 통화 accessor — market 에서 유도 (토스 호출 불필요).
     *
     * KR → KRW, US → USD.
     * 그 외 market 이면 토스 캐시 currency 확인 후 'USD' 기본값.
     *
     * @return string  'KRW' | 'USD'
     */
    public function getCurrencyAttribute(): string
    {
        $market = strtoupper((string) ($this->attributes['market'] ?? ''));

        if ($market === 'KR') {
            return 'KRW';
        }

        if ($market === 'US') {
            return 'USD';
        }

        // 알 수 없는 market — 토스 캐시 확인
        /** @var TossStockMaster $master */
        $master = app(TossStockMaster::class);
        $info   = $master->getInfo($this->symbol);

        return $info['currency'] ?? 'USD';
    }

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    public function watchlistEntries(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }
}
