<?php

namespace Admnio\Sunset\Dashboard;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Persists the most recent transport probe results so the HealthStrip can
 * surface them on pages other than /sunset/health (which is where the probes
 * are actually executed).
 *
 * The cache is intentionally optimistic — probes are a UI hint, not a load-
 * bearing signal — so {@see recent()} returns an empty array (rather than
 * throwing) when the cache backend is unreachable.
 *
 * @internal
 */
final class ProbeCache
{
    /** Cache key under which probe results are stored. */
    private const KEY = 'sunset:health:probes';

    /** TTL in seconds. The HealthController writes fresh data whenever the
     *  health page is opened; this just bounds the staleness in between. */
    private const TTL_SECONDS = 60;

    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * Latest cached probe results, or an empty array when none exist yet
     * (e.g. the user hasn't opened the health page since boot) or when the
     * cache backend errors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(): array
    {
        try {
            $value = $this->cache->get(self::KEY);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Persist the probe results from a HealthController render. Failures are
     * swallowed; the probe pills will simply re-appear next time the health
     * page is opened.
     *
     * @param array<int, array<string, mixed>> $probes
     */
    public function record(array $probes): void
    {
        try {
            $this->cache->put(self::KEY, $probes, self::TTL_SECONDS);
        } catch (Throwable) {
            // Probe cache is best-effort.
        }
    }
}
