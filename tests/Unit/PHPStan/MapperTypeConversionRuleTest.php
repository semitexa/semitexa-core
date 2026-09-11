<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\MapperTypeConversionRule;

/**
 * Locks the detection scope + messaging of MapperTypeConversionRule.
 *
 * The rule refuses a mapper that converts a column type the ORM already
 * converts. Two mappers did, independently, and neither was caught — one of
 * them killed every scheduled job in a consumer's production. These assertions
 * pin the parts a refactor could silently weaken: the contract identifier
 * (flows verbatim into ai:verify NDJSON + baselines), the fact that scope is
 * decided by the MAPPER CONTRACT rather than by file path (so a mapper outside
 * `Application/Db/*` is still covered, and a repository's legitimate conversion
 * is still allowed), and an actionable fix in the message.
 */
final class MapperTypeConversionRuleTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(MapperTypeConversionRule::class))->getFileName();
        self::assertIsString($file, 'Cannot locate rule source file');
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        self::assertStringContainsString(
            "->identifier('semitexa.mapperTypeConversion')",
            $this->source(),
        );
    }

    /**
     * Scope is the contract, not a directory. A mapper that lives somewhere
     * unexpected is still a mapper, and a repository that converts a uuid for a
     * raw WHERE is still correct wherever it sits.
     */
    #[Test]
    public function it_is_scoped_by_the_mapper_contract(): void
    {
        $source = $this->source();

        self::assertStringContainsString(
            'Semitexa\\\\Orm\\\\Domain\\\\Contract\\\\ResourceModelMapperInterface',
            $source,
        );
        self::assertStringContainsString('implementsInterface', $source);
    }

    #[Test]
    public function it_names_both_directions_of_the_conversion(): void
    {
        $source = $this->source();

        self::assertStringContainsString('Semitexa\\\\Orm\\\\Application\\\\Service\\\\Uuid7', $source);
        self::assertStringContainsString('tobytes', $source, 'the write direction');
        self::assertStringContainsString('frombytes', $source, 'the read direction');
    }

    /** An aliased import must not be a way around the rule. */
    #[Test]
    public function it_resolves_the_called_class_against_the_file_scope(): void
    {
        self::assertStringContainsString('resolveName', $this->source());
    }

    /**
     * The message has to say what to do, and it has to protect the half of the
     * mapper that is correct — an author told only «do not convert» deletes the
     * JSON handling beside it.
     */
    #[Test]
    public function the_message_says_what_to_do_and_what_to_keep(): void
    {
        $source = $this->source();

        self::assertStringContainsString('Pass the field straight through', $source);
        self::assertStringContainsString('storage-shape', $source);
        self::assertStringContainsString('raw WHERE', $source);
    }
}
