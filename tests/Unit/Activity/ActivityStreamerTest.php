<?php

namespace Admnio\Sunset\Tests\Unit\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Activity\ActivityStreamer;
use Admnio\Sunset\Contracts\ActivityRepository;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class ActivityStreamerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a streamer with a counter-based fake clock, a sleep stub that
     * advances the clock, and a list-collecting emit sink.
     *
     * The clock is a reference float; callers mutate it from outside the
     * streamer (or rely on the sleep stub to advance it) to control timing.
     * The emit closure pushes every frame it receives into the supplied list
     * so tests can assert on the exact sequence of writes to the SSE socket.
     *
     * @param  array<int, string>  $emitted  Out-param: collected frames.
     */
    private function makeStreamer(
        ActivityRepository $repository,
        float &$clock,
        array &$emitted,
        int $maxConnectionSeconds = 5,
        int $heartbeatIntervalSeconds = 15,
        int $pollIntervalSeconds = 1,
    ): ActivityStreamer {
        return new ActivityStreamer(
            repository: $repository,
            maxConnectionSeconds: $maxConnectionSeconds,
            heartbeatIntervalSeconds: $heartbeatIntervalSeconds,
            pollIntervalSeconds: $pollIntervalSeconds,
            clock: function () use (&$clock) {
                return $clock;
            },
            sleep: function (int $seconds) use (&$clock) {
                $clock += $seconds;
            },
            emit: function (string $frame) use (&$emitted) {
                $emitted[] = $frame;
            },
        );
    }

    public function test_replays_events_from_since_and_emits_frames_then_heartbeat(): void
    {
        // First poll returns two events; subsequent polls return nothing.
        // With pollInterval=1s, heartbeat=2s, max=5s:
        //   tick 0  → since(5,100) = [A,B] → emit two frames, lastHeartbeat=0
        //   tick 0  → no heartbeat yet
        //   sleep 1s → clock=1
        //   tick 1  → since(6,100) = []   → no emit; 1-0 < 2 → no heartbeat
        //   sleep 1s → clock=2
        //   tick 2  → since(6,100) = []   → no emit; 2-0 >= 2 → heartbeat, lastHeartbeat=2
        //   sleep 1s → clock=3
        //   tick 3  → since(6,100) = []   → no emit; 3-2 < 2 → no heartbeat
        //   sleep 1s → clock=4
        //   tick 4  → since(6,100) = []   → no emit; 4-2 >= 2 → heartbeat, lastHeartbeat=4
        //   sleep 1s → clock=5
        //   tick 5  → 5-0 >= 5 → exit
        $eventA = new ActivityEvent(id: 5, type: 'job_failed', occurredAt: 1000, payload: ['k' => 'a']);
        $eventB = new ActivityEvent(id: 6, type: 'job_completed', occurredAt: 1001, payload: ['k' => 'b']);

        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')
            ->with(5, 100)
            ->once()
            ->andReturn([$eventA, $eventB]);
        $repo->shouldReceive('since')
            ->with(6, 100)
            ->andReturn([]);

        $clock = 0.0;
        $emitted = [];

        $streamer = $this->makeStreamer(
            repository: $repo,
            clock: $clock,
            emitted: $emitted,
            maxConnectionSeconds: 5,
            heartbeatIntervalSeconds: 2,
            pollIntervalSeconds: 1,
        );

        $streamer->stream(5);

        // Two event frames, then two heartbeats.
        $this->assertCount(4, $emitted);
        $this->assertStringStartsWith("id: 5\nevent: job_failed\n", $emitted[0]);
        $this->assertStringStartsWith("id: 6\nevent: job_completed\n", $emitted[1]);
        $this->assertSame(":heartbeat\n\n", $emitted[2]);
        $this->assertSame(":heartbeat\n\n", $emitted[3]);
    }

    public function test_exits_cleanly_after_max_connection_seconds(): void
    {
        // Sleep advances the clock by pollInterval. With max=5, poll=1:
        //   tick 0 → poll → sleep(1) → tick 1 → ... → tick 5 → exit
        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')->andReturn([]);

        $clock = 0.0;
        $sleepCalls = 0;
        $emitted = [];

        $streamer = new ActivityStreamer(
            repository: $repo,
            maxConnectionSeconds: 5,
            heartbeatIntervalSeconds: 100, // Suppress heartbeats; we only care about loop count.
            pollIntervalSeconds: 1,
            clock: function () use (&$clock) {
                return $clock;
            },
            sleep: function (int $seconds) use (&$clock, &$sleepCalls) {
                $clock += $seconds;
                $sleepCalls++;
            },
            emit: function (string $frame) use (&$emitted) {
                $emitted[] = $frame;
            },
        );

        $streamer->stream(0);

        // The loop body runs at clock=0,1,2,3,4 (5 iterations), each followed
        // by sleep(1). After the 5th sleep the clock reads 5 and we exit
        // before another poll. So sleep is called 5 times and we never emit.
        $this->assertSame(5, $sleepCalls);
        $this->assertSame([], $emitted);
    }

    public function test_emits_heartbeat_after_heartbeat_interval_elapses(): void
    {
        // poll=1, heartbeat=3, max=10. No events ever. Expected heartbeats at
        // ticks 3, 6, 9 (each compares clock vs lastHeartbeat>=3).
        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')->andReturn([]);

        $clock = 0.0;
        $emitted = [];

        $streamer = $this->makeStreamer(
            repository: $repo,
            clock: $clock,
            emitted: $emitted,
            maxConnectionSeconds: 10,
            heartbeatIntervalSeconds: 3,
            pollIntervalSeconds: 1,
        );

        $streamer->stream(0);

        // Heartbeats at clock=3, 6, 9 → three of them. All identical comment lines.
        $this->assertCount(3, $emitted);
        foreach ($emitted as $frame) {
            $this->assertSame(":heartbeat\n\n", $frame);
        }
    }

    public function test_frame_format_matches_sse_spec(): void
    {
        $event = new ActivityEvent(
            id: 42,
            type: 'job_failed',
            occurredAt: 1_700_000_000,
            payload: ['foo' => 'bar'],
        );

        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')
            ->with(10, 100)
            ->once()
            ->andReturn([$event]);
        $repo->shouldReceive('since')
            ->with(42, 100)
            ->andReturn([]);

        $clock = 0.0;
        $emitted = [];

        $streamer = $this->makeStreamer(
            repository: $repo,
            clock: $clock,
            emitted: $emitted,
            maxConnectionSeconds: 1,
            heartbeatIntervalSeconds: 100,
            pollIntervalSeconds: 1,
        );

        $streamer->stream(10);

        $this->assertNotEmpty($emitted);
        $expectedData = json_encode([
            'id' => 42,
            'type' => 'job_failed',
            'occurred_at' => 1_700_000_000,
            'payload' => ['foo' => 'bar'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            "id: 42\nevent: job_failed\ndata: {$expectedData}\n\n",
            $emitted[0],
        );
    }

    public function test_null_last_event_id_uses_php_int_max_and_avoids_replay(): void
    {
        // First-connection-without-prior-page-render: cursor starts at
        // PHP_INT_MAX so since() returns nothing and we only forward new
        // events as they arrive (none in this test).
        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')
            ->with(PHP_INT_MAX, 100)
            ->andReturn([]);

        $clock = 0.0;
        $emitted = [];

        $streamer = $this->makeStreamer(
            repository: $repo,
            clock: $clock,
            emitted: $emitted,
            maxConnectionSeconds: 1,
            heartbeatIntervalSeconds: 5, // Won't fire inside max=1.
            pollIntervalSeconds: 1,
        );

        $streamer->stream(null);

        $this->assertSame([], $emitted);
    }

    public function test_cursor_advances_as_events_flow(): void
    {
        // Verify the cursor monotonically follows the last emitted event's id
        // and that we never re-query for an id we've already forwarded.
        $event7 = new ActivityEvent(id: 7, type: 'job_failed', occurredAt: 1000, payload: []);
        $event11 = new ActivityEvent(id: 11, type: 'job_completed', occurredAt: 1001, payload: []);

        $repo = Mockery::mock(ActivityRepository::class);
        $repo->shouldReceive('since')->with(5, 100)->once()->andReturn([$event7]);
        $repo->shouldReceive('since')->with(7, 100)->once()->andReturn([$event11]);
        $repo->shouldReceive('since')->with(11, 100)->andReturn([]);

        $clock = 0.0;
        $emitted = [];

        $streamer = $this->makeStreamer(
            repository: $repo,
            clock: $clock,
            emitted: $emitted,
            maxConnectionSeconds: 10,
            heartbeatIntervalSeconds: 100, // Suppress heartbeats.
            pollIntervalSeconds: 1,
        );

        $streamer->stream(5);

        // Mockery verifies the call sequence via the once() expectations on
        // since(5,...) and since(7,...). Assert the frames are in order too.
        $this->assertCount(2, $emitted);
        $this->assertStringStartsWith("id: 7\nevent: job_failed\n", $emitted[0]);
        $this->assertStringStartsWith("id: 11\nevent: job_completed\n", $emitted[1]);
    }
}
