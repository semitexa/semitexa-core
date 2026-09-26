<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;

/**
 * Fixture: what StaticStateLifetimeRule must and must not flag.
 *
 * Line numbers are asserted by the test — append, do not insert.
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

/** ALLOWED — each exemption the rule documents. */
final class DeclaredOrExemptStaticState
{
    #[WorkerState('Metadata keyed by class name; derived from code only.')]
    private static array $metadata = [];

    private static bool $warned = false;

    private static ?int $counter = null;

    private static string $cachedVersion = '';

    private static ?StaticStateScope $scope = null;

    private static ?\WeakMap $perObject = null;

    /** @var list<string> an instance property, not static */
    private array $instanceState = [];

    public static function once(): bool
    {
        static $done = false;

        return $done;
    }
}

enum StaticStateScope
{
    case Worker;
}

/** ALLOWED — the class registers its reset with PerRequestStateRegistry: request scope. */
final class RequestScopedStaticState
{
    /** @var array<string, bool> */
    private static array $grants = [];

    public static function remember(string $key): void
    {
        PerRequestStateRegistry::register('fixture.grants', static function (): void {
            self::$grants = [];
        });
        self::$grants[$key] = true;
    }
}

/** FLAGGED — a trait's static is shared state wherever the trait is used. */
trait StaticStateInTrait
{
    /** @var list<object> */
    private static array $seen = [];
}

// Aliases are imported here, not at the top, so the lines asserted above do not move.
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

/** ALLOWED — an aliased #[WorkerState] still resolves to the Semitexa attribute. */
final class AliasedWorkerStateAttribute
{
    #[AliasedWorkerState('Metadata keyed by class name; derived from code only.')]
    private static array $metadata = [];
}

/** ALLOWED — an aliased PerRequestStateRegistry::register() still declares request scope. */
final class AliasedRegistryStaticState
{
    /** @var array<string, bool> */
    private static array $grants = [];

    public static function remember(string $key): void
    {
        AliasedRegistry::register('fixture.aliased', static function (): void {
            self::$grants = [];
        });
        self::$grants[$key] = true;
    }
}

/** FLAGGED — an unrelated attribute that happens to be called WorkerState declares nothing. */
final class ForeignWorkerStateAttribute
{
    #[\Some\Other\WorkerState('not the Semitexa attribute')]
    private static array $impostor = [];
}
