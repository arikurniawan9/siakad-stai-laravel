<?php

namespace App\Providers;

use App\Integrations\Bsi\Contracts\VirtualAccountGateway;
use App\Integrations\Bsi\FakeBsiVirtualAccountGateway;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\LogWhatsAppGateway;
use App\Integrations\WhatsApp\MetaWhatsAppGateway;
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
        $this->app->singleton(WhatsAppGateway::class, function (): WhatsAppGateway {
            return match (config('finance_notifications.whatsapp.driver')) {
                'log' => new LogWhatsAppGateway,
                'meta' => new MetaWhatsAppGateway,
                default => throw new LogicException('Driver WhatsApp tidak dikenali. Gunakan log atau meta.'),
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
