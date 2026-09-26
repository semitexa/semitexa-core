<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Declares that a `static` property deliberately lives as long as the Swoole
 * worker: one value, shared by every request and every coroutine the worker
 * serves until it exits.
 *
 * That is the right lifetime for a boot-time registry, a class-metadata cache
 * keyed by class name, or a process-wide handle such as a Swoole\Table. It is
 * the wrong one for anything derived from a request — a user, a permission
 * decision, a payload — which then survives into the next request or bleeds
 * into a concurrent one. The two look identical in code, so the choice is
 * written next to the property, where a reviewer reads it, and enforced by
 * {@see \Semitexa\Core\PHPStan\Rules\StaticStateLifetimeRule}.
 *
 * The other lifetimes already have a home and need no attribute:
 *   - per request: register a reset with {@see \Semitexa\Core\Lifecycle\PerRequestStateRegistry};
 *   - per coroutine: {@see \Semitexa\Core\Support\CoroutineLocal}, not a static.
 *
 * ```php
 * #[WorkerState('Reflection plan per payload class; derived from code, never from a request.')]
 * private static array $plans = [];
 * ```
 */
#[InternalAttribute('Lifetime annotation checked by a PHPStan rule; the rule message names it '
    . 'wherever it is needed, so there is nothing to discover.')]
#[Attribute(Attribute::TARGET_PROPERTY)]
final class WorkerState
{
    /**
     * @param string $reason Why worker-wide sharing is safe here: what the value
     *                       is keyed by or derived from, and why no request data
     *                       can end up in it.
     */
    public function __construct(
        public readonly string $reason,
    ) {
    }
}
