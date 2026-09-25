<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\CycleDetector;
use Semitexa\Core\Container\Exception\CircularDependencyException;

final class SelfInjectingService
{
    protected SelfInjectingService $self;
}

/**
 * A class that injects its own type is the shortest cycle there is. Dropping
 * self-edges let it through boot: an execution-scoped one then threw "Cyclic
 * mutable injection detected" on every request, a readonly one failed with a
 * misleading "No binding found".
 */
final class CycleDetectorTest extends TestCase
{
    #[Test]
    public function execution_scoped_mutable_self_injection_is_reported_as_a_cycle(): void
    {
        $this->assertSelfCycle('mutable', executionScoped: true);
    }

    #[Test]
    public function readonly_self_injection_is_reported_as_a_cycle(): void
    {
        $this->assertSelfCycle('readonly', executionScoped: false);
    }

    private function assertSelfCycle(string $kind, bool $executionScoped): void
    {
        try {
            (new CycleDetector())->assertNoCycles(
                [SelfInjectingService::class => SelfInjectingService::class],
                $executionScoped ? [SelfInjectingService::class => true] : [],
                [SelfInjectingService::class => ['self' => ['kind' => $kind, 'type' => SelfInjectingService::class]]],
                static fn (string $id): ?string => class_exists($id) ? $id : null,
            );
        } catch (CircularDependencyException $e) {
            self::assertSame([SelfInjectingService::class, SelfInjectingService::class], $e->chain);

            return;
        }

        self::fail('A self-injection must fail boot as a circular dependency.');
    }
}
