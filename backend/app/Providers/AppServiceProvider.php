<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FxService;
use App\Services\MarketSessionService;
use App\Services\PriceService;
use App\Services\Quote\TossQuoteProvider;
use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossFxProvider;
use App\Services\Toss\TossCandleProvider;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossStockMaster;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // 토스 인프라 (Phase 1 신규, 싱글톤으로 토큰 캐시 공유)
        $this->app->singleton(TossApiClient::class);
        $this->app->singleton(TossSymbolMapper::class);

        // Phase 7 신규: 종목 마스터 (name·type·currency, TTL 1일 캐시)
        $this->app->singleton(TossStockMaster::class, function ($app) {
            return new TossStockMaster(
                $app->make(TossApiClient::class),
                $app->make(TossSymbolMapper::class)
            );
        });

        $this->app->singleton(TossFxProvider::class, function ($app) {
            return new TossFxProvider($app->make(TossApiClient::class));
        });

        // Phase 3 신규: 국내 현재가 배치 페처 · 등락 계산기 · Quote 프로바이더
        $this->app->singleton(TossChangeCalculator::class, function ($app) {
            return new TossChangeCalculator($app->make(TossApiClient::class));
        });

        $this->app->singleton(TossPriceFetcher::class, function ($app) {
            return new TossPriceFetcher(
                $app->make(TossApiClient::class),
                $app->make(TossSymbolMapper::class),
                $app->make(TossChangeCalculator::class)
            );
        });

        $this->app->singleton(TossQuoteProvider::class, function ($app) {
            return new TossQuoteProvider(
                $app->make(TossPriceFetcher::class),
                $app->make(TossChangeCalculator::class),
                $app->make(TossSymbolMapper::class)
            );
        });

        // Phase 5 신규: 개별종목 캔들 프로바이더
        $this->app->singleton(TossCandleProvider::class, function ($app) {
            return new TossCandleProvider(
                $app->make(TossApiClient::class),
                $app->make(TossSymbolMapper::class),
                $app->make(TossChangeCalculator::class)
            );
        });

        // MarketSessionService — TossApiClient 주입 (토스 캘린더 기반 KR 거래일 판정)
        $this->app->singleton(MarketSessionService::class, function ($app) {
            return new MarketSessionService(
                $app->make(TossApiClient::class)
            );
        });

        // PriceService — Phase 4: KR+US → TossQuoteProvider 통합
        $this->app->singleton(PriceService::class, function ($app) {
            return new PriceService(
                $app->make(TossQuoteProvider::class)  // Phase 4: KR+US 모두 토스
            );
        });

        // FxService — Phase 2: TossFxProvider 주입
        $this->app->singleton(FxService::class, function ($app) {
            return new FxService($app->make(TossFxProvider::class));
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
