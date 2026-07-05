<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;

/**
 * A discovered, active listener that fails to register must NOT vanish
 * silently — the failure has to reach BootDiagnostics.
 *
 * The pipeline loop wires the AuthCheck phase (AuthorizationListener,
 * CsrfListener); a swallowed registration failure there runs every request
 * with authorization silently absent. The event loop's swallow hid
 * unregistered subscribers (audit, ledger publish, cache invalidation) while
 * dispatch kept "succeeding". Both were `catch { continue; }` with zero
 * diagnostics; this pins the diagnostic.
 */
final class ListenerRegistryDiagnosticTest extends TestCase
{
    #[Test]
    public function pipeline_registry_reports_a_listener_that_fails_to_reflect(): void
    {
        // A discovered class that does not exist: it passes the active-class
        // filter (ModuleRegistry knows no module for it) but ReflectionClass
        // throws — the autoload-glitch / mixed-version-deploy scenario.
        $diagnostics = BootDiagnostics::begin();
        $registry = new PipelineListenerRegistry(
            $this->discoveryReturning('Semitexa\\Ghost\\BrokenPipelineListener'),
            new ModuleRegistry(),
        );

        $registry->ensureBuilt();

        $warnings = array_filter(
            $diagnostics->getWarnings(),
            static fn ($w): bool => $w->component === 'PipelineListenerRegistry',
        );
        self::assertNotSame([], $warnings, 'A failed pipeline-listener registration must surface a diagnostic.');
        self::assertStringContainsString('BrokenPipelineListener', reset($warnings)->message);
    }

    #[Test]
    public function event_registry_reports_a_listener_that_fails_to_reflect(): void
    {
        $diagnostics = BootDiagnostics::begin();
        $registry = new EventListenerRegistry(
            $this->discoveryReturning('Semitexa\\Ghost\\BrokenEventListener'),
            new ModuleRegistry(),
        );

        $registry->ensureBuilt();

        $warnings = array_filter(
            $diagnostics->getWarnings(),
            static fn ($w): bool => $w->component === 'EventListenerRegistry',
        );
        self::assertNotSame([], $warnings, 'A failed event-listener registration must surface a diagnostic.');
        self::assertStringContainsString('BrokenEventListener', reset($warnings)->message);
    }

    private function discoveryReturning(string $class): ClassDiscovery
    {
        return new class ($class) extends ClassDiscovery {
            public function __construct(private readonly string $class)
            {
            }

            public function initialize(): void
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return [$this->class];
            }
        };
    }
}
