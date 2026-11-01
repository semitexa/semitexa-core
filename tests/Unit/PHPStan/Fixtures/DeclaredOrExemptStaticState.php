<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

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
