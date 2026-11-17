<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Registry\RegistryContractResolverGenerator;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\A\FixedClock as FixedClockA;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\B\FixedClock as FixedClockB;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\B\FixedClockALIAS1 as FixedClockAlias1Named;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\C\FixedClock as FixedClockC;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\D\fixedClock as LowerCaseFixedClock;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\F\ClockResolver as NamedLikeTheResolver;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock;
use Semitexa\Core\Tests\Fixtures\ResolverOrder\SystemClock;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * tk-registry-resolvers-reorder-on-every-boot: a fresh clone deployed by an agent
 * came up with eight committed src/registry/Contracts/*Resolver.php files
 * rewritten, nothing but use lines and constructor parameters reordered. The
 * generator followed discovery order, which differs between machines and
 * filesystems. The active implementation is named separately, so the order only
 * ever decided the bytes, and now it decides nothing.
 */
final class GeneratedResolversDoNotDependOnDiscoveryOrderTest extends TestCase
{
    private const RESOLVER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Registry\Contracts;

        use Semitexa\Core\Tests\Fixtures\ResolverOrder\A\FixedClock;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\B\FixedClock as FixedClockAlias1;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\SystemClock;

        /**
         * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts
         * Resolver for Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock.
         *
         * By default, the active implementation is selected automatically by module 'extends' priority.
         * Override getContract() only if you need to resolve a conflict between competing modules manually.
         */
        final class ClockResolver
        {
            private Clock $contract;

            public function __construct(
                FixedClock $fixedClock,
                FixedClockAlias1 $fixedClock1,
                SystemClock $systemClock
            ) {
                $this->contract = $systemClock;
                $unusedDependencies = [$fixedClock, $fixedClock1];
                unset($unusedDependencies);
            }

            public function getContract(): Clock
            {
                return $this->contract;
            }
        }

        PHP;

    private const THREE_OF_A_NAME = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Registry\Contracts;

        use Semitexa\Core\Tests\Fixtures\ResolverOrder\A\FixedClock;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\B\FixedClock as FixedClockAlias1;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\C\FixedClock as FixedClockAlias2;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock;
        use Semitexa\Core\Tests\Fixtures\ResolverOrder\SystemClock;

        /**
         * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts
         * Resolver for Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock.
         *
         * By default, the active implementation is selected automatically by module 'extends' priority.
         * Override getContract() only if you need to resolve a conflict between competing modules manually.
         */
        final class ClockResolver
        {
            private Clock $contract;

            public function __construct(
                FixedClock $fixedClock,
                FixedClockAlias1 $fixedClock1,
                FixedClockAlias2 $fixedClock2,
                SystemClock $systemClock
            ) {
                $this->contract = $systemClock;
                $unusedDependencies = [$fixedClock, $fixedClock1, $fixedClock2];
                unset($unusedDependencies);
            }

            public function getContract(): Clock
            {
                return $this->contract;
            }
        }

        PHP;

    private string $root = '';
    private string|false $cwd = false;
    /** @var array{class: class-string, values: array<string, mixed>}|null */
    private ?array $projectRoot = null;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->projectRoot = StaticState::snapshot(ProjectRoot::class);
        $this->root = sys_get_temp_dir() . '/semitexa-resolver-order-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/modules', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        if ($this->projectRoot !== null) {
            StaticState::restore($this->projectRoot);
        }
        $resolver = $this->root . '/src/registry/Contracts/ClockResolver.php';
        if (is_file($resolver)) {
            unlink($resolver);
        }
        foreach (['/src/registry/Contracts', '/src/registry', '/src/modules', '/src'] as $dir) {
            if (is_dir($this->root . $dir)) {
                rmdir($this->root . $dir);
            }
        }
        if (is_file($this->root . '/composer.json')) {
            unlink($this->root . '/composer.json');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    #[Test]
    public function every_discovery_order_writes_the_same_resolver(): void
    {
        $orders = [
            [SystemClock::class, FixedClockA::class, FixedClockB::class],
            [FixedClockB::class, SystemClock::class, FixedClockA::class],
            [FixedClockA::class, FixedClockB::class, SystemClock::class],
        ];
        foreach ($orders as $order) {
            RegistryContractResolverGenerator::generateAll([Clock::class => [
                'active' => SystemClock::class,
                'implementations' => array_map(
                    static fn (string $class): array => ['module' => 'Clocks', 'class' => $class],
                    $order,
                ),
            ]]);

            self::assertSame(
                self::RESOLVER,
                file_get_contents($this->root . '/src/registry/Contracts/ClockResolver.php'),
                'discovery order: ' . implode(', ', $order),
            );
        }
    }

    /**
     * Three implementations sharing a short name sat in a row once sorted, and the
     * second and third were both given FixedClockAlias1: PHP refuses the file (review,
     * core#245, with this very discovery order).
     */
    #[Test]
    public function three_implementations_sharing_a_short_name_each_get_their_own_alias(): void
    {
        $resolver = $this->root . '/src/registry/Contracts/ClockResolver.php';
        $orders = [
            [FixedClockA::class, FixedClockB::class, SystemClock::class, FixedClockC::class],
            [FixedClockC::class, FixedClockB::class, FixedClockA::class, SystemClock::class],
            [SystemClock::class, FixedClockA::class, FixedClockC::class, FixedClockB::class],
        ];
        foreach ($orders as $order) {
            RegistryContractResolverGenerator::generateAll([Clock::class => [
                'active' => SystemClock::class,
                'implementations' => array_map(
                    static fn (string $class): array => ['module' => 'Clocks', 'class' => $class],
                    $order,
                ),
            ]]);

            self::assertSame(self::THREE_OF_A_NAME, file_get_contents($resolver), 'discovery order: ' . implode(', ', $order));
        }

        // PHP itself accepts the file: two imports under one name fail to compile.
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($resolver) . ' 2>&1', $lint, $exit);
        self::assertSame(0, $exit, implode("\n", $lint));
    }

    /**
     * PHP compares imported names case-insensitively, and the class the file declares
     * holds its name too (review, core#245, round 2: FixedClock and fixedClock, and a
     * class literally named FixedClockALIAS1 next to a generated FixedClockAlias1).
     */
    #[Test]
    public function names_in_the_generated_file_are_unique_the_way_php_compares_them(): void
    {
        $base = 'Semitexa\\Core\\Tests\\Fixtures\\ResolverOrder\\';
        $cases = [
            'one name in two cases' => [
                [FixedClockA::class, LowerCaseFixedClock::class],
                ["use {$base}A\\FixedClock;", "use {$base}Clock;", "use {$base}D\\fixedClock as fixedClockAlias1;"],
            ],
            'a class named like a generated alias' => [
                [FixedClockA::class, FixedClockAlias1Named::class, FixedClockC::class],
                ["use {$base}A\\FixedClock;", "use {$base}B\\FixedClockALIAS1;", "use {$base}C\\FixedClock as FixedClockAlias2;", "use {$base}Clock;"],
            ],
            'a class named like the resolver itself' => [
                [FixedClockA::class, NamedLikeTheResolver::class],
                ["use {$base}A\\FixedClock;", "use {$base}Clock;", "use {$base}F\\ClockResolver as ClockResolverAlias1;"],
            ],
        ];
        $resolver = $this->root . '/src/registry/Contracts/ClockResolver.php';
        foreach ($cases as $case => [$implementations, $uses]) {
            RegistryContractResolverGenerator::generateAll([Clock::class => [
                'active' => FixedClockA::class,
                'implementations' => array_map(
                    static fn (string $class): array => ['module' => 'Clocks', 'class' => $class],
                    $implementations,
                ),
            ]]);

            $source = (string) file_get_contents($resolver);
            preg_match_all('/^use [^;]+;$/m', $source, $lines);
            self::assertSame($uses, $lines[0], $case);
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($resolver) . ' 2>&1', $lint, $exit);
            self::assertSame(0, $exit, $case . ': ' . implode("\n", $lint));
            $lint = [];
        }
    }
}
