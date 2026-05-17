<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit;

use MasonWorkforce\HorizonSqs\Tests\TestCase;

class HorizonSqsServiceProviderTest extends TestCase
{
    public function test_publishes_config(): void
    {
        $this->assertSame('default', config('horizon-sqs.redis_connection'));
        $this->assertSame(5, config('horizon-sqs.workload_cache_ttl'));
    }
}
