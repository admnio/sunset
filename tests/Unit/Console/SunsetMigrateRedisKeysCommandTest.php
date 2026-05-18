<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Mockery;

class SunsetMigrateRedisKeysCommandTest extends TestCase
{
    public function test_renames_old_key_to_new_when_new_is_absent(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('exists')->with('horizon-sqs:delayed')->andReturn(1);
        $conn->shouldReceive('exists')->with('sunset:delayed')->andReturn(0);
        $conn->shouldReceive('zcard')->with('horizon-sqs:delayed')->andReturn(3);
        $conn->shouldReceive('rename')->with('horizon-sqs:delayed', 'sunset:delayed')->once()->andReturn('OK');
        $conn->shouldReceive('zcard')->with('sunset:delayed')->andReturn(3);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);
        $this->app->instance(RedisFactory::class, $factory);

        $this->artisan('sunset:migrate-redis-keys')
            ->expectsOutputToContain('horizon-sqs:delayed → sunset:delayed (renamed')
            ->assertSuccessful();
    }

    public function test_noop_when_old_key_missing(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('exists')->with('horizon-sqs:delayed')->andReturn(0);
        $conn->shouldNotReceive('rename');

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);
        $this->app->instance(RedisFactory::class, $factory);

        $this->artisan('sunset:migrate-redis-keys')
            ->expectsOutputToContain('no migration needed')
            ->assertSuccessful();
    }

    public function test_aborts_when_new_key_already_populated(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('exists')->with('horizon-sqs:delayed')->andReturn(1);
        $conn->shouldReceive('exists')->with('sunset:delayed')->andReturn(1);
        $conn->shouldReceive('zcard')->with('horizon-sqs:delayed')->andReturn(2);
        $conn->shouldReceive('zcard')->with('sunset:delayed')->andReturn(5);
        $conn->shouldNotReceive('rename');

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->andReturn($conn);
        $this->app->instance(RedisFactory::class, $factory);

        $this->artisan('sunset:migrate-redis-keys')
            ->expectsOutputToContain('refusing to overwrite')
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
