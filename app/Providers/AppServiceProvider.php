<?php

namespace App\Providers;

use App\Services\Notification\HttpWhatsAppGateway;
use App\Services\Notification\WhatsAppGatewayInterface;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGatewayInterface::class, HttpWhatsAppGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::forceAssetInjection();
    }
}
