<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

/**
 * Fixture: what StaticStateLifetimeRule must and must not flag.
 *
 * Lines are asserted by the test, per file: append to a fixture, do not insert.
 */

/** FLAGGED — four statics that can hold objects or arrays, none declaring a lifetime. */
final class UndeclaredStaticState
{
    /** @var array<string, object> */
    private static array $decisions = [];

    private static ?\stdClass $lastPrincipal = null;

    private static $untyped = null;

    public static function memo(): array
    {
        static $cache = null;

        return $cache ??= [];
    }
}
