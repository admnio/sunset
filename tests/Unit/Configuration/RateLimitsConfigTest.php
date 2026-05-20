<?php

namespace Admnio\Sunset\Tests\Unit\Configuration;

use Admnio\Sunset\Tests\TestCase;

class RateLimitsConfigTest extends TestCase
{
    public function test_rate_limits_block_present_with_documented_defaults(): void
    {
        $cfg = config('sunset.rate_limits');

        $this->assertIsArray($cfg);
        $this->assertFalse($cfg['count_releases_by_default']);
        $this->assertFalse($cfg['fail_closed']);
        $this->assertSame(60, $cfg['sweep_interval_seconds']);
    }
}
