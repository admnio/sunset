<?php

namespace Admnio\Sunset\Contracts;

/**
 * Marker interface: jobs implementing Silenced are excluded from Sunset's
 * recent/failed lifecycle counters even though they still execute. Mirrors
 * the role that `Laravel\Horizon\Contracts\Silenced` played pre-v0.8.
 */
interface Silenced
{
}
