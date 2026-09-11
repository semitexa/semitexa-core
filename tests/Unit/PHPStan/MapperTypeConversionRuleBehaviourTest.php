<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\MapperTypeConversionRule;

/**
 * Runs MapperTypeConversionRule over real code and checks what it reports.
 *
 * Its sibling {@see MapperTypeConversionRuleTest} reads the rule's SOURCE —
 * useful for pinning the identifier and the wording, useless for proving the
 * rule fires. A rule whose processNode() returned `[]` unconditionally would
 * pass every assertion in that file, which is the same defect the rule itself
 * exists to catch: a check that cannot fail.
 *
 * @extends RuleTestCase<MapperTypeConversionRule>
 */
final class MapperTypeConversionRuleBehaviourTest extends RuleTestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/MapperConversions.php';

    protected function getRule(): Rule
    {
        return new MapperTypeConversionRule();
    }

    /**
     * The fixture declares several classes in one file, so PSR-4 cannot
     * autoload it and PHPStan's reflection would report every class in it as
     * unknown — which makes the rule see no class at all and report nothing,
     * i.e. a green test proving the opposite of what it claims. Loading the
     * file puts the symbols where PHPStan's autoload source locator can find
     * them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/Fixtures/MapperConversions.php';
    }

    /**
     * Both directions, on a mapper, including through an alias.
     *
     * The aliased case is the one worth having: `use Uuid7 as Identifier` is
     * how the same call escapes a grep, and the rule resolves the name against
     * the file scope rather than matching text.
     */
    public function testItFlagsBothConversionsOnAMapperIncludingThroughAnAlias(): void
    {
        $reported = [];

        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            $reported[$error->getLine()] = $error->getMessage();
            self::assertSame('semitexa.mapperTypeConversion', $error->getIdentifier());
        }

        self::assertSame([25, 30, 39], array_keys($reported));

        self::assertStringStartsWith(
            'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\FlaggedMapper is a mapper and calls '
            . 'Semitexa\Orm\Application\Service\Uuid7::fromBytes()',
            $reported[25],
        );
        self::assertStringStartsWith(
            'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\AliasedMapper is a mapper and calls '
            . 'Semitexa\Orm\Application\Service\Uuid7::fromBytes()',
            $reported[39],
            'an aliased import is the same call and must be resolved, not matched as text',
        );
    }

    /**
     * The consequence named in the message matches the direction of the call.
     *
     * `fromBytes()` on an already-converted column throws, and the message may
     * say so. `toBytes()` does not: handed the canonical string it returns 16
     * bytes quite happily, and the damage is one step later. Quoting that
     * exception for both would send the reader hunting for something that never
     * happened.
     */
    public function testTheDiagnosticMatchesTheDirectionOfTheCall(): void
    {
        $byLine = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            $byLine[$error->getLine()] = $error->getMessage();
        }

        self::assertStringContainsString('Expected 16 bytes, got 36', $byLine[25]);
        self::assertStringNotContainsString('Expected 16 bytes, got 36', $byLine[30]);
        self::assertStringContainsString('already-converted value', $byLine[30]);
    }

    /**
     * Nothing else in that file is reported. The mapper that only reshapes JSON
     * is doing what a mapper owns, and the repository binds a raw WHERE value
     * that nothing hydrates — both must stay out of scope, and the rule decides
     * that from the CONTRACT rather than from a directory.
     */
    public function testTheContractIsTheScopeAndNotTheDirectory(): void
    {
        $errors = $this->gatherAnalyserErrors([self::FIXTURE]);

        self::assertCount(3, $errors);

        foreach ($errors as $error) {
            self::assertStringNotContainsString('ShapeOnlyMapper', $error->getMessage());
            self::assertStringNotContainsString('ArticleRepository', $error->getMessage());
        }
    }
}
