<?php

return [
    /*
    | Redis connection (from config/database.php) used by Horizon's
    | repositories as the stats sidecar. Should match Horizon's own redis.
    */
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),

    /*
    | Workload cache TTL in seconds for GetQueueAttributes results.
    | Prevents SQS API thrashing under dashboard polling load.
    */
    'workload_cache_ttl' => 5,

    /*
    | SQS native maximum delay for sendMessage in seconds.
    */
    'sqs_max_delay' => 900,

    /*
    | How often the delayed-job sweeper runs (seconds).
    */
    'long_delay_sweep_interval' => 60,

    /*
    | Reserved for v0.2. Flag accepted by config but not yet wired.
    | When implemented, an in-worker heartbeat will extend SQS visibility
    | while a job runs. Requires the pcntl extension on Linux.
    */
    'visibility_heartbeat' => false,

    'fifo' => [
        // 'queue-name' | 'job-class' | callable(array $payload, string $queue): string
        'message_group_id' => 'queue-name',
        'content_based_dedup' => true,
    ],

    'extended_payload' => [
        'enabled' => false,
        'bucket' => env('HORIZON_SQS_S3_BUCKET'),
        'prefix' => 'horizon-sqs-payloads/',
        'lifecycle_days' => 7,
    ],
];
