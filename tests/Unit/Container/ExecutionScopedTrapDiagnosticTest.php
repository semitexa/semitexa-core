<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\GraphBuilder;

interface TrapFixtureStoreInterface
{
}

final class TrapFixtureScopedStore implements TrapFixtureStoreInterface
{
}

final class TrapFixturePlainStore implements TrapFixtureStoreInterface
{
}

/**
 * The "No binding found" enrichment for the ExecutionScoped injection trap.
 *
 * Two real regressions came from this exact failure reading like a missing
 * service: the calendar repository (boot crash-loop, fixed by trial and
 * error) and the collab draft store (a worker-local in-memory substitute was
 * bound instead, silently breaking cross-worker live sync). The diagnostic
 * must name the execution-scoped implementer and the sanctioned ways out —
 * and stay silent when the type genuinely has no implementation.
 */
final class ExecutionScopedTrapDiagnosticTest extends TestCase
{
    #[Test]
    public function names_the_scoped_implementer_via_the_id_map(): void
    {
        $note = $this->describe(
            TrapFixtureStoreInterface::class,
            idToClass: [TrapFixtureStoreInterface::class => TrapFixtureScopedStore::class],
            executionScopedClasses: [TrapFixtureScopedStore::class => true],
        );

        $this->assertStringContainsString(TrapFixtureScopedStore::class, $note);
        $this->assertStringContainsString('#[ExecutionScoped]', $note);
        $this->assertStringContainsString('AT CALL TIME', $note);
        $this->assertStringContainsString('worker-local in-memory substitute', $note);
    }

    #[Test]
    public function finds_the_implementer_without_an_id_mapping(): void
    {
        // The binding may be gone precisely BECAUSE the impl is execution-scoped
        // (no readonly binding was ever registered), so the id map cannot be
        // relied on — the scan over scoped classes must still find it.
        $note = $this->describe(
            TrapFixtureStoreInterface::class,
            idToClass: [],
            executionScopedClasses: [TrapFixtureScopedStore::class => true],
        );

        $this->assertStringContainsString(TrapFixtureScopedStore::class, $note);
    }

    #[Test]
    public function stays_silent_when_no_scoped_class_implements_the_type(): void
    {
        $note = $this->describe(
            TrapFixtureStoreInterface::class,
            idToClass: [TrapFixtureStoreInterface::class => TrapFixturePlainStore::class],
            executionScopedClasses: [\ArrayObject::class => true],
        );

        $this->assertSame('', $note, 'A genuinely missing binding must keep the plain message.');
    }

    /**
     * @param array<string, class-string> $idToClass
     * @param array<class-string, true> $executionScopedClasses
     */
    private function describe(string $typeName, array $idToClass, array $executionScopedClasses): string
    {
        $builder = (new \ReflectionClass(GraphBuilder::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GraphBuilder::class, 'describeExecutionScopedTrap');

        return (string) $method->invoke($builder, $typeName, $idToClass, $executionScopedClasses);
    }
}
