<?php

namespace Admnio\Sunset\Activity;

/**
 * Immutable activity-stream event.
 *
 * Public value object. Each event the dashboard records — job lifecycle
 * transitions, supervisor deployments, long-wait detections, etc. — is
 * represented as one of these. The on-the-wire shape (JSON in the Redis
 * sorted set, JSON to the dashboard SSE stream) matches toArray()'s
 * snake_case keys exactly.
 *
 * Consumers reading via Admnio\Sunset\Contracts\ActivityRepository receive
 * instances of this DTO. Consumers subscribing to Admnio\Sunset\Events\
 * ActivityRecorded also receive instances of this DTO.
 */
final readonly class ActivityEvent
{
    /**
     * @param int    $id         Monotonic id assigned by the recorder (INCR-based).
     *                           Factory output uses 0 as a placeholder; the recorder
     *                           rewrites the event with the real id after INCR.
     * @param string $type       Snake_case event type (e.g. 'job_failed',
     *                           'worker_process_restarting').
     * @param int    $occurredAt Unix timestamp in seconds.
     * @param array  $payload    Flat, JSON-serializable payload. Shape depends on
     *                           $type; the dashboard interprets known shapes and
     *                           falls back to a generic pretty-print for unknowns.
     */
    public function __construct(
        public int $id,
        public string $type,
        public int $occurredAt,
        public array $payload,
    ) {
    }

    /**
     * @return array{id: int, type: string, occurred_at: int, payload: array}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }

    /**
     * Hydrate from the snake_case array shape produced by toArray().
     *
     * Numeric fields are coerced from strings — Redis returns integers as
     * strings through some client paths (ZRANGEBYSCORE WITHSCORES, HGETALL).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            type: (string) ($data['type'] ?? ''),
            occurredAt: (int) ($data['occurred_at'] ?? 0),
            payload: $payload,
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        return self::fromArray(is_array($decoded) ? $decoded : []);
    }
}
