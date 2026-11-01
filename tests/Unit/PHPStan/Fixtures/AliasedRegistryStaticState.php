<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

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
