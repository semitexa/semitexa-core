<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Support\Row;

/**
 * Where a coroutine is parked, as a short frame trail.
 *
 * One wording for the two places that have to name a coroutine an operator
 * did not start: the exit drain (a coroutine that refused cancellation) and
 * the memory watchdog (what a worker was holding when it grew too large).
 */
final class CoroutineSite
{
    public static function describe(int $cid): string
    {
        try {
            $frames = \Swoole\Coroutine::getBackTrace($cid);
        } catch (\Throwable) {
            return 'unknown (backtrace unavailable)';
        }

        if (!is_array($frames) || $frames === []) {
            return 'unknown (empty backtrace)';
        }

        $trail = [];
        foreach (array_slice($frames, 0, 4) as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $values = Row::of($frame);
            $call = $values->string('class') . $values->string('type') . $values->string('function', '?');
            $file = $values->has('file') ? basename($values->string('file')) : null;
            $trail[] = $file !== null ? $call . ' (' . $file . ':' . $values->string('line', '?') . ')' : $call;
        }

        return $trail === [] ? 'unknown (unreadable frames)' : implode(' <- ', $trail);
    }
}
