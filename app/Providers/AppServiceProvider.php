<?php

namespace App\Providers;

use App\Integrations\Bsi\Contracts\VirtualAccountGateway;
use App\Integrations\Bsi\FakeBsiVirtualAccountGateway;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VirtualAccountGateway::class, function (): VirtualAccountGateway {
            return match (config('bsi.driver')) {
                'fake' => new FakeBsiVirtualAccountGateway,
                default => throw new LogicException('Driver VA BSI riil belum tersedia tanpa kontrak onboarding resmi.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
