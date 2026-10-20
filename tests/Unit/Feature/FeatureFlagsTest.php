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

    private static function registry(): FeatureFlagRegistry
    {
        $registry = new FeatureFlagRegistry();
        (new \ReflectionProperty($registry, 'classDiscovery'))->setValue($registry, new class () extends ClassDiscovery {
            public function __construct()
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $attributeClass === AsFeatureFlag::class
                    ? [FeatureFlagsTestDeclarations::class, FeatureFlagsTestDuplicate::class]
                    : [];
            }
        });

        return $registry;
    }
}
