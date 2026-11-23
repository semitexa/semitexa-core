<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;

/**
 * Discovers service contracts from #[SatisfiesServiceContract(of: SomeInterface::class)]
 * on implementation classes, and alone decides which implementation of a contract is
 * active (d-20261123-di-registry-one-source). With two or more: a module that extends
 * another (composer.json extra.semitexa-module.extends) wins over it, a project module
 * (src/modules) wins over a package, and any module wins over Core's default. Nothing
 * else relates two modules, so between unrelated ones of a tier the one found first
 * wins; getDecisions() says which of these decided each contract.
 */
final class ServiceContractRegistry
{
    /**
     * Single source of truth: per-interface implementations and active class.
     * @var array<class-string, array{implementations: list<array{module: string, class: class-string, factoryKey?: \BackedEnum|null}>, active: class-string}>
     */
    private array $contractDetails = [];

    /**
     * For each contract with two or more implementations, what separated the
     * active one from its closest rival: discovery-order when it beat another
     * only by the order modules were found (those are tied_with), else extends,
     * project-module or core-default.
     * @var array<class-string, array{decided_by: 'extends'|'project-module'|'core-default'|'discovery-order', tied_with: list<array{module: string, class: class-string}>}>
     */
    private array $decisions = [];

    /**
     * The ties between two different project modules, which lint:di warns about:
     * the project's own code, whose winner nothing it declared decides. cycles:
     * the extends cycles that leave either of the two with no place in the
     * order, each written from its first module by name.
     * @var list<array{contract: class-string, active: class-string, active_module: string, other: class-string, other_module: string, cycles: list<string>}>
     */
    private array $projectModuleTies = [];

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
     * @return array<class-string, class-string>
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
     * @return array<class-string, array{implementations: list<array{module: string, class: class-string, factoryKey?: \BackedEnum|null}>, active: class-string}>
     */
    public function getContractDetails(): array
    {
        return $this->contractDetails;
    }

    /**
     * @return array<class-string, array{decided_by: 'extends'|'project-module'|'core-default'|'discovery-order', tied_with: list<array{module: string, class: class-string}>}>
     */
    public function getDecisions(): array
    {
        return $this->decisions;
    }

    /**
     * @return list<array{contract: class-string, active: class-string, active_module: string, other: class-string, other_module: string, cycles: list<string>}>
     */
    public function getProjectModuleTies(): array
    {
        return $this->projectModuleTies;
    }

    private function build(): void
    {
        $this->classDiscovery->initialize();
        $candidates = array_unique(array_merge(
            $this->classDiscovery->findClassesWithAttribute(SatisfiesServiceContract::class),
            $this->classDiscovery->findClassesWithAttribute(SatisfiesRepositoryContract::class)
        ));

        /** @var array<class-string, array<int, array{module: string, impl: class-string, factoryKey?: \BackedEnum|null}>> */
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
                    /** @var class-string $implClass */
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
        $types = [];
        $parents = [];
        foreach ($this->moduleRegistry->getModules() as $module) {
            $types[$module['name']] = $module['type'];
            if ($module['extends'] !== null && $module['extends'] !== '') {
                $parents[$module['name']] = $module['extends'];
            }
        }

        foreach ($byInterface as $interface => $candidatesList) {
            $winner = null;
            $winnerModule = '';
            $winnerRank = PHP_INT_MAX;
            foreach ($candidatesList as $item) {
                $rank = array_search($item['module'], $moduleOrder, true);
                if ($rank === false) {
                    $rank = 999;
                }
                if ($rank < $winnerRank) {
                    $winnerRank = $rank;
                    $winner = $item['impl'];
                    $winnerModule = $item['module'];
                }
            }
            if ($winner !== null) {
                /** @var class-string $interface */
                $this->decide($interface, $winner, $winnerModule, $candidatesList, $types, $parents, array_flip($moduleOrder));
                $this->contractDetails[$interface] = [
                    'implementations' => array_values(array_map(
                        static function (array $item): array {
                            /** @var class-string $implClass */
                            $implClass = $item['impl'];
                            $row = ['module' => $item['module'], 'class' => $implClass];
                            if (array_key_exists('factoryKey', $item)) {
                                $row['factoryKey'] = $item['factoryKey'];
                            }
                            return $row;
                        },
                        $candidatesList
                    )),
                    'active' => $winner,
                ];
            }
        }
    }

    /**
     * @param class-string $interface
     * @param class-string $active
     * @param array<int, array{module: string, impl: class-string, factoryKey?: \BackedEnum|null}> $candidates
     * @param array<string, string> $types module name => local, composer or vendor
     * @param array<string, string> $parents module name => the module it extends
     * @param array<string, int> $ranked the modules with a place in the order
     */
    private function decide(string $interface, string $active, string $activeModule, array $candidates, array $types, array $parents, array $ranked): void
    {
        $closeness = ['discovery-order' => 0, 'extends' => 1, 'project-module' => 2, 'core-default' => 3];
        $decidedBy = null;
        $tiedWith = [];
        foreach ($candidates as $item) {
            if ($item['impl'] === $active || isset($tiedWith[$item['impl']])) {
                continue;
            }
            $reason = self::reason($activeModule, $item['module'], $types, $parents, $ranked);
            if ($reason === 'discovery-order') {
                $tiedWith[$item['impl']] = ['module' => $item['module'], 'class' => $item['impl']];
                if ($item['module'] !== $activeModule && ($types[$activeModule] ?? null) === 'local' && ($types[$item['module']] ?? null) === 'local') {
                    $this->projectModuleTies[] = [
                        'contract' => $interface,
                        'active' => $active,
                        'active_module' => $activeModule,
                        'other' => $item['impl'],
                        'other_module' => $item['module'],
                        'cycles' => self::cycles([$activeModule, $item['module']], $parents, $ranked),
                    ];
                }
            }
            if ($decidedBy === null || $closeness[$reason] < $closeness[$decidedBy]) {
                $decidedBy = $reason;
            }
        }
        if ($decidedBy !== null) {
            $this->decisions[$interface] = ['decided_by' => $decidedBy, 'tied_with' => array_values($tiedWith)];
        }
    }

    /**
     * The extends cycles that leave any of $modules with no place in the order
     * (a module stays out of it only while it is in a cycle, as each module
     * extends one other at most), each from its first module by name:
     * "Tenants → Website → Tenants".
     *
     * @param list<string> $modules
     * @param array<string, string> $parents
     * @param array<string, int> $ranked
     * @return list<string>
     */
    private static function cycles(array $modules, array $parents, array $ranked): array
    {
        $cycles = [];
        foreach ($modules as $module) {
            if (isset($ranked[$module])) {
                continue;
            }
            $members = [$module];
            $next = $parents[$module] ?? null;
            while ($next !== null && $next !== $module && count($members) <= count($parents)) {
                $members[] = $next;
                $next = $parents[$next] ?? null;
            }
            if ($next !== $module) {
                continue;
            }
            $first = (int) array_search(min($members), $members, true);
            $members = array_merge(array_slice($members, $first), array_slice($members, 0, $first));
            $cycles[implode(' → ', [...$members, $members[0]])] = true;
        }

        return array_keys($cycles);
    }

    /**
     * What made the implementation of module $winner beat the one of module $other.
     *
     * @param array<string, string> $types
     * @param array<string, string> $parents
     * @param array<string, int> $ranked
     * @return 'extends'|'project-module'|'core-default'|'discovery-order'
     */
    private static function reason(string $winner, string $other, array $types, array $parents, array $ranked): string
    {
        // One module, or a winner with no place in the order (a module in an
        // extends cycle ranks with Core): the order of the candidates decided.
        if ($winner === $other || !isset($ranked[$winner])) {
            return 'discovery-order';
        }
        if ($other === 'Core') {
            return 'core-default';
        }
        $seen = [];
        for ($module = $parents[$winner] ?? null; $module !== null && !isset($seen[$module]); $module = $parents[$module] ?? null) {
            if ($module === $other) {
                return 'extends';
            }
            $seen[$module] = true;
        }
        if (($types[$winner] ?? null) === 'local' && ($types[$other] ?? null) !== 'local') {
            return 'project-module';
        }

        return 'discovery-order';
    }
}
