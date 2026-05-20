<?php

namespace Admnio\Sunset\Tests\Unit\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Tests\TestCase;

class ActivityEventTest extends TestCase
{
    public function test_constructor_assigns_all_fields(): void
    {
        $event = new ActivityEvent(
            id: 42,
            type: 'job_failed',
            occurredAt: 1_700_000_000,
            payload: ['job_id' => 'abc-123', 'queue' => 'default'],
        );

        $this->assertSame(42, $event->id);
        $this->assertSame('job_failed', $event->type);
        $this->assertSame(1_700_000_000, $event->occurredAt);
        $this->assertSame(['job_id' => 'abc-123', 'queue' => 'default'], $event->payload);
    }

    public function test_to_array_returns_snake_case_keys(): void
    {
        $event = new ActivityEvent(
            id: 7,
            type: 'job_completed',
            occurredAt: 1_700_000_050,
            payload: ['job_id' => 'c-1'],
        );

        $this->assertSame([
            'id' => 7,
            'type' => 'job_completed',
            'occurred_at' => 1_700_000_050,
            'payload' => ['job_id' => 'c-1'],
        ], $event->toArray());
    }

    public function test_from_array_round_trips(): void
    {
        $original = new ActivityEvent(
            id: 99,
            type: 'master_supervisor_deployed',
            occurredAt: 1_700_000_500,
            payload: ['master_name' => 'master-abc'],
        );

        $roundTripped = ActivityEvent::fromArray($original->toArray());

        $this->assertEquals($original, $roundTripped);
    }

    public function test_from_array_coerces_string_id_and_occurred_at_to_int(): void
    {
        // Redis sometimes returns numeric fields as strings; fromArray must coerce.
        $event = ActivityEvent::fromArray([
            'id' => '123',
            'type' => 'job_failed',
            'occurred_at' => '1700000000',
            'payload' => ['job_id' => 'x'],
        ]);

        $this->assertSame(123, $event->id);
        $this->assertSame(1_700_000_000, $event->occurredAt);
        $this->assertSame('job_failed', $event->type);
        $this->assertSame(['job_id' => 'x'], $event->payload);
    }

    public function test_from_array_treats_missing_payload_as_empty_array(): void
    {
        $event = ActivityEvent::fromArray([
            'id' => 1,
            'type' => 'long_wait_detected',
            'occurred_at' => 1,
        ]);

        $this->assertSame([], $event->payload);
    }

    public function test_to_json_returns_valid_json(): void
    {
        $event = new ActivityEvent(
            id: 1,
            type: 'job_failed',
            occurredAt: 1_700_000_000,
            payload: ['job_id' => 'abc'],
        );

        $decoded = json_decode($event->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['id']);
        $this->assertSame('job_failed', $decoded['type']);
        $this->assertSame(1_700_000_000, $decoded['occurred_at']);
        $this->assertSame(['job_id' => 'abc'], $decoded['payload']);
    }

    public function test_from_json_round_trips_to_json(): void
    {
        $original = new ActivityEvent(
            id: 256,
            type: 'job_rate_limited',
            occurredAt: 1_700_001_234,
            payload: ['queue' => 'default', 'limit_name' => 'concurrency:foo'],
        );

        $roundTripped = ActivityEvent::fromJson($original->toJson());

        $this->assertEquals($original, $roundTripped);
    }
}
