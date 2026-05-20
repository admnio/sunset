<?php

namespace Admnio\Sunset\RateLimiting;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Read-only view over the `sunset:rl:rejects:*` counters that
 * {@see RateLimitGate::applyReject()} increments on every reject.
 *
 * Counter key shape: `sunset:rl:rejects:<connection>:<queue>:<limit-name>`.
 * The TTL on each counter matches the throttle window so old data ages out
 * naturally — this repository simply lists what's currently live and sorts
 * the most-rejected limits first for dashboard display.
 */
class RateLimitStatsRepository
{
    private const REJECT_PREFIX = 'sunset:rl:rejects:';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName,
    ) {
    }

    /**
     * Return rejection counts grouped by (connection, queue, limit name).
     *
     * @return array<int, array{
     *   connection: string,
     *   queue: string,
     *   limit: string,
     *   count: int,
     *   ttl_seconds: int,
     * }>
     */
    public function rejectsByLimit(): array
    {
        $conn = $this->redis->connection($this->connectionName);
        $prefix = $this->detectPrefix($conn);
        $rawKeys = (array) $conn->keys(self::REJECT_PREFIX . '*');

        $rows = [];
        foreach ($rawKeys as $rawKey) {
            // Strip Redis-client prefix if present (matches the
            // SunsetSweepRateLimitSlotsCommand pattern). $conn->keys() returns
            // FULLY PREFIXED keys straight from the wire; subsequent calls via
            // Laravel's wrapper will re-apply the prefix automatically, so
            // we strip here and pass the bare logical key downstream.
            $logicalKey = $prefix !== '' && str_starts_with($rawKey, $prefix)
                ? substr($rawKey, strlen($prefix))
                : $rawKey;

            if (! str_starts_with($logicalKey, self::REJECT_PREFIX)) {
                continue;
            }

            $suffix = substr($logicalKey, strlen(self::REJECT_PREFIX));
            $parts = explode(':', $suffix, 3);
            if (count($parts) !== 3) {
                continue; // malformed key — skip rather than crash the page
            }
            [$connection, $queue, $limit] = $parts;

            $count = (int) $conn->get($logicalKey);
            $ttl = (int) $conn->ttl($logicalKey);

            $rows[] = [
                'connection'  => $connection,
                'queue'       => $queue,
                'limit'       => $limit,
                'count'       => $count,
                'ttl_seconds' => $ttl < 0 ? 0 : $ttl,
            ];
        }

        // Sort by count descending so the most-rejected limits surface first.
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
    }

    /**
     * Detect the Redis client's prefix so we can strip it before reading.
     * (Same logic as SunsetSweepRateLimitSlotsCommand::detectPrefix.)
     */
    private function detectPrefix($conn): string
    {
        if (method_exists($conn, 'client')) {
            try {
                $client = $conn->client();
                if (is_object($client) && method_exists($client, '_prefix')) {
                    $p = $client->_prefix('');
                    if (is_string($p) && $p !== '') {
                        return $p;
                    }
                }
                if (is_object($client) && method_exists($client, 'getOption') && defined('\\Redis::OPT_PREFIX')) {
                    $p = $client->getOption(\Redis::OPT_PREFIX);
                    if (is_string($p) && $p !== '') {
                        return $p;
                    }
                }
                if (is_object($client) && method_exists($client, 'getOptions')) {
                    $opts = $client->getOptions();
                    if (is_object($opts) && method_exists($opts, '__get')) {
                        $p = $opts->__get('prefix');
                        if (is_string($p) && $p !== '') {
                            return $p;
                        }
                    }
                }
            } catch (Throwable) {
                // fall through to config fallback
            }
        }

        return (string) config('database.redis.options.prefix', '');
    }
}
