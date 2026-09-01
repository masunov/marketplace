<?php
declare(strict_types=1);

namespace App\Providers;

use App\Domain\Factories\ProductVendors\ProductVendorProcessorsFactory;
use App\Domain\Services\ProductVendors\VendorA\Client\VendorAClient;
use App\Domain\Services\ProductVendors\VendorA\VendorAProcessor;
use App\Domain\Services\ProductVendors\VendorB\Client\VendorBClient;
use App\Domain\Services\ProductVendors\VendorB\VendorBProcessor;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class ProductVendorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
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

        $this->app->bind(
            ProductVendorProcessorsFactory::class, fn() => new ProductVendorProcessorsFactory(
            vendorAProcessor: $this->app->make(VendorAProcessor::class),
            vendorBProcessor: $this->app->make(VendorBProcessor::class),
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
            ProductVendorProcessorsFactory::class,
            VendorAClient::class,
            VendorBClient::class,
        ];
    }
}
