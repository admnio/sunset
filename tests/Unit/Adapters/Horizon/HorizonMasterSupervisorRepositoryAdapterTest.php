<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonMasterSupervisorRepositoryAdapter;
use Admnio\Sunset\Contracts\MasterSupervisorRepository as SunsetMasterRepo;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use RuntimeException;

class HorizonMasterSupervisorRepositoryAdapterTest extends TestCase
{
    public function test_names_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('names')->once()->andReturn(['master-abc']);

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);

        $this->assertSame(['master-abc'], $adapter->names());
    }

    public function test_all_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('all')->once()->andReturn([['name' => 'master-abc']]);

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);

        $this->assertSame([['name' => 'master-abc']], $adapter->all());
    }

    public function test_find_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('find')->with('master-abc')->once()->andReturn(['name' => 'master-abc']);

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);

        $this->assertSame(['name' => 'master-abc'], $adapter->find('master-abc'));
    }

    public function test_get_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('get')->with(['master-abc'])->once()->andReturn([['name' => 'master-abc']]);

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);

        $this->assertSame([['name' => 'master-abc']], $adapter->get(['master-abc']));
    }

    public function test_forget_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('forget')->with('master-abc')->once();

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);
        $adapter->forget('master-abc');
    }

    public function test_flush_expired_delegates_to_sunset_repo(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $repo->shouldReceive('flushExpired')->once();

        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);
        $adapter->flushExpired();
    }

    public function test_update_throws_runtime_exception(): void
    {
        $repo = Mockery::mock(SunsetMasterRepo::class);
        $adapter = new HorizonMasterSupervisorRepositoryAdapter($repo);

        // update() takes a Horizon MasterSupervisor but our repo expects Sunset's type.
        // Sunset's supervisor calls our repo directly; this adapter path is never used.
        $this->expectException(RuntimeException::class);

        $horizonMaster = Mockery::mock(\Laravel\Horizon\MasterSupervisor::class);
        $adapter->update($horizonMaster);
    }
}
