<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisProcessRepository;
use Admnio\Sunset\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisProcessRepositoryTest extends TestCase
{
    private RedisProcessRepository $repo;
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
        $this->repo = new RedisProcessRepository($factory);
    }

    public function test_orphaned_records_process_ids_for_master(): void
    {
        $this->repo->orphaned('master-1', ['1001', '1002', '1003']);

        $orphans = $this->repo->allOrphans('master-1');

        $this->assertArrayHasKey('1001', $orphans);
        $this->assertArrayHasKey('1002', $orphans);
        $this->assertArrayHasKey('1003', $orphans);
    }

    public function test_orphaned_removes_stale_pids_no_longer_in_list(): void
    {
        // First call: record PIDs 1001, 1002
        $this->repo->orphaned('master-1', ['1001', '1002']);

        // Second call: only PID 1002 is still orphaned; 1001 is no longer orphaned
        $this->repo->orphaned('master-1', ['1002']);

        $orphans = $this->repo->allOrphans('master-1');

        $this->assertArrayNotHasKey('1001', $orphans);
        $this->assertArrayHasKey('1002', $orphans);
    }

    public function test_all_orphans_returns_hash_of_pid_to_timestamp(): void
    {
        $this->repo->orphaned('master-2', ['2001', '2002']);

        $orphans = $this->repo->allOrphans('master-2');

        $this->assertIsArray($orphans);
        $this->assertCount(2, $orphans);
        // Values should be timestamps (numeric strings)
        foreach ($orphans as $pid => $timestamp) {
            $this->assertIsNumeric($timestamp);
        }
    }

    public function test_orphaned_for_returns_pids_older_than_threshold(): void
    {
        $master = 'master-orphan-for';
        $now = CarbonImmutable::now()->getTimestamp();

        // Directly write one "old" PID and one "new" PID into the hash
        $this->redis->hmset("sunset:supervisor:{$master}:orphans", [
            'old-pid' => (string) ($now - 120),  // 2 minutes old
            'new-pid' => (string) $now,           // just now
        ]);

        $oldPids = $this->repo->orphanedFor($master, 60);

        $this->assertContains('old-pid', $oldPids);
        $this->assertNotContains('new-pid', $oldPids);
    }

    public function test_forget_orphans_removes_specific_pids(): void
    {
        $this->repo->orphaned('master-3', ['3001', '3002', '3003']);

        $this->repo->forgetOrphans('master-3', ['3001', '3003']);

        $orphans = $this->repo->allOrphans('master-3');

        $this->assertArrayNotHasKey('3001', $orphans);
        $this->assertArrayNotHasKey('3003', $orphans);
        $this->assertArrayHasKey('3002', $orphans);
    }

    public function test_all_orphans_returns_empty_array_for_unknown_master(): void
    {
        $orphans = $this->repo->allOrphans('unknown-master');
        $this->assertIsArray($orphans);
        $this->assertEmpty($orphans);
    }
}
