<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

/**
 * Where a log line was written FROM, when something is able to say.
 *
 * ## Why this is a slot and not an implementation
 *
 * The question "which part of the pipeline was running when this line was
 * logged" has an exact answer, and it lives in semitexa/dev: the tracer knows
 * which spans are open on this coroutine, and the Observatory journal knows
 * which process the coroutine belongs to. The logger lives here, in core, and
 * core must not depend on dev.
 *
 * So the dependency is inverted rather than argued about. Core owns the shape
 * of the answer and the place to put it; whoever can answer installs a
 * resolver at boot. Nothing is installed by default, and then this costs one
 * null check per log call — which matters, because it sits on a path that runs
 * for every line the application writes.
 *
 * ## What it is NOT
 *
 * This static holds a FUNCTION, set once at boot and identical for every
 * coroutine. It is not request-scoped state, which under Swoole would be the
 * well-known trap: a `private static` holding one request's value is shared by
 * every coroutine in the worker and hands one request another's data. The
 * per-request part of the answer is computed inside the resolver, from the
 * calling coroutine, on every call.
 *
 * ## Failure is silence
 *
 * A resolver that throws must not take the log line with it. Diagnostics that
 * can break the thing they observe are worse than no diagnostics, and a logger
 * is the one component that is running precisely when everything else is not.
 */
final class LogOrigin
{
    /** @var (\Closure(): (array{process?: string, block?: string}|null))|null */
    private static ?\Closure $resolver = null;

    /**
     * Install the thing that can answer, or null to stop asking.
     *
     * @param (\Closure(): (array{process?: string, block?: string}|null))|null $resolver
     */
    public static function resolveWith(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function isResolvable(): bool
    {
        return self::$resolver !== null;
    }

    /**
     * The origin of the line being written now, or null when nobody can say.
     *
     * Null is a real answer and the common one: no resolver installed, or a
     * line written outside any traced request — a worker starting up, a CLI
     * command, a listener on a queue message. Those lines are not less
     * important, they simply have no block to belong to.
     *
     * @return array{process?: string, block?: string}|null
     */
    public static function current(): ?array
    {
        $resolver = self::$resolver;
        if ($resolver === null) {
            return null;
        }

        try {
            $origin = $resolver();
        } catch (\Throwable) {
            return null;
        }

        return is_array($origin) && $origin !== [] ? $origin : null;
    }
}
