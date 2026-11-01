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
    protected function getRule(): Rule
    {
        return new MapperTypeConversionRule();
    }

    /**
     * One declaration per file under its PSR-4 path (Fixtures/<Class>.php), so
     * the autoloader finds every class PHPStan reflects. Once they were several
     * classes in one file, which PSR-4 cannot load: unloaded, the rule saw no
     * class and reported nothing, a green test proving the opposite of what it
     * claims; the file had to be required by hand.
     *
     * @return list<string>
     */
    private static function fixtures(): array
    {
        $files = array_map(static fn (string $class): string => __DIR__ . '/Fixtures/' . $class . '.php', ['FlaggedMapper', 'AliasedMapper', 'ShapeOnlyMapper', 'ArticleRepository']);
        foreach ($files as $file) {
            self::assertFileExists($file, 'the fixtures moved: this test would check nothing');
        }
        foreach ($files as $file) {
            // Autoloaded by PSR-4: a file emptied or holding another class fails here,
            // so a fixture expected to report nothing cannot pass by being empty.
            $name = 'Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name), "{$name} is not declared in {$file}");
        }

        return $files;
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

        foreach ($this->gatherAnalyserErrors(self::fixtures()) as $error) {
            $reported[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getMessage();
            self::assertSame('semitexa.mapperTypeConversion', $error->getIdentifier());
        }
        ksort($reported);

        self::assertSame(['AliasedMapper.php:16', 'FlaggedMapper.php:25', 'FlaggedMapper.php:30'], array_keys($reported));

        self::assertStringStartsWith(
            'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\FlaggedMapper is a mapper and calls '
            . 'Semitexa\Orm\Application\Service\Uuid7::fromBytes()',
            $reported['FlaggedMapper.php:25'],
        );
        self::assertStringStartsWith(
            'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\AliasedMapper is a mapper and calls '
            . 'Semitexa\Orm\Application\Service\Uuid7::fromBytes()',
            $reported['AliasedMapper.php:16'],
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
        foreach ($this->gatherAnalyserErrors(self::fixtures()) as $error) {
            $byLine[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getMessage();
        }

        self::assertStringContainsString('Expected 16 bytes, got 36', $byLine['FlaggedMapper.php:25']);
        // Positive first: an empty message would pass the negative one alone.
        self::assertStringContainsString('already-converted value', $byLine['FlaggedMapper.php:30']);
        self::assertStringNotContainsString('Expected 16 bytes, got 36', $byLine['FlaggedMapper.php:30']);
    }

    /** The reason differs by direction as well (review of core#171). */
    public function testTheRationaleMatchesTheDirectionOfTheCall(): void
    {
        $tips = [];
        foreach ($this->gatherAnalyserErrors(self::fixtures()) as $error) {
            $tips[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getTip();
        }
        $rule = new \ReflectionClass(MapperTypeConversionRule::class);

        self::assertSame($rule->getConstant('RATIONALE'), $tips['FlaggedMapper.php:25']);
        self::assertSame($rule->getConstant('TO_BYTES_RATIONALE'), $tips['FlaggedMapper.php:30']);
    }

    /**
     * Nothing else in that file is reported. The mapper that only reshapes JSON
     * is doing what a mapper owns, and the repository binds a raw WHERE value
     * that nothing hydrates — both must stay out of scope, and the rule decides
     * that from the CONTRACT rather than from a directory.
     */
    public function testTheContractIsTheScopeAndNotTheDirectory(): void
    {
        $errors = $this->gatherAnalyserErrors(self::fixtures());

        self::assertCount(3, $errors);

        foreach ($errors as $error) {
            self::assertStringNotContainsString('ShapeOnlyMapper', $error->getMessage());
            self::assertStringNotContainsString('ArticleRepository', $error->getMessage());
        }
    }
}
