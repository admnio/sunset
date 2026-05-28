# Sunset for Laravel

A multi-transport queue management platform for Laravel — a replacement for Laravel Horizon with first-class support for SQS, Redis, RabbitMQ, and Database, unified rate limiting, fleet-wide deploy controls, and a dashboard built for clarity.

## Features

- **Multi-transport, run concurrently.** SQS, Redis, RabbitMQ, and Database — pick one or run several side-by-side. Each supervisor names which connection it consumes, so you can route different queues onto whichever driver fits.
- **Unified dashboard at `/sunset`** — workload, recent / failed / pending / completed jobs, metrics, supervisors, batches, tags, rate limits, health, and a live activity stream.
- **Fleet-wide deploy controls.** `sunset:pause`, `sunset:pause-and-wait` (drains in-flight jobs with `--timeout`), and `sunset:resume` — Redis-backed, so they work across containers.
- **Per-queue pause/resume** from the dashboard or CLI. Pause/resume fire public events so you can forward to Slack or your audit log.
- **Fluent rate limiting** — sliding-window throttle + concurrency semaphores, per-queue or per-job-class, dynamic bucket keys, conditional guards. Zero overhead when no limits are registered.
- **Throughput, runtime, percentiles, and runtime histograms** — both per-queue and per-class.
- **Worker telemetry** — RSS / CPU% per worker with live sparklines.
- **SQS specifics** — FIFO + standard queues, S3 spill-over for payloads over 256 KB, arbitrary-length delays buffered in Redis, long polling on by default.
- **Live worker scaling** (+ / −) from the dashboard, without restarting supervisors.

## Installation

```bash
composer require admnio/sunset
php artisan sunset:install
```

The dashboard publishes to `public/vendor/sunset/` (pre-built — no Node.js required) and `/sunset` is accessible from localhost by default.

If you plan to use the **`database`** queue driver, also run:

```bash
php artisan queue:table && php artisan migrate
```

## Configuration

### Queue connections — `config/queue.php`

Add the connection block(s) you want under `connections`. Sunset works with any one of these on its own, or any combination running side by side.

```php
'connections' => [

    'sqs' => [
        'driver'        => 'sqs',
        'key'           => env('AWS_ACCESS_KEY_ID'),
        'secret'        => env('AWS_SECRET_ACCESS_KEY'),
        'prefix'        => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
        'queue'         => env('SQS_QUEUE', 'default'),
        'suffix'        => env('SQS_SUFFIX'),     // e.g. '.fifo' for FIFO queues
        'region'        => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'after_commit'  => false,
    ],

    'redis' => [
        'driver'        => 'redis',
        'connection'    => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queue'         => env('REDIS_QUEUE', 'default'),
        'retry_after'   => 90,
        'block_for'     => null,
        'after_commit'  => false,
    ],

    'rabbitmq' => [
        'driver'        => 'rabbitmq',
        'queue'         => env('RABBITMQ_QUEUE', 'default'),
        'connection'    => 'default',
        'hosts' => [[
            'host'      => env('RABBITMQ_HOST', '127.0.0.1'),
            'port'      => (int) env('RABBITMQ_PORT', 5672),
            'user'      => env('RABBITMQ_USER', 'guest'),
            'password'  => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'     => env('RABBITMQ_VHOST', '/'),
        ]],
        'options' => [
            'queue' => [
                'exchange'      => env('RABBITMQ_EXCHANGE', ''),
                'exchange_type' => 'direct',
            ],
        ],
        'after_commit'  => false,
    ],

    'database' => [
        'driver'        => 'database',
        'connection'    => env('DB_QUEUE_CONNECTION'),
        'table'         => env('DB_QUEUE_TABLE', 'jobs'),
        'queue'         => env('DB_QUEUE', 'default'),
        'retry_after'   => (int) env('DB_QUEUE_RETRY_AFTER', 90),
        'after_commit'  => false,
    ],

],
```

### Supervisors — `config/sunset.php`

Sunset reads its supervisor definitions from `config('sunset.supervisors')`. Each environment block names supervisors, and each supervisor names the queue connection it consumes — this is where you choose which driver runs which queues.

```php
'supervisors' => [
    'production' => [
        'default-redis' => [
            'connection'    => 'redis',
            'queue'         => ['default', 'emails'],
            'balance'       => 'auto',
            'min_processes' => 1,
            'max_processes' => 10,
            'tries'         => 3,
            'timeout'       => 60,
        ],
        'webhooks-sqs' => [
            'connection'    => 'sqs',
            'queue'         => ['webhooks'],
            'balance'       => 'auto',
            'min_processes' => 1,
            'max_processes' => 5,
            'tries'         => 3,
            'timeout'       => 120,
        ],
    ],
],
```

Every other key in `config/sunset.php` ships with sensible defaults — see the file `sunset:install` publishes for the full reference (dashboard path, key prefix, trim windows, memory limit, etc.).

### Dashboard access

By default `/sunset` is accessible only from localhost outside `local` env. Register a gate to allow specific users — anywhere in your service-provider `boot()`:

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    Sunset::auth(fn ($request) => $request->user()?->isAdmin());
}
```

The dashboard path is configurable via `SUNSET_PATH` env or `sunset.dashboard.path`.

## Running the supervisor

Sunset ships its own supervisor — add it to your process manager (Supervisor, systemd, k8s, …) in place of `php artisan queue:work`:

```bash
php artisan sunset:work
```

## Scheduling

Sunset does **not** auto-register its maintenance cron jobs — scheduling is left to your application so it stays explicit. Paste this into `routes/console.php` (Laravel 11+) or your `App\Console\Kernel::schedule()` method:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sunset:sweep-delayed')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:snapshot')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:sweep-rate-limit-slots')->everyMinute()->withoutOverlapping();
Schedule::command('sunset:sweep-worker-metrics')->everyMinute()->withoutOverlapping();
```

| Command | What it does | When you need it |
|---|---|---|
| `sunset:sweep-delayed` | Pops due jobs out of the Redis-backed `DelayedJobStore` and back into SQS / RabbitMQ. | If you use long delays on SQS / RabbitMQ. |
| `sunset:snapshot` | Captures per-queue throughput and runtime into the time-series. | For the Metrics page, Throughput stat, and Workload ETA to populate. |
| `sunset:sweep-rate-limit-slots` | Reconciles concurrency-slot sets against TTL'd keys — semaphores recover from killed workers. | If you use rate limiting with `concurrency()`. |
| `sunset:sweep-worker-metrics` | Prunes orphaned worker telemetry rows. | Default-on; needed for the Supervisors page worker columns. |

Then make sure Laravel's scheduler is actually ticking — in development:

```bash
php artisan schedule:work
```

In production, wire `schedule:run` into cron once per minute:

```
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

## Rate limiting (optional)

Declare limits in any service provider's `boot()`:

```php
use Admnio\Sunset\Facades\Sunset;

public function boot(): void
{
    // 10 requests per minute, max 3 in flight.
    Sunset::for('geocode')
        ->throttle(perMinute: 10)
        ->concurrency(3);

    // Per-job-class.
    Sunset::limit(\App\Jobs\GeocodeAddress::class)
        ->throttle(perHour: 1000);
}
```

Both `Sunset::for($queue)` and `Sunset::limit($jobClass)` return a `LimitBuilder` supporting `throttle()`, `concurrency()`, `by()` (dynamic bucket key), `when()` (conditional), and `onOverLimit()` strategies (`release-computed` / `release-fixed` / `drop`). Limits work uniformly across every transport — the bookkeeping lives in Redis. No limits = no behavior change; the gate short-circuits when the registry is empty.

## Deploy controls

For rolling deploys where worker containers may be torn down:

```bash
php artisan sunset:pause-and-wait --timeout=120   # pause every queue, drain in-flight
# … do the deploy …
php artisan sunset:resume                         # bring queues back
```

`sunset:pause-and-wait` exits non-zero if the timeout elapses with jobs still in flight, so a deploy script can decide to abort. Use plain `sunset:pause` if you just want to pause without waiting.

## SQS notes

- **Visibility timeout.** Set on each SQS queue to at least your job `timeout` × 1.5 — Sunset relies on SQS redelivery to retry jobs after worker crashes.
- **Long polling.** Defaults to `wait_time=20` (the SQS maximum), which minimizes API calls on idle queues. Lower it on the `sqs` connection if you want faster idle-worker shutdown at the cost of more requests.

## RabbitMQ notes

The `vladimir-yuldashev/laravel-queue-rabbitmq` driver ships transitively — no separate require.

**Queue bindings.** RabbitMQ does *not* auto-bind queues to non-default exchanges. If you set `options.queue.exchange` to a named exchange (e.g. `'amq.direct'`), declare the binding yourself, once per environment:

```bash
rabbitmqadmin declare binding source=amq.direct destination=<queue-name> routing_key=<queue-name>
```

If you leave `options.queue.exchange` empty (the default exchange), no binding setup is needed — RabbitMQ routes by queue name. **Prefer the default exchange for simple deployments.**

**Delayed jobs** (`Queue::connection('rabbitmq')->later(60, $job)`) route through Sunset's Redis-backed `DelayedJobStore`, since RabbitMQ has no native delayed-message primitive. Make sure `sunset:sweep-delayed` is in your scheduler.

## License

MIT.
