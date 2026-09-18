<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\PHPStan\Rules\UnquotedSqlIdentifierRule;

/**
 * The ratchet that keeps the forty-second interpolation site from reappearing.
 *
 * The rule decides from one string literal, so it is exercised directly rather
 * than through a PHPStan fixture run: every case below is a literal that either
 * did appear in the ORM before SqlIdentifier existed, or is prose that a
 * careless version of this rule would have flagged. The silent cases matter as
 * much as the loud ones — backticks are also Markdown, and a rule that fires on
 * console output gets switched off, after which it catches nothing.
 */
final class UnquotedSqlIdentifierRuleTest extends TestCase
{
    private function fire(string $literal): bool
    {
        return (new UnquotedSqlIdentifierRule())
            ->processNode(new String_($literal), $this->createStub(Scope::class)) !== [];
    }

    /**
     * @param list<string|Variable> $parts strings are literal text, Variables interpolate
     */
    private function fireInterpolated(array $parts): bool
    {
        $nodes = array_map(
            static fn (string|Variable $part) => is_string($part) ? new InterpolatedStringPart($part) : $part,
            $parts,
        );

        return (new UnquotedSqlIdentifierRule())
            ->processNode(new InterpolatedString($nodes), $this->createStub(Scope::class)) !== [];
    }

    /**
     * The exact construct that survived the first version of this rule: the
     * quote character lives in $q, so the literal holds no backtick and the
     * pattern match finds nothing. SyncEngine had four of these left after a
     * sweep the rule had already called clean.
     */
    #[Test]
    public function it_fires_on_a_value_fenced_by_a_variable_quote(): void
    {
        self::assertTrue($this->fireInterpolated([
            'ALTER TABLE ', new Variable('q'), new Variable('tableName'), new Variable('q'),
            ' DROP COLUMN ', new Variable('q'), new Variable('columnName'), new Variable('q'),
        ]));
    }

    #[Test]
    public function it_fires_on_a_backtick_in_an_interpolated_string(): void
    {
        self::assertTrue($this->fireInterpolated([
            'DROP TABLE `', new Variable('tableName'), '` FROM x',
        ]));
    }

    #[Test]
    public function two_different_variables_side_by_side_are_not_a_fence(): void
    {
        self::assertFalse($this->fireInterpolated([
            'SELECT ', new Variable('a'), new Variable('b'), ' FROM t',
        ]));
    }

    #[Test]
    public function an_interpolated_string_that_is_not_sql_stays_silent(): void
    {
        self::assertFalse($this->fireInterpolated([
            'Drop ', new Variable('q'), new Variable('name'), new Variable('q'), ' here.',
        ]));
    }

    #[Test]
    public function a_correctly_built_interpolated_statement_stays_silent(): void
    {
        // What the sweep left behind: the identifier is quoted before it is
        // interpolated, so there is no fence and no literal backtick.
        self::assertFalse($this->fireInterpolated([
            'ALTER TABLE ', new Variable('quotedTable'), ' ADD COLUMN ', new Variable('ddl'),
        ]));
    }

    #[Test]
    #[DataProvider('sqlThatWrapsWithoutEscaping')]
    public function it_fires_on_a_sql_literal_that_quotes_an_interpolated_name(string $literal): void
    {
        self::assertTrue($this->fire($literal), "should have fired on: {$literal}");
    }

    /**
     * Every one of these is a literal that was in semitexa-orm on 2026-09-18,
     * before the sweep.
     *
     * @return iterable<string, array{string}>
     */
    public static function sqlThatWrapsWithoutEscaping(): iterable
    {
        yield 'the countBy() literal that was proven exploitable' => [
            'SELECT `%1$s` AS __g, COUNT(*) AS __c FROM `%2$s`%3$s GROUP BY `%1$s` ORDER BY __c DESC, `%1$s` ASC',
        ];
        yield 'relation loader IN-list' => ['SELECT * FROM `%s` WHERE `%s` IN (%s)'];
        yield 'aggregate' => ['SELECT %s(`%s`) AS __a FROM `%s`%s'];
        yield 'count' => ['SELECT COUNT(*) AS __c FROM `%s`%s'];
        yield 'write engine update' => ['UPDATE `%s` SET %s WHERE `%s` = :__pk%s%s'];
        yield 'write engine delete' => ['DELETE FROM `%s` WHERE `%s` = :__pk%s'];
        yield 'smart upsert' => ['INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s'];
        yield 'ddl foreign key' => [
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`) ON DELETE %s ON UPDATE %s',
        ];
        yield 'ansi quotes count too' => ['SELECT * FROM "%s" WHERE "%s" = ?'];
        yield 'brace interpolation in a double-quoted literal' => ['DROP TABLE `{$tableName}`'];
    }

    #[Test]
    #[DataProvider('literalsItMustLeaveAlone')]
    public function it_stays_silent(string $literal): void
    {
        self::assertFalse($this->fire($literal), "should have been silent on: {$literal}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function literalsItMustLeaveAlone(): iterable
    {
        // OrmDiffCommand prints these to a terminal. They read as SQL and carry
        // backticks, and they are not SQL at all — which is why the rule cannot
        // key on the SQL marker alone, and why this case is here.
        yield 'console echo of a CREATE' => ['+ CREATE TABLE `{$table->name}`'];
        yield 'console echo of an ALTER' => ['~ ALTER TABLE `{$tableName}`'];

        // Markdown, which the workspace emits by the hundred.
        yield 'markdown code span' => ['Resume task `{$top->id}` under epic `{$top->epicId}`.'];
        yield 'markdown list item' => ['- `%s` %s'];

        // Correct SQL: the name is escaped before it gets here.
        yield 'already swept' => ['SELECT * FROM %s WHERE %s IN (%s)'];
        yield 'fully literal sql' => ['SELECT id FROM `orders` WHERE `total` > :min'];

        // Prose with an interpolated identifier but no SQL at all.
        yield 'error message' => ['Column `{$name}` is missing from the model.'];

        // Opens with a statement verb and quotes a placeholder, and is an
        // English sentence. UpdateCommand emits it; the rule found it on the
        // first tree-wide run, which is why the second keyword is required.
        yield 'console error that opens with a verb' => ['Update aborted at stage "%s".'];
        yield 'another verb-initial sentence' => ['Select the `%s` option to continue.'];
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        // It flows verbatim into ai:verify NDJSON and into user baselines, so a
        // rename silently breaks every existing ignore.
        $errors = (new UnquotedSqlIdentifierRule())
            ->processNode(new String_('SELECT * FROM `%s`'), $this->createStub(Scope::class));

        self::assertCount(1, $errors);
        self::assertSame('semitexa.unquotedSqlIdentifier', $errors[0]->getIdentifier());
    }

    #[Test]
    public function the_message_names_the_helper_to_use(): void
    {
        $errors = (new UnquotedSqlIdentifierRule())
            ->processNode(new String_('SELECT * FROM `%s`'), $this->createStub(Scope::class));

        self::assertStringContainsString('SqlIdentifier::quote()', $errors[0]->getMessage());
    }
}
