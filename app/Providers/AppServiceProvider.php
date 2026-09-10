<?php

namespace App\Providers;

use App\Domain\Factories\OrderContent\OrderContentFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

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

        Relation::enforceMorphMap(OrderContentFactory::morphMap());

    }
}
