<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreWatchlistRequest;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Services\KrStockResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 관심종목(watchlist) CRUD.
 *
 * UNIQUE(user_id, stock_id) 충돌 시 graceful 처리(409 반환).
 * US 종목이 stocks 에 없으면 firstOrCreate 로 lazy 생성.
 */
class WatchlistController extends Controller
{
    /** 고정 user_id (로그인 이전 단계) */
    private const USER_ID = 1;

    private KrStockResolver $krStockResolver;

    public function __construct(KrStockResolver $krStockResolver)
    {
        $this->krStockResolver = $krStockResolver;
    }

    /**
     * POST /api/watchlist
     * 관심종목 추가.
     */
    public function store(StoreWatchlistRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $stock = $this->resolveOrCreateStock($data);

        if ($stock === null) {
            return response()->json([
                'message' => 'stock_id 또는 symbol+market 을 입력해 주세요.',
            ], 422);
        }

        // UNIQUE 충돌 확인
        $exists = Watchlist::where('user_id', self::USER_ID)
            ->where('stock_id', $stock->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '이미 관심종목에 추가된 종목입니다.',
            ], 409);
        }

        // sort_order: 미지정 시 현재 최대값 + 1
        $sortOrder = isset($data['sort_order'])
            ? (int)$data['sort_order']
            : $this->nextSortOrder();

        $watchlist = DB::transaction(function () use ($stock, $sortOrder): Watchlist {
            return Watchlist::create([
                'user_id'    => self::USER_ID,
                'stock_id'   => $stock->id,
                'sort_order' => $sortOrder,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        });

        return response()->json([
            'message'   => '관심종목에 추가되었습니다.',
            'watchlist' => $this->formatWatchlistRow($watchlist->load('stock')),
        ], 201);
    }

    /**
     * DELETE /api/watchlist/{id}
     * 관심종목 삭제.
     */
    public function destroy(int $id): JsonResponse
    {
        $watchlist = Watchlist::where('id', $id)
            ->where('user_id', self::USER_ID)
            ->first();

        if ($watchlist === null) {
            return response()->json(['message' => '관심종목을 찾을 수 없습니다.'], 404);
        }

        DB::transaction(function () use ($watchlist): void {
            $watchlist->delete();
        });

        return response()->json(['message' => '관심종목에서 삭제되었습니다.']);
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * stock_id 또는 symbol+market 으로 Stock 조회·생성.
     *
     * US : stocks 에 없으면 firstOrCreate (lazy 생성).
     * KR : 심볼 정규화(.KS/.KQ 접미사 제거) 후 조회. 없으면 KrStockResolver 로 lazy 생성.
     *
     * @param  array<string, mixed> $data
     * @return Stock|null
     */
    private function resolveOrCreateStock(array $data): ?Stock
    {
        if (!empty($data['stock_id'])) {
            return Stock::find((int)$data['stock_id']);
        }

        $symbol = isset($data['symbol']) ? trim((string)$data['symbol']) : '';
        $market = isset($data['market']) ? strtoupper(trim((string)$data['market'])) : '';

        if ($symbol === '' || $market === '') {
            return null;
        }

        if ($market === 'US') {
            // Phase 7: name·type·currency 는 토스 캐시(TossStockMaster)가 제공.
            // 마이그레이션 실행 전까지는 NOT NULL 제약을 위해 기본값을 유지하고,
            // 실행 후에는 컬럼이 사라지므로 자동으로 무해화된다.
            $symbolUpper = strtoupper($symbol);
            return Stock::firstOrCreate(
                ['symbol' => $symbolUpper, 'market' => 'US'],
                [
                    'name'     => $symbolUpper, // 마이그레이션 전 NOT NULL 대비 기본값; 표시명은 TossStockMaster accessor 경유
                    'type'     => 'stock',       // 동일
                    'currency' => 'USD',         // 동일
                    'exchange' => null,
                ]
            );
        }

        if ($market === 'KR') {
            $nameHint = isset($data['name']) ? trim((string)$data['name']) : null;
            return $this->krStockResolver->resolveOrCreate($symbol, $nameHint ?: null);
        }

        return null;
    }

    /**
     * 현재 user_id 의 관심종목 최대 sort_order + 1 반환.
     */
    private function nextSortOrder(): int
    {
        $max = Watchlist::where('user_id', self::USER_ID)->max('sort_order');
        return (int)$max + 1;
    }

    /**
     * Watchlist 행을 응답용 배열로 포맷.
     *
     * @param  Watchlist $watchlist  stock 관계가 로드된 모델
     * @return array<string, mixed>
     */
    private function formatWatchlistRow(Watchlist $watchlist): array
    {
        $stock = $watchlist->stock;
        return [
            'id'         => $watchlist->id,
            'user_id'    => $watchlist->user_id,
            'stock_id'   => $watchlist->stock_id,
            'symbol'     => $stock ? $stock->symbol   : null,
            'name'       => $stock ? $stock->name     : null,
            'market'     => $stock ? $stock->market   : null,
            'currency'   => $stock ? $stock->currency : null,
            'sort_order' => $watchlist->sort_order,
            'created_at' => $watchlist->created_at ? $watchlist->created_at->toDateTimeString() : null,
        ];
    }
}
