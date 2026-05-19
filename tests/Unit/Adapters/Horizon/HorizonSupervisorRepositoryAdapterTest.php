<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonSupervisorRepositoryAdapter;
use Admnio\Sunset\Contracts\SupervisorRepository as SunsetSupervisorRepo;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use RuntimeException;

class HorizonSupervisorRepositoryAdapterTest extends TestCase
{
    public function test_names_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('names')->once()->andReturn(['supervisor-1']);

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        $this->assertSame(['supervisor-1'], $adapter->names());
    }

    public function test_all_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('all')->once()->andReturn([['name' => 'supervisor-1']]);

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        $this->assertSame([['name' => 'supervisor-1']], $adapter->all());
    }

    public function test_find_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('find')->with('supervisor-1')->once()->andReturn(['name' => 'supervisor-1']);

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        $this->assertSame(['name' => 'supervisor-1'], $adapter->find('supervisor-1'));
    }

    public function test_get_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('get')->with(['supervisor-1'])->once()->andReturn([['name' => 'supervisor-1']]);

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        $this->assertSame([['name' => 'supervisor-1']], $adapter->get(['supervisor-1']));
    }

    public function test_longest_active_timeout_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('longestActiveTimeout')->once()->andReturn(60);

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        $this->assertSame(60, $adapter->longestActiveTimeout());
    }

    public function test_forget_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('forget')->with(['supervisor-1'])->once();

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);
        $adapter->forget(['supervisor-1']);
    }

    public function test_flush_expired_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $repo->shouldReceive('flushExpired')->once();

        $adapter = new HorizonSupervisorRepositoryAdapter($repo);
        $adapter->flushExpired();
    }

    public function test_update_throws_runtime_exception(): void
    {
        $repo = Mockery::mock(SunsetSupervisorRepo::class);
        $adapter = new HorizonSupervisorRepositoryAdapter($repo);

        // update() takes a Horizon Supervisor but our repo expects Sunset's type.
        // Sunset's supervisor calls our repo directly; this adapter path is never used.
        $this->expectException(RuntimeException::class);

        $horizonSupervisor = Mockery::mock(\Laravel\Horizon\Supervisor::class);
        $adapter->update($horizonSupervisor);
    }
}
