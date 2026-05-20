<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\Contracts\Limiter;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Admnio\Sunset\Tests\TestCase;

class SunsetServiceProviderBindingTest extends TestCase
{
    public function test_LimitRegistry_is_bound_as_singleton(): void
    {
        $a = $this->app->make(LimitRegistry::class);
        $b = $this->app->make(LimitRegistry::class);
        $this->assertSame($a, $b);
    }

    public function test_Limiter_resolves_to_RedisLimiter(): void
    {
        $this->assertInstanceOf(\Admnio\Sunset\RateLimiting\RedisLimiter::class, $this->app->make(Limiter::class));
    }

    public function test_RateLimitGate_is_bound_as_singleton(): void
    {
        $a = $this->app->make(RateLimitGate::class);
        $b = $this->app->make(RateLimitGate::class);
        $this->assertSame($a, $b);
    }
}
