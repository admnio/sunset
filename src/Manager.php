<?php

namespace Admnio\Sunset;

use Admnio\Sunset\RateLimiting\LimitBuilder;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Illuminate\Contracts\Container\Container;

/**
 * Singleton that backs the Sunset facade. Public surface added in subsequent
 * minor releases:
 *   - v0.7.0: for() and limit() return LimitBuilder for rate-limit declarations.
 *   - v0.8.0 (this release): auth() registers the dashboard gate; check() evaluates it.
 */
class Manager
{
    /** @var \Closure|null */
    private static $authCallback = null;

    public function __construct(private Container $container)
    {
    }

    public function for(string $queueName): LimitBuilder
    {
        return new LimitBuilder(
            $this->container->make(LimitRegistry::class),
            new QueueTarget($queueName),
            (array) $this->container['config']->get('sunset.rate_limits', []),
        );
    }

    public function limit(string $jobClass): LimitBuilder
    {
        return new LimitBuilder(
            $this->container->make(LimitRegistry::class),
            new JobClassTarget($jobClass),
            (array) $this->container['config']->get('sunset.rate_limits', []),
        );
    }

    public function auth(\Closure $callback): void
    {
        self::$authCallback = $callback;
    }

    public function check($request): bool
    {
        if (self::$authCallback) {
            return (bool) (self::$authCallback)($request);
        }
        if ($this->container['config']->get('app.env') === 'local') {
            return true;
        }
        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    public static function flushAuth(): void
    {
        self::$authCallback = null;
    }
}
