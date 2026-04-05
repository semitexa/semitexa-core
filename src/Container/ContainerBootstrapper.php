<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\BuildPhase\AttributeScanPhase;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\BuildPhaseInterface;
use Semitexa\Core\Container\BuildPhase\ClassmapLoadPhase;
use Semitexa\Core\Container\BuildPhase\ContractResolutionPhase;
use Semitexa\Core\Container\BuildPhase\CycleDetectionPhase;
use Semitexa\Core\Container\BuildPhase\ExecutionScopedBuildPhase;
use Semitexa\Core\Container\BuildPhase\FactoryBuildPhase;
use Semitexa\Core\Container\BuildPhase\InjectionAnalysisPhase;
use Semitexa\Core\Container\BuildPhase\ModuleDiscoveryPhase;
use Semitexa\Core\Container\BuildPhase\ReadonlyBuildPhase;
use Semitexa\Core\Container\BuildPhase\RegistryBuildPhase;
use Semitexa\Core\Container\BuildPhase\ResolverBuildPhase;
use Semitexa\Core\Container\BuildPhase\ScopeDetectionPhase;
use Semitexa\Core\Container\BuildPhase\ServiceRegistrationPhase;
use Semitexa\Core\Container\BuildPhase\ValidationPhase;
use Semitexa\Core\Discovery\BootDiagnostics;

/**
 * Orchestrates the container build process through sequential build phases.
 *
 * Each phase is an independent class implementing BuildPhaseInterface.
 * Phases communicate through a shared BuildContext accumulator.
 * No DI is available during build — phases receive explicit dependencies
 * through their constructors or from the BuildContext.
 *
 * @internal Used only by SemitexaContainer::build().
 */
final class ContainerBootstrapper
{
    /**
     * Run the full container build sequence.
     *
     * Creates all build phases, executes them sequentially, and transfers
     * accumulated state to the typed stores in BuildContext.
     */
    public function build(BuildContext $context): void
    {
        BootDiagnostics::begin();

        $cycleDetector = new CycleDetector();

        /** @var list<BuildPhaseInterface> $phases */
        $phases = [
            new ClassmapLoadPhase(),
            new ModuleDiscoveryPhase(),
            new AttributeScanPhase(),
            new RegistryBuildPhase(),
            new ContractResolutionPhase(),
            new ServiceRegistrationPhase(),
            new ScopeDetectionPhase(),
            new InjectionAnalysisPhase(),
            new CycleDetectionPhase($cycleDetector),
            new ReadonlyBuildPhase(),
            new ExecutionScopedBuildPhase(),
            new ResolverBuildPhase(),
            new FactoryBuildPhase(),
            new ValidationPhase(),
        ];

        foreach ($phases as $phase) {
            $phase->execute($context);
        }

        // Transfer build-time arrays to typed stores
        $context->transferToStores();

        $strictMode = filter_var(getenv('BOOT_STRICT_MODE'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        BootDiagnostics::current()->finalize(strict: $strictMode);
    }
}
