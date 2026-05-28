# Sunset for Laravel

A multi-transport queue management platform for Laravel — a replacement for Laravel Horizon with first-class support for SQS, Redis, RabbitMQ, and Database, unified rate limiting, fleet-wide deploy controls, and a dashboard built for clarity.

## Why

Sunset is a multi-transport queue management platform for Laravel: SQS, Redis, RabbitMQ, and Database today (BullMQ and LavinMQ planned) — all behind one consistent dashboard with deep visibility into workers and queues. A unified rate-limiting subsystem (throttle + concurrency + drop strategies) layers over every transport, Sunset owns the supervisor process tree (`sunset:work` runs your queues), and Sunset owns the job lifecycle subsystem end-to-end.

**Mix and match transports.** Run different queues on different drivers *simultaneously* — emails on Redis, webhooks on SQS, nightly batches on RabbitMQ, low-volume internal jobs on Database — and supervise, rate-limit, monitor, pause, and observe all of them through the same dashboard, the same metrics, and the same lifecycle events. No re-platforming as your workload grows; route new queues onto whichever driver fits them best.

This release ships:

- **Multi-transport, run concurrently:** Amazon SQS, Redis, RabbitMQ, and Database — pick one, or run several side-by-side. Every transport surfaces the same dashboard data, metrics, lifecycle events, rate limits, pause state, and activity log.
- **Fleet-wide deploy controls:** `sunset:pause` pauses every configured queue, `sunset:pause-and-wait` pauses + blocks until in-flight jobs drain (with `--timeout`), `sunset:resume` brings them all back. Built for rolling deploys where worker containers may be torn down — uses the Redis-backed pause state so it works across containers.
- Sunset-owned job lifecycle: events, listeners, repositories, and `JobPayload` live under `Admnio\Sunset\*`. Transports dispatch Sunset events; Sunset listeners record to `sunset:*` Redis keys. Per-job runtimes are recorded on the job record, and failed-job records preserve the real exception origin + trace.
- Throughput metrics (jobs/min, jobs/hour), per-job runtime, percentiles, and per-class runtime histograms.
- Recent / Failed / Completed jobs lists with payloads, retry + bulk-delete from the dashboard.
- Workload page (pending counts, weighted wait, ETA to drain) with live per-queue pause/resume.
- Tags and Monitored tags; per-tag detail page.
- Job batches with split progress + failing-batch indicator.
- SQS specifics: FIFO + standard queues, S3 spill-over for payloads > 256 KB (opt-in), long delays (>15 min) buffered in Redis and swept into SQS, long polling on by default (max 20s WaitTimeSeconds — the cheapest setting).
- `Transport` interface so future drivers plug in without touching existing transport code.
- Sunset-owned supervisor process tree: `sunset:work` runs the master + supervisors. Master / Supervisor / Process repositories live under `Admnio\Sunset\Supervisor\*`. Live worker scaling (+/−) from the dashboard.
- 20+ `sunset:*` artisan commands covering the process tree (`work` / `supervise` / `worker`), fleet deploy controls (`pause` / `resume` / `pause-and-wait`), per-queue control (`pause-queue` / `resume-queue`), per-supervisor control (`pause-supervisor` / `continue-supervisor`), master control (`pause-master` / `continue` / `terminate` / `status` / `supervisors` / `supervisor-status`), maintenance (`clear` / `purge` / `snapshot` / `forget-failed`), and operator (`install` / `publish`).
- Fluent rate limiting via the `Admnio\Sunset\Facades\Sunset` facade — sliding-window throttle, concurrency semaphores, per-(queue|job-class) limits, dynamic bucket keys, conditional `when()` guards, and three over-limit strategies (release-computed / release-fixed / drop). Zero overhead when no limits are registered.
- Worker telemetry (v1.1.0): each `sunset:worker` samples its own RSS and CPU usage on the queue Looping event, throttled to once per 5 seconds (configurable). The Supervisors dashboard renders per-worker RSS / CPU% columns plus a click-to-toggle sparkline showing the last ~100 seconds. CPU% is best-effort on Windows (PHP's `getrusage()` returns zeros there); RSS works everywhere. Disable entirely with `SUNSET_TELEMETRY_ENABLED=false`.
- Activity log (v1.2.0+): a `/sunset/activity` dashboard page renders job failures, rate-limit rejections, worker restarts, and supervisor deployments. Polls every 3 seconds. Default filter shows errors only; toggle to `lifecycle` to see queued/completed jobs. The `Admnio\Sunset\Events\ActivityRecorded` event lets consumers forward activity to Slack / audit logs / external observability. Backed by a capped Redis sorted set (default 5000 events) — disable with `SUNSET_ACTIVITY_ENABLED=false`.
- Queue pause/resume (v1.3.0): pause individual queues from the Workload page or via `sunset:pause-queue {connection} {queue}` / `sunset:resume-queue`. Workers stop popping from paused queues on their next loop iteration (≤ worker sleep interval, default 3s); in-flight jobs continue. Producers can still enqueue. Pause/resume actions fire public `QueuePaused` / `QueueResumed` events and appear live in the Activity log. Works uniformly across every transport. Pause gate fails open on Redis outage — a Redis blip won't halt the fleet.
- Dashboard v2: full Linear/Vercel design — deep slate + violet, Geist + Geist Mono. Tri-state theme toggle (light/dark/system) with `prefers-color-scheme` listener. Sticky service-health strip with live transport probes. Command palette (⌘K), keyboard-shortcuts modal (`?`), global `G + letter` page navigation, toasts on every action. Drill-down detail pages: `/sunset/metrics/jobs/{name}/detail` (per-class hero stats + throughput chart + runtime histogram + recent runs/failures) and `/sunset/monitoring/tags/{tag}` (per-tag stats + 24h activity + class breakdown). Sortable + clickable tables, bulk-select with floating action bar on Failed jobs.

## Quickstart

```bash
composer require admnio/sunset
php artisan sunset:install
```

That's it. Sunset auto-discovers, the dashboard publishes to `public/vendor/sunset/`, and `/sunset` is accessible from localhost by default.

### Wire a queue connection (if you don't already have one)

Add to `config/queue.php`:

```php
'connections' => [
    'sqs' => [/* AWS credentials, queue name, etc. */],
    'redis' => [/* Redis connection name */],
    'rabbitmq' => [/* host/port/user/password */],
],
```

Sunset works with any combination of SQS, Redis, and RabbitMQ. Pick one or run all three side by side.

### Open the dashboard

Visit `http://your-app/sunset` (configurable via `SUNSET_PATH` env or `sunset.dashboard.path` config). Default access policy: localhost-only outside `local` env. To grant other users, register a gate in any service provider:

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    Sunset::auth(fn ($request) => $request->user()?->isAdmin());
}
```

### Run the supervisor

Sunset ships its own supervisor — `php artisan sunset:work`. Add it to your process manager (Supervisor, systemd, k8s) in place of `php artisan queue:work`.

### Add rate limits (optional)

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    Sunset::for('geocode')->throttle(perMinute: 10)->concurrency(3);
    Sunset::limit(\App\Jobs\GeocodeAddress::class)->throttle(perHour: 1000);
}
```

No limits = no behavior change. The rate-limit gate short-circuits when the registry is empty.

### Done

You should now see queue workload, recent jobs, failed jobs, supervisors, and rate-limit usage at `/sunset`.

---

## Public API

Sunset commits to backwards-compatible behavior on these surfaces for the lifetime of major version 1.x. Anything not listed here is internal — see the **Internal — do NOT depend on** section below.

### Configuration (`config/sunset.php`)

All published config keys are stable. Adding new keys is non-breaking; removing or renaming a key is a major version bump.

### Facade

- `Admnio\Sunset\Facades\Sunset::auth(\Closure $callback): void`
- `Admnio\Sunset\Facades\Sunset::for(string $queueName): LimitBuilder`
- `Admnio\Sunset\Facades\Sunset::limit(string $jobClass): LimitBuilder`

### Service provider

- `Admnio\Sunset\SunsetServiceProvider` — referenced by Composer auto-discovery and by consumers who need to register the package explicitly.

### Contracts (`Admnio\Sunset\Contracts\*`)

Interfaces are stable; consumer code should depend on these rather than on concrete implementations:

- `Limiter`, `WorkloadRepository`, `MetricsRepository`, `JobRepository`, `FailedJobRepository`, `TagRepository`, `SupervisorRepository`, `MasterSupervisorRepository`, `ProcessRepository`, `SupervisorCommandQueue`, `Silenced`, `Transport`, `Pausable`, `Restartable`, `Terminable`, `WorkerMetricsRepository`, `ActivityRepository`, `QueuePauseRepository`

### Events (`Admnio\Sunset\Events\*`)

Listen to these from your app for job lifecycle hooks:

- `JobQueueing`, `JobQueued`, `JobReserved`, `JobReleased`, `JobCompleted`, `JobFailed`, `JobRateLimited`, `JobEvent`
- Supervisor lifecycle: `MasterSupervisorDeployed`, `MasterSupervisorLooped`, `SupervisorLooped`, `WorkerProcessRestarting`, `LongWaitDetected`, `UnableToLaunchProcess`
- Activity: `ActivityRecorded` (fired after each event lands in the activity buffer — useful for forwarding to external observability/audit systems)
- Queue pause/resume: `QueuePaused`, `QueueResumed` (fired by the repository after each pause/resume action; carry `connection`, `queue`, and an optional `actor` tag)

### Exceptions (`Admnio\Sunset\Exceptions\*`)

Catchable by consumer code:

- `RateLimitExceededException`, `ExtendedPayloadException`, `InvalidConfigurationException`

### Value objects (rate limiting + job payload)

- `Admnio\Sunset\JobPayload`, `Admnio\Sunset\Tags`
- `Admnio\Sunset\Manager`
- `Admnio\Sunset\RateLimiting\Limit`, `LimitBuilder`, `ThrottleSpec`, `ConcurrencySpec`, `Decision`
- `Admnio\Sunset\RateLimiting\Targets\QueueTarget`, `JobClassTarget`
- `Admnio\Sunset\Telemetry\WorkerMetricsSnapshot`
- `Admnio\Sunset\Activity\ActivityEvent`

### Artisan commands

All `sunset:*` artisan commands are part of the public surface — the command names, signatures, and documented options are stable. The PHP classes implementing them are **not** public API — they may be refactored. Invoke commands via `php artisan sunset:*`, not by instantiating the command class.

### Dashboard routes

The `/sunset/*` routes (configurable via `sunset.dashboard.path`) are part of the public API. Their props shapes for the SPA are stable for major v1.x.

### Internal — do NOT depend on

Everything else, especially classes marked `@internal` in their PHPDoc. This includes:

- Concrete repository implementations under `Admnio\Sunset\Repositories\*`
- Supervisor internals under `Admnio\Sunset\Supervisor\*`
- Transport concrete classes (`*Queue`, `*Connector`, `*Transport` implementations under `Admnio\Sunset\Transports\*`)
- HTTP controllers and middleware under `Admnio\Sunset\Dashboard\*`
- Rate-limit internals (`RateLimitGate`, `LimitRegistry`, `RedisLimiter`, `RateLimitStatsRepository`, listeners)
- Job lifecycle listeners under `Admnio\Sunset\Listeners\*`
- Console command PHP classes under `Admnio\Sunset\Console\*`
- Supervisor command classes under `Admnio\Sunset\SupervisorCommands\*` and `Admnio\Sunset\MasterSupervisorCommands\*`
- Support utilities under `Admnio\Sunset\Support\*`
- The `Admnio\Sunset\AutoScaler` implementation
- The `Manager` singleton's internal state (only its public method surface listed above is stable)

These may change between minor releases. If you find yourself reaching for an internal class, [file an issue](https://github.com/admnio/sunset/issues) — we may want to surface that API as public.

## Install

```bash
composer require admnio/sunset
php artisan vendor:publish --tag=sunset-config
```

## Configure

`config/queue.php` — your `sqs` connection stays the same as standard Laravel.

`config/sunset.php`:

```php
return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'key_prefix' => env('SUNSET_KEY_PREFIX', 'sunset'),
    'memory_limit' => 64,          // MB; supervisor restarts workers above this
    'fast_termination' => false,   // true = SIGKILL immediately on terminate signal
    'wait' => 60,                  // seconds to wait for graceful drain before force-kill
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'supervisors' => [
        'production' => [
            'my-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'processes' => 10,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
    ],
    'transports' => [
        // ... unchanged ...
    ],
];
```

## RabbitMQ

Sunset supports RabbitMQ as a peer transport to SQS and Redis. Jobs go through the same Sunset event lifecycle, show the same depth/throughput/payload data on the dashboard, and delayed dispatch routes through Sunset's `DelayedJobStore` (the same store SQS uses since v0.2.0) — so you get arbitrary-length delays even though RabbitMQ has no native delayed-message primitive.

### Installation

```bash
composer require admnio/sunset
```

The `vladimir-yuldashev/laravel-queue-rabbitmq` driver ships transitively — no separate require needed.

### `config/queue.php`

Add a `rabbitmq` connection block:

```php
'connections' => [
    // ... existing connections ...
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'queue' => 'default',
        'connection' => 'default',
        'hosts' => [[
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
        ]],
        'options' => [
            'queue' => [
                'exchange' => env('RABBITMQ_EXCHANGE', ''),
                'exchange_type' => 'direct',
            ],
        ],
    ],
],
```

Env-var fallbacks: `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST`.

### `config/sunset.php`

The published config exposes a `transports.rabbitmq` block:

```php
'transports' => [
    // ... sqs, redis ...
    'rabbitmq' => [
        // Connection name (from config/queue.php) used by RabbitTransport
        // for dashboard workload (depth) queries.
        'workload_connection' => env('SUNSET_RABBITMQ_WORKLOAD_CONN', 'rabbitmq'),

        // Opt-in dead-letter exchange. Scaffolded in v0.6.0; full
        // nack-on-drop routing lands in v0.7.0.
        'dead_letter' => [
            'enabled' => env('SUNSET_RABBITMQ_DLX_ENABLED', false),
            'exchange' => env('SUNSET_RABBITMQ_DLX_EXCHANGE', null),
        ],
    ],
],
```

### Production operations — queue bindings

**IMPORTANT:** RabbitMQ does NOT auto-bind queues to non-default exchanges. If you configure `options.queue.exchange` to a named exchange (e.g. `'amq.direct'`), you must declare the queue-to-exchange binding out-of-band (CLI, terraform, infrastructure-as-code). Otherwise messages published to that exchange will be silently dropped (no error, no retry — RabbitMQ's "no route" default).

For each queue you intend to publish to, declare a binding once per environment:

```bash
rabbitmqadmin declare binding source=amq.direct destination=<queue-name> routing_key=<queue-name>
```

Or use the equivalent HTTP management API call.

If you set `options.queue.exchange = ''` (the empty / default exchange), no binding setup is required — RabbitMQ routes by queue name automatically. **For simple deployments, prefer the default exchange.**

### Delayed jobs

```php
Queue::connection('rabbitmq')->later(60, $job);
```

Delays route through Sunset's `DelayedJobStore` (Redis-backed ZSET). Schedule the `sunset:sweep-delayed` command in your `routes/console.php` (see [Scheduling](#scheduling)) — it pops due jobs back into RabbitMQ. Make sure `schedule:run` is wired in cron.

## Rate limiting

Sunset v0.7.0 ships a fluent queue rate-limiting API behind the `Admnio\Sunset\Facades\Sunset` facade. Throttle requests per sliding window, cap concurrent execution, and choose how excess jobs behave — release (default), release with a fixed backoff, or drop. Limits work uniformly across all three transports (SQS, Redis, RabbitMQ) because the bookkeeping lives in Redis. When no limits are registered the gate short-circuits with no Redis round-trip on the pop path, so adoption is incremental and zero-cost.

### Basic usage

Declare limits in any service provider's `boot()`:

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    // Protect a third-party API: 10 requests per minute, max 3 in flight.
    Sunset::for('geocode')
        ->throttle(perMinute: 10)
        ->concurrency(3);

    // Per-tenant rate limit using a dynamic bucket key. The closure receives
    // the queue Job contract, the decoded payload, the queue name, and tags.
    Sunset::for('emails')
        ->throttle(perMinute: 100)
        ->by(fn ($job, array $payload) => (string) ($payload['data']['tenant_id'] ?? 'global'));

    // Per-job-class with a conditional override that only kicks in when the
    // payload matches a specific provider.
    Sunset::limit(\App\Jobs\GeocodeAddress::class)
        ->throttle(perHour: 1000)
        ->when(fn ($job, array $payload) => ($payload['data']['provider'] ?? null) === 'google');

    // Drop noisy jobs instead of holding the queue. dropAsFailure(false)
    // deletes the job silently (log only); the default true routes drops
    // through failed_jobs.
    Sunset::for('webhook-deliveries')
        ->throttle(perMinute: 60)
        ->onOverLimit('drop')
        ->dropAsFailure(false);
}
```

`Sunset::for($queueName)` and `Sunset::limit($jobClass)` both return a `LimitBuilder`. Chaining `throttle()` and `concurrency()` on the same builder composes them — the gate evaluates both in a single Redis Lua script atomically.

### Builder method summary

| Method | Description |
|---|---|
| `throttle(perSecond: N \| perMinute: N \| perHour: N \| perDay: N)` | Sliding-window throttle (sorted-set-based). Pick exactly one unit. |
| `throttle(int $max, per: int $seconds)` | Raw form for custom windows. |
| `concurrency(int $max, ?int $slotTtl = null)` | Max simultaneous in-flight jobs. `$slotTtl` defaults to `max(queue.connections.*.retry_after) + 60` seconds. |
| `by(Closure $key)` | Dynamic bucket key. Closure `fn ($job, $payload, $queueName, $tags): string` — return value scopes the limit (per-tenant, per-user, etc.). |
| `when(Closure $condition)` | The limit only applies when the closure returns truthy. Closure-throw is treated as no-match. |
| `onOverLimit('release-computed' \| 'release-fixed' \| 'drop')` | How to handle rejected jobs. Default: `release-computed`. |
| `releaseAfter(int $seconds)` | Sugar: selects `release-fixed` with that backoff. |
| `dropAsFailure(bool $asFailure = true)` | When the strategy is `drop`: route to `failed_jobs` (`true`, default) or silently delete (`false`). |
| `countReleases(bool $count = true)` | Invert the default "don't double-count released jobs" behavior. |

### Over-limit strategies

- **`release-computed` (default)** — release the job back to the queue with `retry_after = time until the next slot frees`. Most efficient; minimizes wasted work.
- **`release-fixed`** — release with a fixed backoff regardless of when the next slot frees. Use when you want predictable retry cadence over efficiency. `releaseAfter($seconds)` is shorthand.
- **`drop`** — delete the job rather than release it. `dropAsFailure(true)` (default) routes the drop through `failed_jobs` via a `RateLimitExceededException`. `dropAsFailure(false)` deletes silently and emits an info-level log line.

Every rejection (regardless of strategy) fires `Admnio\Sunset\Events\JobRateLimited` for dashboards / metrics, and increments a per-`(connection, queue, limit)` Redis counter that TTLs out with the throttle window.

### Config block

`config/sunset.php` exposes a top-level `rate_limits` block:

```php
'rate_limits' => [
    // When true, every pop attempt (admit OR reject) consumes a throttle
    // token. Default false: rejected jobs don't burn tokens.
    'count_releases_by_default' => env('SUNSET_RATE_LIMITS_COUNT_RELEASES', false),

    // When true, Redis-unavailable during a limit check releases the job
    // with a 30s fixed backoff. When false (default), the gate fails open
    // — admit the job and log a warning. Pick false when uptime > quota
    // protection; true when quota > uptime.
    'fail_closed' => env('SUNSET_RATE_LIMITS_FAIL_CLOSED', false),

    // Cadence (seconds) of the safety-net Lua reconciliation that cleans
    // up leaked concurrency slots. Used by sunset:sweep-rate-limit-slots.
    'sweep_interval_seconds' => (int) env('SUNSET_RATE_LIMITS_SWEEP_INTERVAL', 60),
],
```

### Operational notes

- `sunset:sweep-rate-limit-slots` should be scheduled every minute in your app (see [Scheduling](#scheduling)). It reconciles concurrency-slot sets against TTL'd slot keys so semaphores recover automatically from killed workers. Manual run: `php artisan sunset:sweep-rate-limit-slots`.
- If no limits are ever registered, the gate exits via `LimitRegistry::isEmpty()` before any Redis call. Existing SQS/Redis/RabbitMQ pop paths add only one branch when rate limiting is unused — safe to ship the upgrade without declaring any limits.

## Dashboard

Sunset ships its own operator dashboard at `/sunset` (configurable). Inertia + Vue 3 SPA, polls every 3 seconds by default.

### Install

```bash
composer require admnio/sunset
php artisan sunset:install
```

`sunset:install` publishes the config + the compiled JS/CSS bundle to `public/vendor/sunset/`. **No Node.js required in the consumer app** — Sunset ships pre-built.

### Access

By default the dashboard is accessible only from localhost outside `local` env. Register a gate to allow specific users:

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    Sunset::auth(fn ($request) => $request->user()?->isAdmin());
}
```

### Pages

Overview · Workload · Recent jobs · Failed jobs · Pending · Completed · Metrics · Monitoring · Rate limits · Supervisors · Batches · Health.

The Failed Jobs page uses a master-detail layout (list left, selected job's exception + actions right). Retry/delete actions work via POST endpoints on the same routes.

### Configuration

In `config/sunset.php`:

```php
'dashboard' => [
    'path'                  => env('SUNSET_PATH', 'sunset'),
    'poll_interval_seconds' => (int) env('SUNSET_DASHBOARD_POLL', 3),
],
```

### Themes

Auto light/dark via `prefers-color-scheme`. User can override via the header toggle (persisted in localStorage as `sunset.theme`).

### Keyboard

⌘K (or Ctrl+K) opens a fuzzy command palette to jump between pages. ↑/↓ to navigate, Enter to select, Esc to close.

### Mobile

Full responsive. Left rail collapses on tablet; on phone, master-detail becomes single-pane with back navigation.

## Scheduling

Sunset does **not** auto-register its maintenance cron jobs — scheduling is left to your application so it stays explicit and visible. Paste this into your `routes/console.php` (Laravel 11+) or your `App\Console\Kernel::schedule()` method:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sunset:sweep-delayed')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:snapshot')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:sweep-rate-limit-slots')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:sweep-worker-metrics')->everyMinute()->withoutOverlapping();
```

What each one does:

| Command | What it does | When you need it |
|---|---|---|
| `sunset:sweep-delayed` | Pops due jobs out of Sunset's Redis-backed `DelayedJobStore` and back into SQS / RabbitMQ. | If you use long delays on SQS / RabbitMQ. |
| `sunset:snapshot` | Captures per-queue throughput and runtime into the time-series. | For the Metrics page, Throughput stat, and Workload ETA to populate. |
| `sunset:sweep-rate-limit-slots` | Reconciles concurrency-slot sets against TTL'd keys — semaphores recover from killed workers. | If you use rate limiting with `concurrency()`. |
| `sunset:sweep-worker-metrics` | Prunes orphaned worker telemetry rows from crashed workers. | If you have worker telemetry enabled (default on). |

Then make sure Laravel's scheduler is actually ticking — in development:

```bash
php artisan schedule:work
```

In production, wire `schedule:run` into cron once per minute:

```
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

## Operational notes

- **Long polling (default — saves money):** workers poll SQS with `WaitTimeSeconds=20` (the maximum) by default. With short-polling, every empty receive is a billable API call; long polling drastically reduces the request count on idle queues. To override, set `wait_time` (0–20) on your `sqs` queue connection in `config/queue.php` — for example `'wait_time' => 10` for faster idle-worker shutdown at the cost of more requests. `wait_time => 0` disables long polling (not recommended).
- **Visibility timeout:** set on each SQS queue to ≥ your job `timeout` × 1.5. (The `visibility_heartbeat` config knob is reserved for a later release — currently ignored.)
- **Extended payload cleanup:** add a lifecycle rule to your S3 bucket prefix (default `sunset-payloads/`) to clean up orphans from worker crashes.
- **Long-delay sweep:** add `sunset:sweep-delayed` to your scheduler (see [Scheduling](#scheduling)) and ensure `schedule:run` is wired in your cron.
- **Octane note (auth):** `Sunset::auth(...)` stores its callback in a static property. This is intentional and Octane-safe: each Octane worker boots its service providers once (registering the gate), then reuses the callback across the worker's lifetime of requests. Don't add `Admnio\Sunset\Manager` to Octane's "flush" list — flushing the static would lose the gate. If you need request-scoped auth logic, do it inside the callback (it receives `$request` per-call); don't rely on per-request callback registration.

## Testing locally

```bash
docker compose up -d
vendor/bin/phpunit
```

## License

MIT.
