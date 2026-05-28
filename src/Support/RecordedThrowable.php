<?php

namespace Admnio\Sunset\Support;

use RuntimeException;

/**
 * Carries a failed job's *original* exception identity (class, file, line,
 * trace) across the reconstruction boundary.
 *
 * When a queued job fails, the original Throwable can't be unserialized safely,
 * so Sunset rebuilds one from the data captured by {@see \Admnio\Sunset\Listeners\TranslateJobFailed}.
 * PHP makes Exception::getFile()/getLine()/getTraceAsString() final, so a
 * rebuilt exception always reports the *reconstruction* site, not the job's.
 * This type instead exposes the originals via dedicated accessors that
 * {@see \Admnio\Sunset\Repositories\Redis\RedisFailedJobRepository::failed()}
 * persists — so the dashboard shows where the job actually failed.
 *
 * @internal
 */
final class RecordedThrowable extends RuntimeException
{
    public function __construct(
        private readonly string $originalClass,
        string $message,
        private readonly string $originalFile,
        private readonly int $originalLine,
        private readonly string $originalTrace,
    ) {
        parent::__construct($message);
    }

    public function originalClass(): string
    {
        return $this->originalClass;
    }

    public function originalFile(): string
    {
        return $this->originalFile;
    }

    public function originalLine(): int
    {
        return $this->originalLine;
    }

    public function originalTrace(): string
    {
        return $this->originalTrace;
    }
}
