<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — stocks 테이블에서 name·type·currency 컬럼 제거.
 *
 * 배경:
 *   name·type·currency 는 토스 /stocks 에서 실시간 제공받아 캐시(TossStockMaster)로 관리.
 *   DB 컬럼으로 유지할 필요 없음 → 제거해 단일 소스(토스)로 일원화.
 *
 * 유지되는 것:
 *   - stocks.id            (FK 중심축 — Portfolio·Watchlist·Price·Dividend 외래키)
 *   - stocks.symbol        (종목 코드)
 *   - stocks.market        (KR / US)
 *   - stocks.exchange      (KIS Phase 4용, 나중 제거 예정)
 *   - UNIQUE(symbol, market) 제약
 *
 * 주의:
 *   이 마이그레이션을 실행하기 전에,
 *   코드베이스(컨트롤러·모델·서비스)가 name·type·currency 컬럼을
 *   직접 읽지 않음을 반드시 확인한다 (accessor 또는 토스 캐시 경유).
 *
 * !! php artisan migrate 실행 전 여중기님 승인 필요 !!
 */
class DropNameTypeCurrencyFromStocks extends Migration
{
    /**
     * stocks 테이블에서 name·type·currency 컬럼을 제거한다.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['name', 'type', 'currency']);
        });
    }

    /**
     * 롤백: name·type·currency 컬럼을 복원한다.
     *
     * 복원 후 기존 데이터가 없으므로 name 은 '' (빈 문자열 기본값),
     * type 은 'stock', currency 는 'KRW' 로 초기화된다.
     * 실제 값은 TossStockMaster 또는 KrxStocksSeeder 재실행으로 복구.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('name')->default('')->after('symbol');
            $table->enum('type', ['stock', 'etf'])->default('stock')->after('name');
            $table->enum('currency', ['KRW', 'USD'])->default('KRW')->after('exchange');
        });
    }
}
