<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonProcessRepositoryAdapter;
use Admnio\Sunset\Contracts\ProcessRepository as SunsetProcessRepo;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class HorizonProcessRepositoryAdapterTest extends TestCase
{
    public function test_all_orphans_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetProcessRepo::class);
        $repo->shouldReceive('allOrphans')->with('master-abc')->once()->andReturn(['pid-1' => 1000]);

        $adapter = new HorizonProcessRepositoryAdapter($repo);

        $this->assertSame(['pid-1' => 1000], $adapter->allOrphans('master-abc'));
    }

    public function test_orphaned_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetProcessRepo::class);
        $repo->shouldReceive('orphaned')->with('master-abc', ['pid-1'])->once()->andReturn(['pid-1']);

        $adapter = new HorizonProcessRepositoryAdapter($repo);

        $this->assertSame(['pid-1'], $adapter->orphaned('master-abc', ['pid-1']));
    }

    public function test_orphaned_for_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetProcessRepo::class);
        $repo->shouldReceive('orphanedFor')->with('master-abc', 30)->once()->andReturn(['pid-1']);

        $adapter = new HorizonProcessRepositoryAdapter($repo);

        $this->assertSame(['pid-1'], $adapter->orphanedFor('master-abc', 30));
    }

    public function test_forget_orphans_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetProcessRepo::class);
        $repo->shouldReceive('forgetOrphans')->with('master-abc', ['pid-1'])->once();

        $adapter = new HorizonProcessRepositoryAdapter($repo);
        $adapter->forgetOrphans('master-abc', ['pid-1']);
    }
}
