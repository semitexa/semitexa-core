<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\InjectionViaConstructorRule;

/**
 * Locks in the messaging and detection invariants of InjectionViaConstructorRule.
 *
 * The rule has historically been misread as "constructors are forbidden".
 * These assertions prevent regression of wording and scope: the rule must
 * communicate that constructor *injection* is forbidden (not constructors
 * themselves), and must only target container-managed attribute classes.
 */
final class InjectionViaConstructorRuleTest extends TestCase
{
    /**
     * The PHPStan identifier is contract-like — changing it would break user
     * ignores / baselines. It must name the violation accurately.
     */
    public function testRuleIdentifierNamesInjectionNotConstructors(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(InjectionViaConstructorRule::class))->getFileName()
                ?: self::fail('Cannot locate rule source file'),
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            "->identifier('semitexa.injectionViaConstructor')",
            $source,
            'Rule identifier must be semitexa.injectionViaConstructor '
            . '(it describes constructor-based injection, not constructors as such).',
        );

        self::assertStringNotContainsString(
            'semitexa.forbiddenConstructor',
            $source,
            'Old identifier semitexa.forbiddenConstructor must not resurface — '
            . 'it mislabels the rule as banning constructors.',
        );
    }

    /**
     * The error message reaches contributors directly. It MUST name the
     * forbidden thing (constructor injection) and MUST explicitly clarify
     * that constructors themselves are not banned — otherwise the wording
     * regresses to the "constructors forbidden" misreading that prompted this
     * rule's rename.
     */
    public function testErrorMessageDistinguishesInjectionFromConstructors(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(InjectionViaConstructorRule::class))->getFileName()
                ?: self::fail('Cannot locate rule source file'),
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            'Constructor injection is not the DI channel',
            $source,
            'Error message must name constructor injection as the violation.',
        );

        self::assertStringContainsString(
            'Constructors themselves are not banned',
            $source,
            'Error message must explicitly clarify that constructors are not globally forbidden.',
        );

        self::assertStringContainsString(
            '#[InjectAsReadonly]',
            $source,
            'Error message must point users at the correct property-injection attributes.',
        );
    }

    /**
     * Detection scope: the rule only targets container-managed framework
     * classes. Value objects, DTOs, payloads, resources — anything without
     * these attributes — must not be in the rule's target set.
     */
    public function testContainerManagedAttributeSetIsExhaustive(): void
    {
        $reflection = new ReflectionClass(InjectionViaConstructorRule::class);
        $constants = $reflection->getConstant('CONTAINER_MANAGED_ATTRIBUTES');

        self::assertIsArray($constants);
        self::assertSame(
            [
                \Semitexa\Core\Attribute\AsService::class,
                'Semitexa\\Orm\\Attribute\\AsRepository',
                \Semitexa\Core\Attribute\AsPayloadHandler::class,
                \Semitexa\Core\Attribute\AsEventListener::class,
                \Semitexa\Core\Attribute\AsPipelineListener::class,
                \Semitexa\Core\Attribute\AsServerLifecycleListener::class,
                \Semitexa\Core\Attribute\AsCommand::class,
                \Semitexa\Core\Attribute\SatisfiesServiceContract::class,
                \Semitexa\Core\Attribute\SatisfiesRepositoryContract::class,
            ],
            $constants,
            'If this list changes, update packages/semitexa-docs/docs/workspace/DI_ONE_WAY.md so contributors '
            . 'know which attributes mark a class as container-managed.',
        );
    }
}
