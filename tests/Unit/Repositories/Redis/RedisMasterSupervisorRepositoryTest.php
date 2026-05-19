<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisMasterSupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisMasterSupervisorRepositoryTest extends TestCase
{
    private RedisMasterSupervisorRepository $repo;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');
        // Clean ANY sunset:* keys from prior runs
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
        $this->repo = new RedisMasterSupervisorRepository($factory);
    }

    public function test_names_returns_recently_updated_masters(): void
    {
        // Add a master with a recent timestamp
        $this->redis->zadd('sunset:masters', (float) CarbonImmutable::now()->getTimestamp(), 'master-1');

        $names = $this->repo->names();

        $this->assertContains('master-1', $names);
    }

    public function test_names_excludes_expired_masters(): void
    {
        // Add a master with an old timestamp (older than 14 seconds)
        $oldTimestamp = (float) CarbonImmutable::now()->subSeconds(20)->getTimestamp();
        $this->redis->zadd('sunset:masters', $oldTimestamp, 'old-master');

        $names = $this->repo->names();

        $this->assertNotContains('old-master', $names);
    }

    public function test_update_stores_master_hash_and_adds_to_set(): void
    {
        // MasterSupervisor doesn't exist yet (Task 13). Use Mockery to create
        // a runtime stand-in so we can test the repository without the real class.
        $master = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master->name = 'master-1';
        $master->environment = 'production';
        $master->working = true;
        $master->supervisors = collect([]);
        $master->shouldReceive('pid')->andReturn(1234);

        $this->repo->update($master);

        $hash = $this->redis->hgetall('sunset:master:master-1');
        $this->assertSame('master-1', $hash['name']);
        $this->assertSame('production', $hash['environment']);
        $this->assertSame('1234', $hash['pid']);
        $this->assertSame('running', $hash['status']);

        $score = $this->redis->zscore('sunset:masters', 'master-1');
        $this->assertNotNull($score);
    }

    public function test_update_sets_paused_status_when_not_working(): void
    {
        $master = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master->name = 'master-paused';
        $master->environment = 'local';
        $master->working = false;
        $master->supervisors = collect([]);
        $master->shouldReceive('pid')->andReturn(5678);

        $this->repo->update($master);

        $hash = $this->redis->hgetall('sunset:master:master-paused');
        $this->assertSame('paused', $hash['status']);
    }

    public function test_find_returns_master_by_name(): void
    {
        $master = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master->name = 'master-find';
        $master->environment = 'testing';
        $master->working = true;
        $master->supervisors = collect([]);
        $master->shouldReceive('pid')->andReturn(999);

        $this->repo->update($master);

        $found = $this->repo->find('master-find');

        $this->assertNotNull($found);
        $this->assertSame('master-find', $found['name']);
        $this->assertSame('testing', $found['environment']);
    }

    public function test_find_returns_null_for_missing_master(): void
    {
        $found = $this->repo->find('does-not-exist');
        $this->assertNull($found);
    }

    public function test_all_returns_array_of_all_active_masters(): void
    {
        $master1 = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master1->name = 'master-a';
        $master1->environment = 'production';
        $master1->working = true;
        $master1->supervisors = collect([]);
        $master1->shouldReceive('pid')->andReturn(100);

        $master2 = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master2->name = 'master-b';
        $master2->environment = 'production';
        $master2->working = true;
        $master2->supervisors = collect([]);
        $master2->shouldReceive('pid')->andReturn(200);

        $this->repo->update($master1);
        $this->repo->update($master2);

        $all = $this->repo->all();

        $this->assertCount(2, $all);
        $names = array_column($all, 'name');
        $this->assertContains('master-a', $names);
        $this->assertContains('master-b', $names);
    }

    public function test_forget_removes_master_from_hash_and_set(): void
    {
        $master = \Mockery::mock('Admnio\Sunset\Supervisor\MasterSupervisor');
        $master->name = 'master-forget';
        $master->environment = 'testing';
        $master->working = true;
        $master->supervisors = collect([]);
        $master->shouldReceive('pid')->andReturn(777);

        $this->repo->update($master);

        $this->repo->forget('master-forget');

        $this->assertNull($this->repo->find('master-forget'));
        $score = $this->redis->zscore('sunset:masters', 'master-forget');
        // phpredis returns false when member not found; predis returns null
        $this->assertFalse((bool) $score);
    }

    public function test_flush_expired_removes_old_masters(): void
    {
        $oldTimestamp = (float) CarbonImmutable::now()->subSeconds(20)->getTimestamp();
        $this->redis->zadd('sunset:masters', $oldTimestamp, 'expired-master');
        $this->redis->zadd('sunset:masters', (float) CarbonImmutable::now()->getTimestamp(), 'fresh-master');

        $this->repo->flushExpired();

        $remaining = $this->redis->zrange('sunset:masters', 0, -1);
        $this->assertNotContains('expired-master', $remaining);
        $this->assertContains('fresh-master', $remaining);
    }
}
