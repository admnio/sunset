<?php

namespace MasonWorkforce\HorizonSqs;

use Illuminate\Support\ServiceProvider;

class HorizonSqsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/horizon-sqs.php', 'horizon-sqs');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/horizon-sqs.php' => config_path('horizon-sqs.php'),
            ], 'horizon-sqs-config');
        }
    }
}
