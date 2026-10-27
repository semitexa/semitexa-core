<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsFeatureFlag;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Feature\DefaultFeatureFlags;
use Semitexa\Core\Feature\FeatureFlag;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Core\Feature\InMemoryFeatureFlags;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;

#[AsFeatureFlag('checkout.new-flow', description: 'One-page checkout')]
#[AsFeatureFlag('search.semantic', default: true)]
#[AsFeatureFlag('Bad Name')]
#[AsFeatureFlag('rollout.half', percent: 50)]
final class FeatureFlagsTestDeclarations
{
}

#[AsFeatureFlag('search.semantic', default: false)]
final class FeatureFlagsTestDuplicate
{
}

final class FeatureFlagsTest extends TestCase
{
    #[Test]
    public function declarations_are_discovered_bad_names_skipped_and_the_first_of_two_kept(): void
    {
        $flags = self::registry()->all();

        self::assertSame(['checkout.new-flow', 'rollout.half', 'search.semantic'], array_keys($flags));
        self::assertTrue($flags['search.semantic']->default, 'the first declaration wins');
        self::assertSame(FeatureFlagsTestDeclarations::class, $flags['search.semantic']->declaredBy);
        self::assertSame('One-page checkout', $flags['checkout.new-flow']->description);
    }

    /** A class discovery named but autoload could not find dropped its flags without a word. */
    #[Test]
    public function a_declaring_class_that_does_not_load_is_said(): void
    {
        $logger = new class implements LoggerInterface {
            /** @var list<array{string, array<string, mixed>}> */
            public array $warnings = [];

            public function error(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
        StaticLoggerBridge::set($logger);
        try {
            $flags = self::registry(['App\\Missing\\FeatureFlagDeclarations', FeatureFlagsTestDuplicate::class])->all();
        } finally {
            StaticLoggerBridge::reset();
        }

        self::assertSame(['search.semantic'], array_keys($flags), 'the classes that load are still read');
        self::assertSame([[
            'A class with feature flag declarations could not be loaded; its flags are ignored',
            ['class' => 'App\\Missing\\FeatureFlagDeclarations', '_channel' => 'feature'],
        ]], $logger->warnings);
    }

    #[Test]
    public function the_default_flags_answer_the_declared_default_and_off_for_an_unknown_flag(): void
    {
        $flags = DefaultFeatureFlags::of(self::registry())->forSubject(null, null);

        self::assertFalse($flags->isEnabled('checkout.new-flow'));
        self::assertTrue($flags->isEnabled('search.semantic'));
        self::assertFalse($flags->isEnabled('never.declared'));
        self::assertSame(
            FeatureFlag::rollsOutTo('rollout.half', 50, 'default'),
            $flags->isEnabled('rollout.half'),
            'a guest on a single-site install is decided by the `default` site',
        );
    }

    /** '' was taken for a user, so a percentage rollout skipped a guest pinned this way. */
    #[Test]
    public function an_empty_user_id_is_no_user(): void
    {
        $registry = self::registry();

        self::assertSame(
            DefaultFeatureFlags::of($registry)->forSubject(null, null)->isEnabled('rollout.half'),
            DefaultFeatureFlags::of($registry)->forSubject(null, '')->isEnabled('rollout.half'),
        );
    }

    /** A pinned '' site skipped the `default` site's bucket and answered the static default. */
    #[Test]
    public function an_empty_site_is_the_default_site(): void
    {
        $registry = FeatureFlagRegistry::of([new FeatureFlag('rollout.site', false, '', 50, self::class)]);
        // A flag the `default` site is in, so a '' that skipped the rollout
        // (the static default, off) answers differently.
        self::assertTrue(FeatureFlag::rollsOutTo('rollout.site', 50, 'default'));

        self::assertTrue(DefaultFeatureFlags::of($registry)->forSubject('', null)->isEnabled('rollout.site'));
        self::assertTrue(DefaultFeatureFlags::of($registry)->forSubject('', '')->isEnabled('rollout.site'));
    }

    #[Test]
    public function a_rollout_keeps_each_subject_in_or_out_and_raising_it_only_adds(): void
    {
        $in10 = $in50 = 0;
        for ($i = 0; $i < 2000; $i++) {
            $subject = 'user-' . $i;
            $at10 = FeatureFlag::rollsOutTo('rollout.half', 10, $subject);
            $at50 = FeatureFlag::rollsOutTo('rollout.half', 50, $subject);
            self::assertSame($at10, FeatureFlag::rollsOutTo('rollout.half', 10, $subject), 'deterministic');
            self::assertTrue(!$at10 || $at50, 'in at 10 % means in at 50 %');
            $in10 += (int) $at10;
            $in50 += (int) $at50;
        }

        self::assertEqualsWithDelta(200, $in10, 60);
        self::assertEqualsWithDelta(1000, $in50, 100);
        self::assertFalse(FeatureFlag::rollsOutTo('x', 0, 'u'));
        self::assertTrue(FeatureFlag::rollsOutTo('x', 100, 'u'));

        $flags = DefaultFeatureFlags::of(self::registry());
        $answers = array_map(static fn (int $i): bool => $flags->forSubject('acme', 'user-' . $i)->isEnabled('rollout.half'), range(0, 199));
        self::assertContains(true, $answers);
        self::assertContains(false, $answers);
    }

    #[Test]
    public function the_in_memory_flags_are_off_unless_set(): void
    {
        $flags = (new InMemoryFeatureFlags(['a' => true]))->enable('b')->disable('a');

        self::assertFalse($flags->isEnabled('a'));
        self::assertTrue($flags->isEnabled('b'));
        self::assertFalse($flags->forSubject('t', 'u')->isEnabled('c'));
    }

    /** @param list<string>|null $classes what discovery names; the two declaring classes when null */
    private static function registry(?array $classes = null): FeatureFlagRegistry
    {
        $registry = new FeatureFlagRegistry();
        (new \ReflectionProperty($registry, 'classDiscovery'))->setValue($registry, new class ($classes ?? [FeatureFlagsTestDeclarations::class, FeatureFlagsTestDuplicate::class]) extends ClassDiscovery {
            /** @param list<string> $classes */
            public function __construct(private readonly array $classes)
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $attributeClass === AsFeatureFlag::class ? $this->classes : [];
            }
        });

        return $registry;
    }
}
