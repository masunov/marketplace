<?php
declare(strict_types=1);

namespace App\Providers;

use App\Domain\Services\PaymentSystems\PsMir\Client\PsMirClient;
use App\Domain\Services\PaymentSystems\PsMir\ProcessPaymentCallbackService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class PaymentSystemsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->bind(PsMirClient::class, fn() => new PsMirClient(
            totalChances:      config('marketplace.total_chances'),
            rejectChance:      config('marketplace.payment_systems.ps_mir.refund.reject'),
            unavailableChance: config('marketplace.payment_systems.ps_mir.refund.unavailable'),
            chargeFailChance:  config('marketplace.payment_systems.ps_mir.charge.fail'),
            chargeLostChance:  config('marketplace.payment_systems.ps_mir.charge.lost'),
            callbackMinDelay:  config('marketplace.payment_systems.ps_mir.charge.min_delay'),
            callbackMaxDelay:  config('marketplace.payment_systems.ps_mir.charge.max_delay'),
        ));

        $this->app->bind(ProcessPaymentCallbackService::class, fn() => new ProcessPaymentCallbackService(
            lockTtlSeconds:       config('marketplace.payment_systems.ps_mir.callback_lock_ttl_in_seconds'),
        ));
    }

    public function boot(): void
    {
    }

    public function provides(): array
    {
        return [
            PsMirClient::class,
            ProcessPaymentCallbackService::class,
        ];
    }
}
