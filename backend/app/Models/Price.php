<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    /**
     * prices 테이블은 created_at 없이 recorded_at · updated_at 만 사용.
     * Laravel 기본 timestamps() 사용 안 함.
     */
    public $timestamps = false;

    protected $fillable = [
        'stock_id',
        'price',
        'regular_close',
        'change_amount',
        'change_percent',
        'session',
        'recorded_at',
        'updated_at',
    ];

    protected $casts = [
        'price'          => 'decimal:4',
        'regular_close'  => 'decimal:4',
        'change_amount'  => 'decimal:4',
        'change_percent' => 'decimal:4',
        'recorded_at'    => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
