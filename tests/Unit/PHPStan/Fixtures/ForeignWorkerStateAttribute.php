<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

/** FLAGGED — an unrelated attribute that happens to be called WorkerState declares nothing. */
final class ForeignWorkerStateAttribute
{
    #[\Some\Other\WorkerState('not the Semitexa attribute')]
    private static array $impostor = [];
}
