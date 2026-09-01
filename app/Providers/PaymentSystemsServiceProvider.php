<?php
declare(strict_types=1);

namespace App\Providers;

use App\Domain\Services\PaymentSystems\PsMir\ProcessPaymentCallbackService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class PaymentSystemsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->bind(ProcessPaymentCallbackService::class, fn() => new ProcessPaymentCallbackService(
            lockTtlSeconds:       config('marketplace.payment_systems.ps_mir.callback_lock_ttl_in_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    public function provides(): array
    {
        return [
            ProcessPaymentCallbackService::class,
        ];
    }
}
