<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly as Readonly_;
use Semitexa\Core\Event\EventDispatcherInterface;

final class ReadonlyInjectionHolder
{
    #[Readonly_]
    protected ScopedFixtureService $scoped;

    #[Readonly_]
    protected ScopedFixtureListener $listener;

    #[Readonly_]
    protected WorkerFixtureService $worker;

    #[InjectAsMutable]
    protected ScopedFixtureService $mutable;

    #[Readonly_]
    protected EventDispatcherInterface $byInterface;

    #[Readonly_]
    protected ?ScopedFixtureService $nullable = null;
}
