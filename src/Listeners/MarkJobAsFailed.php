<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobFailed;
use Admnio\Sunset\Support\RecordedThrowable;
use RuntimeException;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsFailed
{
    public function __construct(
        private FailedJobRepository $failed,
        private JobRepository $jobs,
    ) {}

    public function handle(JobFailed $event): void
    {
        $exception = $this->reconstructException($event->payload->decoded['exception_data'] ?? null);

        $this->failed->failed($exception, $event->connectionName, $event->queue, $event->payload);

        // Mirror as "completed (failed)" in the job timeline so dashboards that
        // page through completed_jobs see failures in their normal flow.
        $this->jobs->completed($event->payload, silenced: false);
    }

    /**
     * Rebuild a Throwable that preserves the *original* failure's identity —
     * class, file, line, and trace — captured by {@see TranslateJobFailed}.
     * Returned as a {@see RecordedThrowable} so the failed-job repository can
     * persist the real origin rather than this listener's call stack.
     */
    private function reconstructException(?string $data): Throwable
    {
        $decoded = $data ? (json_decode($data, true) ?? []) : [];

        return new RecordedThrowable(
            originalClass: (string) ($decoded['class'] ?? RuntimeException::class),
            message: (string) ($decoded['message'] ?? 'Unknown failure'),
            originalFile: (string) ($decoded['file'] ?? ''),
            originalLine: (int) ($decoded['line'] ?? 0),
            originalTrace: (string) ($decoded['trace'] ?? ''),
        );
    }
}
