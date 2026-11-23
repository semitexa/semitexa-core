<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Application\Console\Command\ContractsListCommand;
use Semitexa\Core\Application\Console\Command\LintDiCommand;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Tests\Unit\Console\ContractsListFixtureDiscovery;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\CoreDefault\CoreTie;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Tenants\TenantsTie;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\TieContract;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Website\WebsiteOtherTie;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Website\WebsiteTie;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Two modules implementing one contract with nothing relating them: the one
 * found first was active, and nothing said so
 * (tk-contract-conflict-silent-between-unrelated-modules). The registry now
 * says what decided each contract, contracts:list shows it, and lint:di warns
 * when the tie is between two of the project's own modules. The modules are
 * fixtures on a real ModuleRegistry, so its own order decides.
 */
final class ContractTieDecisionTest extends TestCase
{
    #[Test]
    public function two_unrelated_project_modules_tie_and_lint_di_warns(): void
    {
        $registry = self::registry([['Website', 'local'], ['Tenants', 'local']]);

        self::assertSame(WebsiteTie::class, $registry->getContracts()[TieContract::class], 'the module found first');
        self::assertSame(
            ['decided_by' => 'discovery-order', 'tied_with' => [['module' => 'Tenants', 'class' => TenantsTie::class]]],
            $registry->getDecisions()[TieContract::class],
        );
        self::assertSame(
            ['Contract ' . TieContract::class . ': ' . WebsiteTie::class . ' (module Website) and ' . TenantsTie::class . ' (module Tenants) are unrelated; '
                . WebsiteTie::class . ' is active only by discovery order. Decide it in the composer.json of the module that should win (create one if it has none): '
                . '"extra": {"semitexa-module": {"extends": "Tenants"}} in module Website keeps ' . WebsiteTie::class . '; '
                . '"extends": "Website" in module Tenants makes ' . TenantsTie::class . ' active. '
                . 'A module extends one other at most, so check contracts:list after the change.'],
            array_map(LintDiCommand::contractTieWarning(...), $registry->getProjectModuleTies()),
        );
    }

    #[Test]
    public function a_declared_extends_decides_and_nothing_warns(): void
    {
        // Website is found first; Tenants extends it.
        $registry = self::registry([['Website', 'local'], ['Tenants', 'local', 'Website']]);

        self::assertSame(TenantsTie::class, $registry->getContracts()[TieContract::class]);
        self::assertSame(['decided_by' => 'extends', 'tied_with' => []], $registry->getDecisions()[TieContract::class]);
        self::assertSame([], $registry->getProjectModuleTies());
    }

    #[Test]
    public function a_tie_between_packages_is_shown_and_not_warned(): void
    {
        $registry = self::registry([['Website', 'composer'], ['Tenants', 'vendor']]);

        self::assertSame(
            ['decided_by' => 'discovery-order', 'tied_with' => [['module' => 'Tenants', 'class' => TenantsTie::class]]],
            $registry->getDecisions()[TieContract::class],
        );
        self::assertSame([], $registry->getProjectModuleTies(), 'not the project\'s code to relate');
    }

    #[Test]
    public function a_project_module_wins_over_a_package_and_any_module_over_core(): void
    {
        $tiers = self::registry([['Website', 'local'], ['Tenants', 'composer']]);
        self::assertSame(WebsiteTie::class, $tiers->getContracts()[TieContract::class]);
        self::assertSame(['decided_by' => 'project-module', 'tied_with' => []], $tiers->getDecisions()[TieContract::class]);

        $core = self::registry([['Tenants', 'vendor']], [CoreTie::class, TenantsTie::class]);
        self::assertSame(TenantsTie::class, $core->getContracts()[TieContract::class]);
        self::assertSame(['decided_by' => 'core-default', 'tied_with' => []], $core->getDecisions()[TieContract::class]);

        $alone = self::registry([['Tenants', 'vendor']], [TenantsTie::class]);
        self::assertSame([], $alone->getDecisions(), 'one implementation: nothing to decide');
    }

    /** Review (Fable): neither case is decided by a relation, and only two project modules warn. */
    #[Test]
    public function an_extends_cycle_and_two_implementations_in_one_module_are_ties(): void
    {
        // Each extends the other: neither gets a place in the order, Core's rank,
        // so the candidates' order decides, against Core too.
        $cycle = self::registry([['Website', 'local', 'Tenants'], ['Tenants', 'local', 'Website']]);
        self::assertSame(WebsiteTie::class, $cycle->getContracts()[TieContract::class]);
        self::assertSame(
            ['decided_by' => 'discovery-order', 'tied_with' => [['module' => 'Tenants', 'class' => TenantsTie::class], ['module' => 'Core', 'class' => CoreTie::class]]],
            $cycle->getDecisions()[TieContract::class],
        );
        // The relations exist already: the advice is to break the cycle (review, semitexa-core#269).
        self::assertSame(
            ['Contract ' . TieContract::class . ': ' . WebsiteTie::class . ' (module Website) and ' . TenantsTie::class . ' (module Tenants) tie, '
                . 'because the extends relations of their modules form a cycle and neither has a place in the module order; ' . WebsiteTie::class . ' is active only by discovery order. '
                . 'Break the cycle: remove extra.semitexa-module.extends from the composer.json of the module that should lose, then check contracts:list.'],
            array_map(LintDiCommand::contractTieWarning(...), $cycle->getProjectModuleTies()),
        );

        $oneModule = self::registry([['Website', 'local']], [WebsiteTie::class, WebsiteOtherTie::class]);
        self::assertSame(
            ['decided_by' => 'discovery-order', 'tied_with' => [['module' => 'Website', 'class' => WebsiteOtherTie::class]]],
            $oneModule->getDecisions()[TieContract::class],
        );
        self::assertSame([], $oneModule->getProjectModuleTies(), 'one module: no relation to declare');
    }

    #[Test]
    public function contracts_list_shows_what_decided_and_the_tie(): void
    {
        $command = new ContractsListCommand();
        (new ReflectionProperty($command, 'classDiscovery'))->setValue($command, new ContractsListFixtureDiscovery([WebsiteTie::class, TenantsTie::class, CoreTie::class]));
        (new ReflectionProperty($command, 'moduleRegistry'))->setValue($command, self::modules([['Website', 'local'], ['Tenants', 'local']]));
        $tester = new CommandTester($command);
        $tester->execute(['--json' => true]);

        $row = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['contracts'][0];
        self::assertSame(TieContract::class, $row['contract']);
        self::assertSame('discovery-order', $row['decided_by']);
        self::assertSame([['module' => 'Tenants', 'class' => TenantsTie::class]], $row['tied_with']);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $modules name, type, the module it extends
     * @param list<class-string> $classes
     */
    private static function registry(array $modules, array $classes = [WebsiteTie::class, TenantsTie::class, CoreTie::class]): ServiceContractRegistry
    {
        return new ServiceContractRegistry(new ContractsListFixtureDiscovery($classes), self::modules($modules));
    }

    /** @param list<array{0: string, 1: string, 2?: string}> $modules name, type, the module it extends */
    private static function modules(array $modules): ModuleRegistry
    {
        $entries = [];
        foreach ($modules as $module) {
            $entries[] = [
                'path' => '/fixture/' . $module[0],
                'name' => $module[0],
                'type' => $module[1],
                'namespace' => 'Semitexa\\Core\\Tests\\Unit\\Container\\Fixtures\\ContractTies\\' . $module[0],
                'composerType' => $module[1] === 'local' ? null : 'semitexa-module',
                'aliases' => [],
                'templatePaths' => [],
                'extends' => $module[2] ?? null,
                'controllers' => [],
                'routes' => [],
                'autoloadPsr4' => [],
                'config' => [],
            ];
        }
        $registry = new ModuleRegistry();
        (new ReflectionProperty(ModuleRegistry::class, 'modules'))->setValue($registry, $entries);
        (new ReflectionProperty(ModuleRegistry::class, 'initialized'))->setValue($registry, true);

        return $registry;
    }
}
