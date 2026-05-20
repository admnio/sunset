<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;

class RedisRoundtripTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean any leftover state in the test queue
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del('queues:sunset-redis-test');
        $conn->del('queues:sunset-redis-test:delayed');
        $conn->del('queues:sunset-redis-test:reserved');

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        // Use Redis as the default queue + a unique queue name
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'sunset-redis-test',
        ]);
    }

    public function test_push_pop_process_roundtrip_via_redis_driver(): void
    {
        Queue::push(new RecordingJob('hello-from-redis'));

        $job = Queue::connection('redis')->pop('sunset-redis-test');
        $this->assertNotNull($job, 'Expected to pop a job from sunset-redis-test');

        $job->fire();

        $this->assertSame(
            'hello-from-redis',
            file_get_contents(sys_get_temp_dir() . '/sunset-marker')
        );
    }
}
