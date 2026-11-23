<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\FactoryBuildPhase;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Registry\CanonicalRegistryPaths;
use Semitexa\Core\Registry\RegistryContractResolverGenerator;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tests\Support\StaticState;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\EditedResolver;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\FactoryPickedChannel;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\PickedChannel;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\PickedChannelKind;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\PickedMailChannel;
use Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides\PickedSmsChannel;

/**
 * The service contract registry alone decides a contract's active
 * implementation (tk-contracts-list-active-ignores-resolver,
 * d-20261123-di-registry-one-source). The container also asked a generated
 * resolver's getContract() — in get() and for a factory's default, while
 * property injection followed the registry — so an edited resolver split the
 * container itself, and contracts:list showed the registry's choice. No edit
 * survived anyway: registry:sync:contracts rewrote every resolver after each
 * composer install or update. Resolvers are no longer read nor written.
 *
 * verify:accept-test-change GeneratedResolversDoNotDependOnDiscoveryOrderTest and its ResolverOrder fixtures pinned the bytes of the generated resolver classes, which are no longer generated (d-20261123-di-registry-one-source)
 */
final class TheRegistryAloneDecidesTest extends TestCase
{
    /**
     * The shape get() used to hand to a resolver: two implementations, the
     * interface not built as a readonly service, the winner execution-scoped
     * (FeatureFlagsInterface in this workspace; on develop an edited resolver
     * there changed what get() returned). Built here from fixtures, so the test
     * holds where core is installed alone.
     */
    #[Test]
    public function an_edited_resolver_does_not_change_what_get_returns(): void
    {
        self::assertFalse(property_exists(TypeMap::class, 'interfaceToResolver'), 'get() has no resolver map to ask');

        $container = new SemitexaContainer();
        $typeMap = (new ReflectionProperty(SemitexaContainer::class, 'typeMap'))->getValue($container);
        $store = (new ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
        self::assertInstanceOf(TypeMap::class, $typeMap);
        self::assertInstanceOf(InstanceStore::class, $store);
        $typeMap->populateFromBuildArrays(
            [PickedChannel::class => PickedMailChannel::class, PickedMailChannel::class => PickedMailChannel::class, PickedSmsChannel::class => PickedSmsChannel::class],
            [PickedMailChannel::class => true],
            [PickedChannel::class => [PickedMailChannel::class, PickedSmsChannel::class]],
        );
        $prototype = new PickedMailChannel();
        $sms = new PickedSmsChannel();
        $store->prototypes[PickedMailChannel::class] = $prototype;
        $store->readonly[PickedSmsChannel::class] = $sms;
        // Where the container looked for the contract's resolver, edited to pick sms.
        $store->readonly[CanonicalRegistryPaths::REGISTRY_CONTRACTS_NAMESPACE . '\\PickedChannelResolver'] = new EditedResolver($sms);

        $got = $container->get(PickedChannel::class);

        self::assertInstanceOf(PickedMailChannel::class, $got, "the registry's active implementation, whatever the resolver says");
        self::assertNotSame($prototype, $got, 'a fresh clone of the execution-scoped winner, as before');
    }

    #[Test]
    public function a_factorys_default_is_the_registrys_active_implementation(): void
    {
        // Nothing can carry a resolver into the build any more.
        self::assertFalse(property_exists(BuildContext::class, 'interfaceToResolver'), 'the factory build reads no resolver map');
        self::assertFalse(property_exists(TypeMap::class, 'interfaceToResolver'), 'and get() none either');

        $mail = new PickedMailChannel();
        $sms = new PickedSmsChannel();
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->contractDetails = [PickedChannel::class => [
            'active' => PickedMailChannel::class,
            'implementations' => [
                ['module' => 'A', 'class' => PickedMailChannel::class, 'factoryKey' => PickedChannelKind::Mail],
                ['module' => 'B', 'class' => PickedSmsChannel::class, 'factoryKey' => PickedChannelKind::Sms],
            ],
        ]];
        $context->instanceStore->readonly = [
            PickedMailChannel::class => $mail,
            PickedSmsChannel::class => $sms,
            // A resolver a project still has, edited to pick sms: not read.
            CanonicalRegistryPaths::REGISTRY_CONTRACTS_NAMESPACE . '\\PickedChannelResolver' => new EditedResolver($sms),
        ];
        $store = $context->instanceStore;
        $context->resolveService = static fn (string $id): object => $store->readonly[$id];

        (new FactoryBuildPhase())->execute($context);

        $factory = $context->instanceStore->genericFactories[FactoryPickedChannel::class] ?? null;
        self::assertNotNull($factory, 'the contract got its factory');
        self::assertSame($mail, $factory->getDefault());
        self::assertSame($sms, $factory->get(PickedChannelKind::Sms));
    }

    #[Test]
    public function sync_removes_the_resolvers_it_generated_and_keeps_everything_else(): void
    {
        $root = self::scratchProject();
        $contracts = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS;
        file_put_contents($contracts . '/ClockResolver.php', self::generated(" * Resolver for App\\Clock.\n") . "final class ClockResolver {}\n");
        file_put_contents($contracts . '/OwnResolver.php', "<?php\n\n/** Written by hand: ours. */\nfinal class OwnResolver {}\n");
        file_put_contents($contracts . '/ChannelFactory.php', self::generated('') . "final class ChannelFactory {}\n");

        try {
            $removed = self::inProject($root, static fn (): array => RegistryContractResolverGenerator::removeGeneratedResolvers());
            $left = array_map('basename', glob($contracts . '/*.php') ?: []);
            sort($left);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        self::assertSame([CanonicalRegistryPaths::REGISTRY_CONTRACTS . '/ClockResolver.php'], $removed);
        self::assertSame(['ChannelFactory.php', 'OwnResolver.php'], $left, 'a hand-written file and a generated factory stay');
    }

    /** A resolver it cannot read, or a generated one it cannot remove, fails the sync (review, semitexa-core#268). */
    #[Test]
    public function sync_stops_on_a_resolver_it_cannot_read_or_remove(): void
    {
        $root = self::scratchProject();
        $contracts = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS;
        try {
            // Unreadable for root as well: a link to nothing.
            symlink($root . '/gone.php', $contracts . '/LostResolver.php');
            $unreadable = self::thrown(static fn (): array => self::inProject($root, static fn (): array => RegistryContractResolverGenerator::removeGeneratedResolvers()));
            self::assertSame("Cannot read {$contracts}/LostResolver.php, so it cannot be told whether it is a generated resolver to remove.", $unreadable);
            unlink($contracts . '/LostResolver.php');

            file_put_contents($contracts . '/ClockResolver.php', self::generated(" * Resolver for App\\Clock.\n") . "final class ClockResolver {}\n");
            chmod($contracts, 0555);
            try {
                $outcome = self::thrown(static fn (): array => self::inProject($root, static fn (): array => RegistryContractResolverGenerator::removeGeneratedResolvers()));
                $mayDelete = is_writable($contracts);
            } finally {
                chmod($contracts, 0755);
            }
            // Root may delete in a directory at 0555: then the resolver is gone, as usual.
            self::assertSame(
                $mayDelete ? null : "Cannot remove {$contracts}/ClockResolver.php, a generated resolver the container no longer reads.",
                $outcome,
            );
            self::assertSame(!$mayDelete, is_file($contracts . '/ClockResolver.php'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** A contracts directory it cannot list fails the sync too: not an empty result (review, semitexa-core#268). */
    #[Test]
    public function sync_stops_on_a_contracts_directory_it_cannot_list(): void
    {
        $root = self::scratchProject();
        $contracts = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS;
        file_put_contents($contracts . '/ClockResolver.php', self::generated(" * Resolver for App\\Clock.\n") . "final class ClockResolver {}\n");
        try {
            chmod($contracts, 0111);
            try {
                $outcome = self::thrown(static fn (): array => self::inProject($root, static fn (): array => RegistryContractResolverGenerator::removeGeneratedResolvers()));
                $mayList = is_readable($contracts);
            } finally {
                chmod($contracts, 0755);
            }
            // Root may list a directory at 0111: then the resolver is found and removed, as usual.
            self::assertSame($mayList ? null : "Cannot list {$contracts}, so the generated resolvers in it cannot be found and removed.", $outcome);
            self::assertSame(!$mayList, is_file($contracts . '/ClockResolver.php'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** A scratch project ProjectRoot accepts: composer.json and src/modules, or it walks up to the real one. */
    private static function scratchProject(): string
    {
        $root = sys_get_temp_dir() . '/semitexa-registry-decides-' . bin2hex(random_bytes(6));
        mkdir($root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS, 0777, true);
        mkdir($root . '/src/modules', 0777, true);
        file_put_contents($root . '/composer.json', '{}');

        return $root;
    }

    /**
     * Runs $fn with $root as the project root; nothing runs unless it is.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private static function inProject(string $root, \Closure $fn): mixed
    {
        $cwd = getcwd();
        $projectRoot = StaticState::snapshot(ProjectRoot::class);
        chdir($root);
        ProjectRoot::reset();
        try {
            self::assertSame(realpath($root), realpath(ProjectRoot::get()), 'the scratch directory is the project root');

            return $fn();
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
            StaticState::restore($projectRoot);
        }
    }

    /** The message $fn throws as a RuntimeException, or null when it returns. */
    private static function thrown(\Closure $fn): ?string
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function generated(string $more): string
    {
        return "<?php\n\n/**\n * " . RegistryContractResolverGenerator::GENERATED_HEADER . "\n" . $more . " */\n";
    }
}
