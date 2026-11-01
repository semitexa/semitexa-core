<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

/** ALLOWED — an aliased #[WorkerState] still resolves to the Semitexa attribute. */
final class AliasedWorkerStateAttribute
{
    #[AliasedWorkerState('Metadata keyed by class name; derived from code only.')]
    private static array $metadata = [];
}
