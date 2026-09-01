<?php

namespace App\Providers;

use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Listeners\Orders\LogOrderStatusChangeListener;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ServeCommand::$passthroughVariables = array_unique(array_merge(
            ServeCommand::$passthroughVariables,
            [
                'APP_URL',
                'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
                'QUEUE_CONNECTION', 'CACHE_STORE',
                'REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD',
            ]
        ));

        Relation::enforceMorphMap([
            VendorKey::morphAlias() => VendorKey::class,
        ]);

        Event::listen(
            array_map(
                static fn(OrderStatusEnum $status): string => $status->event(),
                OrderStatusEnum::cases()
            ),
            LogOrderStatusChangeListener::class
        );
    }
}
