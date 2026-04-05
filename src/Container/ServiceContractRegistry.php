<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
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
     * @var array<string, array{implementations: list<array{module: string, class: string, factoryKey?: \BackedEnum|null}>, active: string}>
     */
    private array $contractDetails = [];
    private readonly ClassDiscovery $classDiscovery;
    private readonly ModuleRegistry $moduleRegistry;

    public function __construct(
        ?ClassDiscovery $classDiscovery = null,
        ?ModuleRegistry $moduleRegistry = null,
    ) {
        $this->classDiscovery = $classDiscovery ?? new ClassDiscovery();
        $this->moduleRegistry = $moduleRegistry ?? new ModuleRegistry();
        $this->build();
    }

    /**
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
     * @return array<string, array{implementations: list<array{module: string, class: string, factoryKey?: \BackedEnum|null}>, active: string}>
     */
    public function getContractDetails(): array
    {
        return $this->contractDetails;
    }

    private function build(): void
    {
        $this->classDiscovery->initialize();
        $candidates = array_unique(array_merge(
            $this->classDiscovery->findClassesWithAttribute(SatisfiesServiceContract::class),
            $this->classDiscovery->findClassesWithAttribute(SatisfiesRepositoryContract::class)
        ));

        /** @var array<string, array<int, array{module: string, impl: string, factoryKey?: \BackedEnum|null}>> */
        $byInterface = [];

        foreach ($candidates as $implClass) {
            try {
                $ref = new \ReflectionClass($implClass);
                if (!$ref->isInstantiable()) {
                    continue;
                }

                $attrs = array_merge(
                    $ref->getAttributes(SatisfiesServiceContract::class),
                    $ref->getAttributes(SatisfiesRepositoryContract::class)
                );

                if ($attrs === []) {
                    continue;
                }

                $moduleName = $this->moduleRegistry->getModuleNameForClass($implClass);
                if ($moduleName === null) {
                    if (!str_starts_with(ltrim($implClass, '\\'), 'Semitexa\\Core\\')) {
                        continue;
                    }
                    $moduleName = 'Core';
                }

                foreach ($attrs as $reflectionAttribute) {
                    /** @var SatisfiesServiceContract|SatisfiesRepositoryContract $attr */
                    $attr = $reflectionAttribute->newInstance();
                    $interface = ltrim($attr->of, '\\');
                    if (!interface_exists($interface) || !$ref->implementsInterface($interface)) {
                        continue;
                    }
                    $entry = ['module' => $moduleName, 'impl' => $implClass];
                    if ($attr instanceof SatisfiesServiceContract) {
                        $entry['factoryKey'] = $attr->factoryKey;
                    }
                    $byInterface[$interface][] = $entry;
                }
            } catch (\Throwable $e) {
                BootDiagnostics::current()->invalidUsage('ServiceContractRegistry', "build() failed for {$implClass}: " . $e->getMessage(), $e);
                continue;
            }
        }

        $moduleOrder = $this->moduleRegistry->getModuleOrderByExtends();

        foreach ($byInterface as $interface => $candidatesList) {
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
                        static function (array $item): array {
                            $row = ['module' => $item['module'], 'class' => $item['impl']];
                            if (array_key_exists('factoryKey', $item)) {
                                $row['factoryKey'] = $item['factoryKey'];
                            }
                            return $row;
                        },
                        $candidatesList
                    ),
                    'active' => $winner,
                ];
            }
        }
    }
}
