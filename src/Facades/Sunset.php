<?php

namespace Admnio\Sunset\Facades;

use Admnio\Sunset\Manager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Admnio\Sunset\RateLimiting\LimitBuilder for(string $queueName)  Declare a rate limit targeting a queue
 * @method static \Admnio\Sunset\RateLimiting\LimitBuilder limit(string $jobClass) Declare a rate limit targeting a job class
 * @method static void auth(\Closure $callback)  Register the dashboard auth gate
 *
 * @see \Admnio\Sunset\Manager
 */
class Sunset extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
