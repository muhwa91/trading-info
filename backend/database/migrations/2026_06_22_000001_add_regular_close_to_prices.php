<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prices 테이블에 regular_close 컬럼 추가.
 *
 * 용도: US 보유종목의 직전 정규장 종가 저장.
 *   KIS HHDFS00000300 output.base 값을 그대로 기록.
 *   정규장 세션에서는 base ≈ 전일 종가 (장전 손익 = 0).
 *   연장(프리/애프터/주간거래) 세션에서는 base = 직전 정규장 종가,
 *   last = 연장 현재가 → 장전 손익 = (last − base) × 수량.
 *
 * nullable: KR 종목은 regular_close 불필요(null).
 */
class AddRegularCloseToPrices extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table): void {
            $table->decimal('regular_close', 18, 4)
                ->nullable()
                ->after('price')
                ->comment('직전 정규장 종가 (KIS base 필드). US 종목만. KR 종목 null.');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table): void {
            $table->dropColumn('regular_close');
        });
    }
}
