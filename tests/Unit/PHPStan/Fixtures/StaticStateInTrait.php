<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Attribute\WorkerState as AliasedWorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry as AliasedRegistry;

/** FLAGGED — a trait's static is shared state wherever the trait is used. */
trait StaticStateInTrait
{
    /** @var list<object> */
    private static array $seen = [];
}
