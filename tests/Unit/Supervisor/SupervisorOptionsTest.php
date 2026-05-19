<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Tests\TestCase;

class SupervisorOptionsTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $options = new SupervisorOptions(
            name: 'sup-1',
            connection: 'sqs',
            queue: 'default',
            workersName: 'workers',
            balance: 'auto',
            backoff: 5,
            maxTime: 0,
            maxJobs: 0,
            maxProcesses: 10,
            minProcesses: 1,
            memory: 128,
            timeout: 60,
            sleep: 3,
            maxTries: 3,
            force: false,
            nice: 0,
            balanceCooldown: 3,
            balanceMaxShift: 1,
            parentId: 1234,
            rest: 0,
            autoScalingStrategy: 'time',
        );

        $this->assertSame('sup-1', $options->name);
        $this->assertSame('sqs', $options->connection);
        $this->assertSame('auto', $options->balance);
        $this->assertSame(10, $options->maxProcesses);
        $this->assertSame(1234, $options->parentId);
    }

    public function test_queue_defaults_to_connection_default_when_null(): void
    {
        config()->set('queue.connections.sqs.queue', 'fallback-queue');
        $options = new SupervisorOptions('sup-1', 'sqs');
        $this->assertSame('fallback-queue', $options->queue);
    }

    public function test_with_queue_returns_clone_with_new_queue(): void
    {
        $options = new SupervisorOptions('sup-1', 'sqs', 'original');
        $clone = $options->withQueue('new-queue');

        $this->assertSame('original', $options->queue);
        $this->assertSame('new-queue', $clone->queue);
        $this->assertNotSame($options, $clone);
    }

    public function test_balancing_is_true_for_simple_and_auto(): void
    {
        $this->assertFalse((new SupervisorOptions('s', 'sqs', balance: 'off'))->balancing());
        $this->assertTrue((new SupervisorOptions('s', 'sqs', balance: 'simple'))->balancing());
        $this->assertTrue((new SupervisorOptions('s', 'sqs', balance: 'auto'))->balancing());
    }

    public function test_auto_scaling_is_true_unless_balance_is_simple(): void
    {
        $this->assertTrue((new SupervisorOptions('s', 'sqs', balance: 'off'))->autoScaling());
        $this->assertFalse((new SupervisorOptions('s', 'sqs', balance: 'simple'))->autoScaling());
        $this->assertTrue((new SupervisorOptions('s', 'sqs', balance: 'auto'))->autoScaling());
    }

    public function test_auto_scale_by_number_of_jobs_when_strategy_is_size(): void
    {
        $time = new SupervisorOptions('s', 'sqs', autoScalingStrategy: 'time');
        $size = new SupervisorOptions('s', 'sqs', autoScalingStrategy: 'size');
        $this->assertFalse($time->autoScaleByNumberOfJobs());
        $this->assertTrue($size->autoScaleByNumberOfJobs());
    }

    public function test_to_array_includes_all_options(): void
    {
        $options = new SupervisorOptions(
            'sup-1', 'sqs', 'default', 'workers', 'auto', 5, 0, 0, 10, 1, 128, 60, 3, 3, false, 0, 3, 1, 1234, 0, 'time'
        );

        $array = $options->toArray();
        $this->assertSame('sup-1', $array['name']);
        $this->assertSame('sqs', $array['connection']);
        $this->assertSame('auto', $array['balance']);
        $this->assertSame(10, $array['maxProcesses']);
        $this->assertSame(1234, $array['parentId']);
        $this->assertSame('time', $array['autoScalingStrategy']);
    }

    public function test_from_array_round_trips(): void
    {
        $original = new SupervisorOptions('sup-1', 'sqs', 'default', balance: 'auto', maxProcesses: 5);
        $restored = SupervisorOptions::fromArray($original->toArray());

        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->connection, $restored->connection);
        $this->assertSame($original->balance, $restored->balance);
        $this->assertSame($original->maxProcesses, $restored->maxProcesses);
    }
}
