<?php

namespace Admnio\Sunset;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Derives tags for a queued job.
 *
 * Mirrors `Laravel\Horizon\Tags::for()` so existing Sunset consumers see the
 * same tag shape (e.g. `App\Models\User:42`) that Horizon-era dashboards
 * displayed. The implementation honours an explicit `tags()` method on the
 * job and otherwise reflects each property, collecting Eloquent models it
 * encounters (including those nested inside arrays / Collections).
 */
class Tags
{
    /**
     * Determine the tags that should be applied to the given job.
     *
     * @param  mixed  $job
     * @return array<int, string>
     */
    public static function for($job): array
    {
        if (is_object($job) && method_exists($job, 'tags')) {
            try {
                return array_values(array_unique(array_map('strval', (array) $job->tags())));
            } catch (Throwable) {
                // fall through to reflection-based extraction
            }
        }

        return array_values(array_unique(static::extractModelTags($job)));
    }

    /**
     * Extract `ClassBasename:key` tags from every Eloquent model on the job.
     *
     * @param  mixed  $job
     * @return array<int, string>
     */
    protected static function extractModelTags($job): array
    {
        if (! is_object($job)) {
            return [];
        }

        $tags = [];

        try {
            $reflection = new ReflectionClass($job);
        } catch (Throwable) {
            return [];
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);
            if (! $property->isInitialized($job)) {
                continue;
            }

            $value = $property->getValue($job);

            foreach (static::modelsFromValue($value) as $model) {
                $key = $model->getKey();
                if ($key === null || $key === '') {
                    continue;
                }
                $tags[] = class_basename($model) . ':' . $key;
            }
        }

        return $tags;
    }

    /**
     * Yield every Eloquent model contained directly or transitively within $value.
     *
     * @param  mixed  $value
     * @return iterable<Model>
     */
    protected static function modelsFromValue($value): iterable
    {
        if ($value instanceof Model) {
            yield $value;
            return;
        }

        if ($value instanceof Collection || is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof Model) {
                    yield $item;
                }
            }
        }
    }
}
