<?php

namespace Admnio\Sunset\Tests\Unit\QueuePause;

use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Tests\TestCase;

class QueuePausedEventTest extends TestCase
{
    public function test_constructor_assigns_all_fields_with_actor(): void
    {
        $event = new QueuePaused(
            connection: 'redis',
            queue: 'default',
            actor: 'dashboard',
        );

        $this->assertSame('redis', $event->connection);
        $this->assertSame('default', $event->queue);
        $this->assertSame('dashboard', $event->actor);
    }

    public function test_actor_defaults_to_null_when_omitted(): void
    {
        $event = new QueuePaused(
            connection: 'sqs',
            queue: 'emails',
        );

        $this->assertSame('sqs', $event->connection);
        $this->assertSame('emails', $event->queue);
        $this->assertNull($event->actor);
    }
}
