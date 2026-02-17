<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attributes\AsServiceContract;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;

/**
 * Discovers service contracts from #[AsServiceContract(of: SomeInterface::class)]
 * on implementation classes. When multiple modules provide an implementation for the same
 * interface, the one from the module that "extends" the other wins (module order by extends).
 */
final class ServiceContractRegistry
{
    /**
     * Single source of truth: per-interface implementations and active class.
     * @var array<string, array{implementations: list<array{module: string, class: string}>, active: string}>
     */
    private array $contractDetails = [];

    public function __construct()
    {
        $this->build();
    }

    /**
     * Returns contract (interface) => implementation class for all discovered contracts.
     * Derived from contract details (module "extends" order: most derived wins).
     *
     * @return array<string, string>
     */
    public function getContracts(): array
    {
        $out = [];
        foreach ($this->contractDetails as $interface => $data) {
            $out[$interface] = $data['active'];
        }
        return $out;
    }

    /**
     * Returns per-interface details: all implementations (module + class) and the active implementation.
     * Useful for debugging and for the contracts:list command.
     *
     * @return array<string, array{implementations: list<array{module: string, class: string}>, active: string}>
     */
    public function getContractDetails(): array
    {
        return $this->contractDetails;
    }

    private function build(): void
    {
        ClassDiscovery::initialize();
        $candidates = ClassDiscovery::findClassesWithAttribute(AsServiceContract::class);

        /** @var array<string, array<int, array{module: string, impl: string}>> interface => list of {module, impl} */
        $byInterface = [];

        foreach ($candidates as $implClass) {
            try {
                $ref = new \ReflectionClass($implClass);
                if (!$ref->isInstantiable()) {
                    continue;
                }
                $attrs = $ref->getAttributes(AsServiceContract::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var AsServiceContract $attr */
                $attr = $attrs[0]->newInstance();
                $interface = ltrim($attr->of, '\\');
                if (!interface_exists($interface)) {
                    continue;
                }
                if (!$ref->implementsInterface($interface)) {
                    continue;
                }
                $moduleName = ModuleRegistry::getModuleNameForClass($implClass);
                if ($moduleName === null) {
                    // Classes from Semitexa\Core (e.g. AsyncJsonLogger) are treated as module "Core"
                    if (!str_starts_with(ltrim($implClass, '\\'), 'Semitexa\\Core\\')) {
                        continue;
                    }
                    $moduleName = 'Core';
                }
                $byInterface[$interface][] = ['module' => $moduleName, 'impl' => $implClass];
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] ServiceContractRegistry::build() failed for {$implClass}: " . $e->getMessage());
                }
                continue;
            }
        }

        $moduleOrder = ModuleRegistry::getModuleOrderByExtends();

        foreach ($byInterface as $interface => $candidatesList) {
            // Handlers are not service contracts; they are discovered via #[AsPayloadHandler] and invoked by the kernel.
            if ($interface === HandlerInterface::class) {
                continue;
            }
            $winner = null;
            $winnerRank = PHP_INT_MAX;
            foreach ($candidatesList as $item) {
                $rank = array_search($item['module'], $moduleOrder, true);
                if ($rank === false) {
                    $rank = 999;
                }
                if ($rank < $winnerRank) {
                    $winnerRank = $rank;
                    $winner = $item['impl'];
                }
            }
            if ($winner !== null) {
                $this->contractDetails[$interface] = [
                    'implementations' => array_map(
                        fn(array $item): array => ['module' => $item['module'], 'class' => $item['impl']],
                        $candidatesList
                    ),
                    'active' => $winner,
                ];
            }
        }
    }
}
