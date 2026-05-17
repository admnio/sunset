# Horizon SQS Driver — Design

**Date:** 2026-05-17
**Status:** Draft (awaiting user review)
**Package:** `masonworkforce/horizon-sqs`

## Goal

A Composer package that makes Laravel Horizon's dashboard fully functional when the underlying queue transport is Amazon SQS instead of Redis. Same dashboard, same metrics, same UX — SQS underneath.

## Non-Goals

- Replacing Horizon's dashboard UI.
- Eliminating Redis entirely. Redis remains as the **stats sidecar** for Horizon's existing repositories. SQS is the job transport; Redis stores Horizon's metadata.
- Building parity with a non-Horizon admin UI.

## Constraints & Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Transport | SQS (Standard + FIFO) | User requirement. |
| Stats storage | Redis (existing Horizon repositories, unchanged) | SQS exposes nothing usable for per-job metrics; reusing Horizon's Redis repos avoids reimplementing them and keeps dashboard compatibility free. |
| Laravel target | 10, 11, 12 | Broad compatibility per user. |
| PHP target | 8.2+ | Matches Laravel 10 floor. |
| Horizon target | 5.x | Current major. |
| Large payloads | S3 spill-over (>256 KB) | Optional, opt-in via config. |
| Long delays | Redis-buffered sweep | SQS native max is 900 s. |
| FIFO | Supported with configurable group/dedup strategies | User requested full parity. |

## Architecture (Approach A)

We add a custom SQS queue connector and override **one** Horizon repository binding. Everything else in Horizon — including its Redis-backed `JobRepository`, `MetricsRepository`, `TagRepository`, `ProcessRepository`, `SupervisorRepository` — is reused unchanged. Those repositories react to Laravel's standard queue events (`JobPushed`, `JobProcessing`, `JobProcessed`, `JobFailed`, `JobReleased`), which fire from Laravel's worker regardless of the underlying driver.

The custom connector ensures the right metadata is in the payload (`id`, `pushedAt`, `tags`) so Horizon's listeners record everything correctly. The repository we override is `WorkloadRepository`, which queries SQS for queue depth and uses Horizon's own MetricsRepository to estimate wait time.

```
┌─────────────────────┐         ┌────────────────────────┐
│   Application code  │─push──▶ │ HorizonSqsQueue        │──▶ AWS SQS
└─────────────────────┘         │  (extends SqsQueue)    │
                                │  + PayloadEnricher     │
                                │  + ExtendedPayload     │
                                │  + Long-delay buffer   │
                                └─────────┬──────────────┘
                                          │ fires Laravel queue events
                                          ▼
                                ┌────────────────────────┐
                                │ Horizon's existing      │
                                │ Redis-backed repos      │──▶ Redis (sidecar)
                                │ (JobRepo, MetricsRepo,  │
                                │  TagRepo, etc.)         │
                                └────────────────────────┘
                                          ▲
                                          │  reads
                  Horizon dashboard ──────┤
                                          │
                                ┌────────────────────────┐
                                │ SqsWorkloadRepository   │──▶ SQS GetQueueAttributes
                                │  (the only override)    │      (cached 5s)
                                └────────────────────────┘
```

## Package Layout

```
src/
  HorizonSqsServiceProvider.php
  Queue/
    HorizonSqsConnector.php
    HorizonSqsQueue.php
    Payload/
      PayloadEnricher.php
      ExtendedPayloadHandler.php
    Delay/
      DelayedJobReenqueuer.php
  Repositories/
    SqsWorkloadRepository.php
  Console/
    PurgeDelayedCommand.php
config/
  horizon-sqs.php
tests/
  Unit/
  Integration/
  Fixtures/test-app/
docker-compose.yml             # LocalStack + Redis for integration tests
```

## Components

### `HorizonSqsServiceProvider`
Wiring only. In `register()`:
- Merge `config/horizon-sqs.php`.
- Bind `SqsWorkloadRepository` to `Laravel\Horizon\Contracts\WorkloadRepository`.
- Validate config (see Error Handling).

In `boot()`:
- Call `Queue::extend('sqs', fn () => new HorizonSqsConnector(...))` to register our driver in place of Laravel's stock SQS connector.
- Register scheduled task: `DelayedJobReenqueuer` every `config('horizon-sqs.long_delay_sweep_interval')` seconds.

### `HorizonSqsConnector implements Illuminate\Queue\Connectors\ConnectorInterface`
Returns `new HorizonSqsQueue($sqsClient, $config['queue'], $config['prefix'] ?? '', $config['suffix'] ?? '', $config)`.

### `HorizonSqsQueue extends Illuminate\Queue\SqsQueue`
Overrides:
- `createPayload($job, $queue, $data = '')`: calls `PayloadEnricher::enrich()` on the array form before JSON-encoding.
- `pushRaw($payload, $queue = null, array $options = [])`:
  - If `strlen($payload) > 256 * 1024` and extended payloads enabled → `ExtendedPayloadHandler::store()` returns a pointer payload; otherwise use `$payload` as-is.
  - If FIFO (queue suffix `.fifo`) → derive `MessageGroupId` and `MessageDeduplicationId` from config + payload + `$options`.
  - If `$options['delay']` > `sqs_max_delay` → push to Redis sorted set instead of SQS; return synthetic ID.
- `pop($queue = null)`:
  - Long-poll SQS (`WaitTimeSeconds=20` default, configurable).
  - If body has `s3PointerKey` → fetch from S3 and reconstruct payload.
  - Return a standard `SqsJob` so Laravel's worker handles it normally.

No knowledge of Horizon repositories or events — Laravel's worker fires those itself.

### `PayloadEnricher`
Pure transformer:
```php
public function enrich(array $payload, string $queue): array
{
    return $payload + [
        'id' => (string) Str::uuid(),
        'pushedAt' => microtime(true),
        'tags' => array_values(array_unique(array_merge(
            $payload['tags'] ?? [],
            $this->autoTagger->tagsFor($payload)  // re-uses Horizon's Tags helper
        ))),
        '_horizon_nonce' => bin2hex(random_bytes(8)),  // for FIFO content-based dedup
    ];
}
```

### `ExtendedPayloadHandler`
- `store(string $payload): string` — writes to `s3://{bucket}/{prefix}/{uuid}` and returns the JSON pointer message `{"s3PointerKey":"...","size":N}`.
- `fetch(string $pointer): string` — reads from S3.
- `delete(string $pointer): void` — best-effort delete on successful processing.

### `DelayedJobReenqueuer`
- Scheduled command (`horizon-sqs:sweep-delayed`).
- Each tick: `ZRANGEBYSCORE horizon-sqs:delayed 0 (now + sweep_interval) WITHSCORES`.
- For each entry: push to SQS with `DelaySeconds = max(0, score - now)`, then `ZREM` only on success.
- Partial-failure resilient: failed entries remain in the set for next tick.

### `SqsWorkloadRepository implements Laravel\Horizon\Contracts\WorkloadRepository`
- `get(): array` — returns `[ ['name' => $queue, 'length' => N, 'wait' => seconds, 'processes' => P, 'split' => null], ... ]`.
- Reads queue list from `config('horizon.environments.{env}.{supervisor}.queue')`.
- For each queue: `GetQueueAttributes(['ApproximateNumberOfMessages','ApproximateNumberOfMessagesNotVisible'])`. Results merged.
- Concurrent fetch via SDK promises, awaited together.
- Cache results in Laravel cache for `workload_cache_ttl` seconds (default 5).
- `wait` = `length × avgRuntime / max(1, processes)` where `avgRuntime` comes from `MetricsRepository::runtimeForQueue($queue)` and `processes` from `ProcessRepository`.

## Data Flow

### Push
1. `Queue::push($job)` → `HorizonSqsQueue::createPayload()` → enriched array with `id`, `pushedAt`, `tags`, `_horizon_nonce`.
2. `pushRaw($json)`:
   - >256KB & enabled → S3 spill, body becomes pointer.
   - FIFO → set group/dedup IDs.
   - delay > 900 → Redis sorted set; return.
   - else → `SqsClient::sendMessage`.
3. Laravel fires `JobQueued`/`JobQueueing` → Horizon's `JobRepository::pushed()` records the job in Redis using `id`.

### Pop & process
1. `queue:work sqs` → `HorizonSqsQueue::pop()` (long-poll 20s).
2. Pointer payload → S3 fetch.
3. `JobProcessing` fires → Horizon updates wait-time EMA using `now() − pushedAt`.
4. Job runs.
5. Success: `JobProcessed` → MetricsRepository records runtime; SQS `DeleteMessage`; (if extended) S3 delete (best-effort).
6. Failure: `JobFailed` → exception + payload stored in Redis `failed` list; SQS `DeleteMessage` (Laravel's failed-jobs table is source of truth for retry).
7. Release: `Job::release($delay)` → SQS `ChangeMessageVisibility`; `JobReleased` increments retry counter.

### Workload page read
1. Dashboard polls `/horizon/api/workload` every 3s.
2. `SqsWorkloadRepository::get()` returns cached or fresh data per queue.
3. Cache TTL prevents AWS API thrashing under multi-viewer load.

### Retry from dashboard
1. User clicks Retry → Horizon's existing controller reads original payload from Redis JobRepository.
2. Re-dispatches via `Queue::connection('sqs')->pushRaw($payload, $queue)`.
3. New job gets new `id`; failure record retains the original `id` for audit.

### Long-delay sweep
1. Every 60s by default.
2. Pushes any job whose ETA is within the next sweep interval, with `DelaySeconds` = remaining time (capped at 900).
3. Removes from sorted set only on successful SQS push.

## Error Handling

| Scenario | Behavior |
|---|---|
| SQS API transient error | SDK retries (3 attempts, exponential backoff with jitter). On push exhaustion: throw `HorizonSqsPushException`. On pop exhaustion: log, return null, worker sleeps. |
| Redis sidecar unavailable | Never blocks dispatch or job processing. JobPushed/Processed listener failures are logged; dashboard shows existing Horizon connection-failed banner. SQS is source of truth. |
| Duplicate SQS delivery (at-least-once) | Application responsibility (same as stock SQS driver). Documented. |
| Job exceeds visibility timeout | Documented advice: set visibility ≥ job timeout × 1.5. Optional `visibility_heartbeat` config extends visibility every `timeout/3` while job runs. No-ops if pcntl unavailable. |
| FIFO dedup collision | `_horizon_nonce` prevents accidental collisions; users can set `$job->deduplicationId` for explicit control. |
| S3 put fails on push | Throw `ExtendedPayloadException`; no SQS message sent. |
| S3 get fails on pop | Release back to SQS with backoff up to N times, then DLQ. |
| S3 orphan after worker death | Documented: configure S3 lifecycle rule on the prefix (default 7 days). |
| Long-delay sweep failure | Per-entry try/catch; failed entries retried next tick. Sweep gap = bounded by scheduler reliability; jobs are never lost. |
| Dashboard polling thrashes SQS | 5s cache on `GetQueueAttributes`; promise-based concurrent fetch across queues. |
| Misconfiguration | ServiceProvider boot validates: redis_connection exists, S3 bucket set if extended_payload enabled, FIFO queues end in `.fifo`. Throws `InvalidConfigurationException` with actionable message. |

## Testing Strategy

### Unit (no AWS, no Redis server)
- `PayloadEnricher` — table-driven cases for tag extraction, pushedAt format, id uniqueness.
- `ExtendedPayloadHandler` — AWS SDK `MockHandler`. Size threshold, pointer format, error mapping.
- `DelayedJobReenqueuer` — in-memory Redis + mocked SQS. ZRANGE/ZREM correctness, partial-failure resilience.
- `SqsWorkloadRepository` — mocked SQS, cache TTL, batch behavior.
- `HorizonSqsQueue` — `createPayload`/`pushRaw`/`pop` against AWS SDK MockHandler.

### Integration (LocalStack via Docker)
- `docker-compose.yml`: `localstack/localstack:latest` (SQS, S3) + `redis:7`.
- Fixture Laravel app under `tests/Fixtures/test-app/` boots Horizon + this package.
- Scenarios: push→pop→process roundtrip; FIFO ordering preserved within group; extended-payload roundtrip; long-delay sweep across a fake clock; visibility-timeout extension; SQS throttle via LocalStack error injection.

### Dashboard (HTTP-level)
- Boot Horizon's routes; hit `/horizon/api/workload`, `/horizon/api/metrics/*`, `/horizon/api/jobs/recent`.
- Assert JSON shape matches Horizon's expectations after pushing N jobs.

### CI matrix
- PHP 8.2, 8.3, 8.4
- Laravel 10, 11, 12
- Horizon 5.x latest
- GitHub Actions; LocalStack as service container.

### Out of scope
- Horizon's Redis repository internals (covered by Horizon's own tests).
- AWS SDK behavior (covered by AWS tests).

## Configuration

`config/horizon-sqs.php`:
```php
return [
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),
    'workload_cache_ttl' => 5,

    'sqs_max_delay' => 900,
    'long_delay_sweep_interval' => 60,

    'visibility_heartbeat' => false,

    'fifo' => [
        'message_group_id' => 'queue-name', // 'queue-name' | 'job-class' | callable
        'content_based_dedup' => true,
    ],

    'extended_payload' => [
        'enabled' => false,
        'bucket' => env('HORIZON_SQS_S3_BUCKET'),
        'prefix' => 'horizon-sqs-payloads/',
        'lifecycle_days' => 7,
    ],
];
```

`config/queue.php` is unchanged — users keep their existing `sqs` connection block. The driver name `sqs` is intercepted by our `Queue::extend()` call.

## Resolved Decisions (called out for reviewer attention)

- **FIFO `message_group_id = 'job-class'` strategy:** derive from the `data.commandName` field already present in the serialized payload — not from runtime reflection. Stable across queue replays.
- **Long-delay sweep registration:** the package's `boot()` registers the scheduled command automatically via Laravel's `Schedule` facade. Users do not need to edit their `app/Console/Kernel.php`.

## Acceptance Criteria

1. A fresh Laravel app with `composer require masonworkforce/horizon-sqs`, an SQS queue, and Horizon installed shows full dashboard parity: throughput, recent/failed/completed jobs, workload (pending + wait), tags, monitored tags, batches, retry-from-dashboard.
2. FIFO queue with `MessageGroupId` produces ordered processing within group.
3. A 600 KB payload pushed and processed end-to-end with `extended_payload.enabled = true`.
4. A job dispatched with `delay(3600)` is processed approximately 1 hour later (within sweep_interval tolerance).
5. CI matrix green across PHP × Laravel combinations.
