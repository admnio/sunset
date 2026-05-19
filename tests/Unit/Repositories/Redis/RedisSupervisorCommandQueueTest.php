<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisSupervisorCommandQueue;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisSupervisorCommandQueueTest extends TestCase
{
    private RedisSupervisorCommandQueue $queue;
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
        $this->queue = new RedisSupervisorCommandQueue($factory);
    }

    public function test_push_appends_json_encoded_command_to_list(): void
    {
        $this->queue->push('supervisor-1', 'Admnio\Sunset\SupervisorCommands\Pause', ['option' => 'value']);

        $raw = $this->redis->lrange('sunset:commands:supervisor-1', 0, -1);
        $this->assertCount(1, $raw);

        $decoded = json_decode($raw[0], true);
        $this->assertSame('Admnio\Sunset\SupervisorCommands\Pause', $decoded['command']);
        $this->assertSame(['option' => 'value'], $decoded['options']);
    }

    public function test_pending_returns_all_commands_and_clears_queue(): void
    {
        $this->queue->push('supervisor-2', 'Admnio\Sunset\SupervisorCommands\Pause', []);
        $this->queue->push('supervisor-2', 'Admnio\Sunset\SupervisorCommands\ContinueWorking', []);

        $pending = $this->queue->pending('supervisor-2');

        $this->assertCount(2, $pending);
        $this->assertSame('Admnio\Sunset\SupervisorCommands\Pause', $pending[0]->command);
        $this->assertSame('Admnio\Sunset\SupervisorCommands\ContinueWorking', $pending[1]->command);

        // Queue should be empty after pending()
        $remaining = $this->redis->llen('sunset:commands:supervisor-2');
        $this->assertSame(0, $remaining);
    }

    public function test_pending_returns_empty_array_when_no_commands(): void
    {
        $pending = $this->queue->pending('empty-supervisor');
        $this->assertSame([], $pending);
    }

    public function test_flush_clears_the_command_queue(): void
    {
        $this->queue->push('supervisor-3', 'Admnio\Sunset\SupervisorCommands\Terminate', []);
        $this->queue->push('supervisor-3', 'Admnio\Sunset\SupervisorCommands\Terminate', []);

        $this->queue->flush('supervisor-3');

        $remaining = $this->redis->llen('sunset:commands:supervisor-3');
        $this->assertSame(0, $remaining);
    }

    public function test_push_with_default_empty_options(): void
    {
        $this->queue->push('supervisor-4', 'Admnio\Sunset\SupervisorCommands\Restart');

        $pending = $this->queue->pending('supervisor-4');
        $this->assertCount(1, $pending);
        $this->assertIsArray($pending[0]->options);
        $this->assertEmpty($pending[0]->options);
    }
}
