<?php

namespace Admnio\Sunset\Support;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class Platform
{
    /**
     * Determine whether Sunset should manage its processes with POSIX signals.
     *
     * Signal-based supervision requires ext-pcntl, which is unavailable on
     * Windows. When this returns false, Sunset runs in "compatibility mode":
     * the master is stopped with Ctrl-C and children are stopped via the
     * process API rather than signals. Setting sunset.compatibility_mode to
     * true forces this off even where ext-pcntl is present (useful for
     * exercising the signal-less path on Linux/macOS).
     */
    public static function handlesSignals(): bool
    {
        return extension_loaded('pcntl')
            && config('sunset.compatibility_mode') !== true;
    }

    /**
     * Determine whether the current OS spawns child processes via cmd.exe.
     *
     * Drives shell-command construction (the Unix `exec` builtin and POSIX
     * quoting do not exist under cmd.exe). This is deliberately OS-based and
     * independent of compatibility_mode, since the spawning shell is fixed.
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
