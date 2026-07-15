<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\NoOpMapperRule;

/**
 * Locks the detection scope + messaging of NoOpMapperRule.
 *
 * The rule refuses a #[AsMapper] whose resourceModel and domainModel resolve to
 * the same class — a lazy no-op mapper that clones the persistence resource
 * model back into the domain and collapses the boundary the mapper exists to
 * enforce. These assertions pin the parts a refactor could silently weaken: the
 * contract identifier (flows verbatim into ai:verify NDJSON + baselines), the
 * both-authoring-forms coverage (`Foo::class` and string FQCN), the
 * named-vs-positional argument handling, and an actionable fix in the message.
 */
final class NoOpMapperRuleTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(NoOpMapperRule::class))->getFileName();
        self::assertIsString($file, 'Cannot locate rule source file');
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        self::assertStringContainsString(
            "->identifier('semitexa.noOpMapper')",
            $this->source(),
        );
    }

    #[Test]
    public function it_targets_the_as_mapper_attribute_in_both_spellings(): void
    {
        $source = $this->source();
        self::assertStringContainsString('Semitexa\\\\Orm\\\\Attribute\\\\AsMapper', $source, 'matches the FQCN spelling');
        self::assertStringContainsString("'AsMapper'", $source, 'matches the imported short-name spelling');
    }

    #[Test]
    public function it_reads_both_mapper_arguments(): void
    {
        $source = $this->source();
        self::assertStringContainsString('resourceModel', $source);
        self::assertStringContainsString('domainModel', $source);
    }

    #[Test]
    public function it_handles_both_class_const_and_string_authoring_forms(): void
    {
        $source = $this->source();
        // `Foo::class`
        self::assertStringContainsString('ClassConstFetch', $source, 'resolves the ::class form');
        // string-literal FQCN
        self::assertStringContainsString('Node\\Scalar\\String_', $source, 'resolves the string-literal form');
        // use-aliased names resolved against scope
        self::assertStringContainsString('resolveName', $source, 'resolves aliased names, not raw spelling');
    }

    #[Test]
    public function it_fires_only_when_the_two_models_are_equal(): void
    {
        $source = $this->source();
        // Guard: no error unless resource === domain (and both resolved).
        self::assertStringContainsString('$resource !== $domain', $source);
    }

    #[Test]
    public function the_message_states_the_fix_not_just_the_problem(): void
    {
        $source = $this->source();
        self::assertStringContainsString('domain model', $source, 'names the missing collaborator');
        self::assertStringContainsString('toDomain()', $source, 'points at where to map fields');
        self::assertStringContainsString('toSourceModel()', $source);
        self::assertStringContainsString('no-op', $source, 'names the anti-pattern');
    }
}
