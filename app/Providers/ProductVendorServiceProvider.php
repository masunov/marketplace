<?php
declare(strict_types=1);

namespace App\Providers;

use App\Domain\Factories\ProductVendors\ProductVendorProcessorsFactory;
use App\Domain\Services\ProductVendors\RateLimit\ThrottledVendorProcessor;
use App\Domain\Services\ProductVendors\RateLimit\VendorRateLimiter;
use App\Domain\Services\ProductVendors\VendorA\Client\VendorAClient;
use App\Domain\Services\ProductVendors\VendorA\VendorAProcessor;
use App\Domain\Services\ProductVendors\VendorB\Client\VendorBClient;
use App\Domain\Services\ProductVendors\VendorB\VendorBProcessor;
use App\Domain\Services\ProductVendors\VendorC\Client\VendorCClient;
use App\Domain\Services\ProductVendors\VendorC\VendorCProcessor;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class ProductVendorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {

        $this->app->bind(VendorAClient::class, fn() => new VendorAClient(
            totalChances:       config('marketplace.total_chances'),
            backendErrorChance: config('marketplace.vendors.vendor_a.outcomes.error'),
            timeoutErrorChance: config('marketplace.vendors.vendor_a.outcomes.timeout'),
        ));

        $this->app->bind(VendorBClient::class, fn() => new VendorBClient(
            totalChances:       config('marketplace.total_chances'),
            backendErrorChance: config('marketplace.vendors.vendor_b.outcomes.error'),
            timeoutErrorChance: config('marketplace.vendors.vendor_b.outcomes.timeout'),
        ));

        $this->app->bind(VendorCClient::class, fn() => new VendorCClient(
            totalChances:       config('marketplace.total_chances'),
            backendErrorChance: config('marketplace.vendors.vendor_c.outcomes.error'),
            timeoutErrorChance: config('marketplace.vendors.vendor_c.outcomes.timeout'),
            duplicateChance:    config('marketplace.vendors.vendor_c.outcomes.duplicate'),
            foreignCodeChance:  config('marketplace.vendors.vendor_c.outcomes.foreign_code'),
            lyingErrorChance:   config('marketplace.vendors.vendor_c.outcomes.lying_error'),
        ));

        $this->app->singleton(VendorRateLimiter::class, fn() => new VendorRateLimiter(
            limits:               collect(config('marketplace.vendors'))
                ->map(static fn(array $vendor): int => (int) ($vendor['rpm'] ?? 0))
                ->all(),
            counterTtlInSeconds:  config('marketplace.rate_limit.counter_ttl_in_seconds'),
        ));

        $this->app->bind(
            ProductVendorProcessorsFactory::class, fn() => new ProductVendorProcessorsFactory(
            vendorAProcessor: $this->throttled(VendorAProcessor::class),
            vendorBProcessor: $this->throttled(VendorBProcessor::class),
            vendorCProcessor: $this->throttled(VendorCProcessor::class),
        ));

    }

    private function throttled(string $processor): ThrottledVendorProcessor
    {
        return new ThrottledVendorProcessor(
            processor: $this->app->make($processor),
            limiter: $this->app->make(VendorRateLimiter::class),
        );
    }

    public function boot(): void
    {
    }

    public function provides(): array
    {
        return [
            ProductVendorProcessorsFactory::class,
            VendorAClient::class,
            VendorBClient::class,
            VendorCClient::class,
            VendorRateLimiter::class,
        ];
    }
}
