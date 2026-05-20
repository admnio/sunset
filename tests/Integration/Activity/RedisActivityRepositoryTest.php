<?php

namespace Admnio\Sunset\Tests\Integration\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisActivityRepositoryTest extends IntegrationTestCase
{
    private RedisActivityRepository $repo;

    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // FLUSHDB-equivalent: wipe any leftover sunset:* keys from prior runs.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }

        // Pin the cap to a small number for easier assertions.
        config(['sunset.activity.stream_buffer_size' => 10]);

        $this->repo = new RedisActivityRepository($factory);
    }

    public function test_record_returns_event_with_real_id_and_preserves_fields(): void
    {
        $input = new ActivityEvent(
            id: 0,
            type: 'job_failed',
            occurredAt: 1_700_000_000,
            payload: ['job_id' => 'abc-123', 'queue' => 'default'],
        );

        $assigned = $this->repo->record($input);

        $this->assertGreaterThan(0, $assigned->id);
        $this->assertSame('job_failed', $assigned->type);
        $this->assertSame(1_700_000_000, $assigned->occurredAt);
        $this->assertSame(['job_id' => 'abc-123', 'queue' => 'default'], $assigned->payload);
    }

    public function test_consecutive_record_calls_assign_strictly_increasing_ids(): void
    {
        $a = $this->repo->record($this->makeEvent());
        $b = $this->repo->record($this->makeEvent());
        $c = $this->repo->record($this->makeEvent());

        $this->assertLessThan($b->id, $a->id);
        $this->assertLessThan($c->id, $b->id);
    }

    public function test_record_caps_sorted_set_to_stream_buffer_size(): void
    {
        // Cap is pinned to 10 in setUp().
        $cap = 10;

        for ($i = 0; $i < $cap + 5; $i++) {
            $this->repo->record($this->makeEvent(payload: ['n' => $i]));
        }

        $this->assertSame(
            $cap,
            (int) $this->redis->zcard('sunset:activity:stream'),
            'sorted set should be capped to stream_buffer_size after over-cap writes',
        );
    }

    public function test_recent_returns_newest_events_in_descending_id_order(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->repo->record($this->makeEvent(payload: ['n' => $i]));
        }

        $newest = $this->repo->recent(3);

        $this->assertCount(3, $newest);
        $this->assertSame($events[4]->id, $newest[0]->id);
        $this->assertSame($events[3]->id, $newest[1]->id);
        $this->assertSame($events[2]->id, $newest[2]->id);

        // Confirm strictly-descending.
        $ids = array_map(fn (ActivityEvent $e) => $e->id, $newest);
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_since_returns_events_strictly_after_cursor_ascending(): void
    {
        // Reset id counter so we have predictable ids 1..8 below.
        $events = [];
        for ($i = 0; $i < 8; $i++) {
            $events[] = $this->repo->record($this->makeEvent(payload: ['n' => $i]));
        }
        $fifthId = $events[4]->id;

        $after = $this->repo->since($fifthId, 100);

        // Should return ids > fifthId, ascending. id=fifthId itself NOT included.
        $returnedIds = array_map(fn (ActivityEvent $e) => $e->id, $after);

        $this->assertNotContains($fifthId, $returnedIds);
        foreach ($returnedIds as $id) {
            $this->assertGreaterThan($fifthId, $id);
        }

        // Confirm ascending.
        $sorted = $returnedIds;
        sort($sorted);
        $this->assertSame($sorted, $returnedIds);

        // Should be the 3 events with ids > fifthId.
        $this->assertSame([$events[5]->id, $events[6]->id, $events[7]->id], $returnedIds);
    }

    public function test_before_returns_events_strictly_before_cursor_descending(): void
    {
        $events = [];
        for ($i = 0; $i < 8; $i++) {
            $events[] = $this->repo->record($this->makeEvent(payload: ['n' => $i]));
        }
        $fifthId = $events[4]->id;

        $before = $this->repo->before($fifthId, 100);

        $returnedIds = array_map(fn (ActivityEvent $e) => $e->id, $before);

        // id=fifthId itself NOT included.
        $this->assertNotContains($fifthId, $returnedIds);
        foreach ($returnedIds as $id) {
            $this->assertLessThan($fifthId, $id);
        }

        // Confirm strictly-descending.
        $sorted = $returnedIds;
        rsort($sorted);
        $this->assertSame($sorted, $returnedIds);

        // Should be the 4 events with ids < fifthId, in descending order.
        $this->assertSame(
            [$events[3]->id, $events[2]->id, $events[1]->id, $events[0]->id],
            $returnedIds,
        );
    }

    public function test_sorted_set_has_positive_ttl_after_record(): void
    {
        $this->repo->record($this->makeEvent());

        $ttl = (int) $this->redis->ttl('sunset:activity:stream');

        $this->assertGreaterThan(0, $ttl, 'sorted set should have a positive TTL');
        $this->assertLessThanOrEqual(86400, $ttl);
    }

    public function test_recent_returns_empty_array_when_no_events_recorded(): void
    {
        $this->assertSame([], $this->repo->recent(50));
    }

    public function test_since_returns_empty_array_when_cursor_is_at_latest(): void
    {
        $last = $this->repo->record($this->makeEvent());

        $this->assertSame([], $this->repo->since($last->id, 100));
    }

    public function test_before_returns_empty_array_when_cursor_is_at_earliest(): void
    {
        $first = $this->repo->record($this->makeEvent());

        $this->assertSame([], $this->repo->before($first->id, 100));
    }

    private function makeEvent(
        string $type = 'job_failed',
        ?int $occurredAt = null,
        array $payload = ['job_id' => 'abc'],
    ): ActivityEvent {
        return new ActivityEvent(
            id: 0,
            type: $type,
            occurredAt: $occurredAt ?? 1_700_000_000,
            payload: $payload,
        );
    }
}
