<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

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
