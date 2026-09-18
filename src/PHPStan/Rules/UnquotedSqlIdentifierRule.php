<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.unquotedSqlIdentifier
 *
 * A SQL string that wraps an interpolated value in identifier quotes itself,
 * instead of passing it through SqlIdentifier.
 *
 * Wrapping is not escaping. `%s` around a name that happens to contain a
 * backtick lets the name close the quote, and the rest of it is parsed as SQL.
 * That is not theoretical: a ColumnRef built by hand rather than through
 * ColumnRef::for() turned a countBy() into
 *   SELECT `name` , (SELECT 1) AS x -- ` AS __g ... GROUP BY `name` , (SELECT 1) AS x -- `
 * The ORM already had the right escaping — as five private copies covering
 * five of the forty-one places that needed it. SqlIdentifier is now the one
 * copy, and this rule is what keeps the forty-second from reintroducing it.
 *
 * Deliberately narrow: the literal must START with a statement verb. Backticks
 * are also Markdown, and the workspace is full of `{$var}` in help text,
 * console output and generated docs. Two whole classes of false positive are
 * excluded by the anchor alone — English prose that happens to contain "from"
 * or "where", and OrmDiffCommand, which PRINTS "+ CREATE TABLE `{$name}`" to a
 * terminal and is not building SQL at all.
 *
 * The cost of the anchor is that a bare fragment — ' AND `%s` = :x', or an
 * inline DDL line — does not trip it. Catching those reliably means reasoning
 * about the sink rather than the literal, which is a different rule. This one
 * covers every statement the ORM composes, and a rule that fires on Markdown
 * gets switched off within a week, after which it catches nothing at all.
 *
 * @implements Rule<Scalar>
 */
final class UnquotedSqlIdentifierRule implements Rule
{
    /**
     * The literal must BEGIN with one of these — leading whitespace aside — to
     * count as a statement being built rather than as prose that mentions one.
     *
     * Anchoring is the whole difference between a rule people keep and a rule
     * people disable. Searching anywhere in the string flagged
     * 'Column `{$name}` is missing from the model.' on " FROM ", and
     * '+ CREATE TABLE `{$table->name}`' — which OrmDiffCommand PRINTS to a
     * terminal — on "CREATE TABLE". English is full of from, where, update,
     * select and delete; SQL statements built in this codebase are not full of
     * leading prose.
     */
    private const STATEMENT_HEADS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE',
        'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
    ];

    /**
     * A real statement carries a second keyword. Requiring one is what keeps
     * 'Update aborted at stage "%s".' out — it opens with a statement verb,
     * and an ANSI double quote around a placeholder is ordinary punctuation in
     * English, far more often than it is an identifier.
     */
    private const STATEMENT_BODY = [
        ' FROM ', ' INTO ', ' SET ', ' WHERE ', ' TABLE ', ' VALUES', ' INDEX ', ' JOIN ',
    ];

    /**
     * An identifier quote wrapped around something that is not a literal name:
     * a printf placeholder, or a string-interpolated expression. Both dialects
     * the ORM emits — MySQL's backtick and the ANSI double quote SyncEngine
     * uses on its SQLite branch.
     */
    private const INTERPOLATION_IN_QUOTES =
        '/(?:[`"](?:%[0-9]*\$?s|\{\$|\$[A-Za-z_])|(?:%[0-9]*\$?s|\})[`"])/';

    public function getNodeType(): string
    {
        // Both halves, and the second is not optional. A first cut looked only
        // at String_ and reported the tree clean while SyncEngine still had
        // four "ALTER TABLE {$q}{$name}{$q}" left in it: an interpolated
        // double-quoted string is an InterpolatedString node, and a rule that
        // cannot see one is a rule that says yes to the construct it exists to
        // forbid.
        return Scalar::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $value = match (true) {
            $node instanceof String_            => $node->value,
            $node instanceof InterpolatedString => self::render($node),
            default                             => null,
        };

        if ($value === null) {
            return [];
        }

        if (!self::looksLikeSql($value)) {
            return [];
        }

        $quotedInLiteral = preg_match(self::INTERPOLATION_IN_QUOTES, $value) === 1;
        $quotedByVariable = $node instanceof InterpolatedString && self::wrapsInAVariableQuote($node, $scope);

        if (!$quotedInLiteral && !$quotedByVariable) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'SQL identifier is wrapped in quotes in the format string instead of being escaped. '
                . 'Backticking a name does not escape it — a backtick inside the name closes the quote '
                . 'and the rest is parsed as SQL. If the quoted thing is an IDENTIFIER (a table or column '
                . 'name), drop the quotes from the literal and pass it through '
                . 'Semitexa\\Orm\\Adapter\\SqlIdentifier::quote() (or quoteAll/quoteQualified). If it is '
                . 'a VALUE — `WHERE name = \'%s\'` — neither quoting nor SqlIdentifier is the answer: bind '
                . 'it as a parameter and leave a placeholder in the statement. This rule reads one string '
                . 'literal and cannot tell the two positions apart, which is why it names both.',
            )
                ->identifier('semitexa.unquotedSqlIdentifier')
                ->build(),
        ];
    }

    /**
     * The shape "{$q}{$name}{$q}" — a value fenced by the SAME variable on both
     * sides, which is how SyncEngine wrote dialect-aware DDL: $q holds the
     * quote character, so the literal contains no backtick at all and the
     * pattern above cannot see it. This is the form that got past a first cut
     * of this rule, which is why it is worth the extra pass.
     *
     * Only a plain variable counts as the fence. A property or a call could be
     * anything, and matching those would start flagging "{$a}{$b}{$a}" in
     * strings that are not quoting anything.
     */
    private static function wrapsInAVariableQuote(InterpolatedString $node, Scope $scope): bool
    {
        $parts = $node->parts;

        for ($i = 0, $n = count($parts) - 2; $i < $n; $i++) {
            $open = $parts[$i];
            $inner = $parts[$i + 1];
            $close = $parts[$i + 2];

            if (
                $open instanceof Node\Expr\Variable
                && $close instanceof Node\Expr\Variable
                && is_string($open->name)
                && $open->name === $close->name
                && !$inner instanceof InterpolatedStringPart
                && !self::isKnownNotToBeAQuote($open, $scope)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the analyser KNOW this variable holds something that is not a quote?
     *
     * `"SELECT {$pad}{$column}{$pad} FROM users"` with `$pad = ' '` has the
     * shape of a fence and is ordinary SQL, and the rule reported it. When
     * PHPStan can resolve the variable to a constant string, that settles it.
     *
     * ONLY A KNOWN VALUE EXCLUDES. An unresolvable variable still reports, and
     * that is the whole point: `$q` in SyncEngine holds the dialect's quote
     * character, chosen at runtime, and it is the case this fence was written
     * for. A rule that went quiet whenever the type was unknown would be quiet
     * exactly where the SQL is built dynamically.
     */
    private static function isKnownNotToBeAQuote(Node\Expr\Variable $variable, Scope $scope): bool
    {
        $constants = $scope->getType($variable)->getConstantStrings();
        if ($constants === []) {
            return false;
        }

        foreach ($constants as $constant) {
            if (in_array($constant->getValue(), ['`', '"'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An interpolated string as one flat literal, each interpolated expression
     * standing in as `{$}` — enough for the quote-around-a-value test, and it
     * deliberately does not try to evaluate anything.
     */
    private static function render(InterpolatedString $node): string
    {
        $out = '';

        foreach ($node->parts as $part) {
            $out .= $part instanceof InterpolatedStringPart ? $part->value : '{$}';
        }

        return $out;
    }

    private static function looksLikeSql(string $value): bool
    {
        // Whitespace runs collapse first. The checks below compare against
        // ' FROM ' and 'SELECT ' with literal spaces, so a statement wrapped
        // across lines — `SELECT\n* FROM `%s`` — did not look like SQL at all
        // and skipped the rule entirely. Formatting is not a reason to stop
        // checking a statement.
        $upper = (string) preg_replace('/\s+/', ' ', strtoupper(ltrim($value)));

        $opens = false;
        foreach (self::STATEMENT_HEADS as $verb) {
            if (str_starts_with($upper, $verb . ' ')) {
                $opens = true;
                break;
            }
        }

        if (!$opens) {
            return false;
        }

        foreach (self::STATEMENT_BODY as $keyword) {
            if (str_contains($upper, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
