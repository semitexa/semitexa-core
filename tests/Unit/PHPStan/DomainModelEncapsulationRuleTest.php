<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\DomainModelEncapsulationRule;

/**
 * Locks the detection scope + messaging of DomainModelEncapsulationRule.
 *
 * The rule enforces encapsulation on the domain model a #[AsMapper] maps to
 * (via `domainModel:`): all fields private, reachable only through accessors,
 * readonly-aware. These assertions pin the parts a refactor could silently
 * weaken: the contract identifier (flows verbatim into ai:verify NDJSON), the
 * discriminator (mapper's domainModel, excluding no-op + ORM resource models),
 * the readonly-aware getter/setter contract, and actionable messages.
 */
final class DomainModelEncapsulationRuleTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(DomainModelEncapsulationRule::class))->getFileName();
        self::assertIsString($file, 'Cannot locate rule source file');
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        self::assertStringContainsString(
            "->identifier('semitexa.domainModelEncapsulation')",
            $this->source(),
        );
    }

    #[Test]
    public function it_discovers_the_domain_model_via_as_mapper(): void
    {
        $source = $this->source();
        self::assertStringContainsString('Semitexa\\\\Orm\\\\Attribute\\\\AsMapper', $source, 'matches the canonical FQCN');
        self::assertStringContainsString('resolveName', $source, 'resolves the attribute name so aliased imports still match');
        self::assertStringContainsString('domainModel', $source);
    }

    #[Test]
    public function it_excludes_the_no_op_and_orm_resource_cases(): void
    {
        $source = $this->source();
        // No-op (domainModel == resourceModel) belongs to NoOpMapperRule.
        self::assertStringContainsString('strcasecmp', $source, 'skips the no-op case');
        // Resource models carry #[FromTable] and must not be treated as domain models.
        self::assertStringContainsString('Semitexa\\\\Orm\\\\Attribute\\\\FromTable', $source);
    }

    #[Test]
    public function it_forbids_non_private_fields(): void
    {
        $source = $this->source();
        self::assertStringContainsString('isPrivate()', $source);
        self::assertStringContainsString('must be private', $source, 'message names the required visibility');
    }

    #[Test]
    public function it_requires_a_getter_for_every_field(): void
    {
        $source = $this->source();
        self::assertStringContainsString("'get'", $source);
        self::assertStringContainsString("'is'", $source, 'accepts boolean is-getters');
        self::assertStringContainsString("'has'", $source, 'accepts has-getters');
        self::assertStringContainsString('no getter', $source);
    }

    #[Test]
    public function it_requires_a_setter_only_for_mutable_fields(): void
    {
        $source = $this->source();
        // readonly-aware: setter demanded only when the property is NOT readonly.
        self::assertStringContainsString('isReadOnly()', $source);
        self::assertStringContainsString("'set'", $source);
        self::assertStringContainsString("'with'", $source, 'accepts immutable-style withers');
        self::assertStringContainsString('without a setter', $source);
    }

    #[Test]
    public function it_ignores_static_and_inherited_properties(): void
    {
        $source = $this->source();
        self::assertStringContainsString('isStatic()', $source);
        self::assertStringContainsString('getDeclaringClass()', $source, 'only the model\'s own fields');
    }
}
