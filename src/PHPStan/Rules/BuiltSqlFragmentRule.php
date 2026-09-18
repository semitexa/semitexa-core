<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.builtSqlFragment
 *
 * A `whereRaw()` fragment that was BUILT rather than WRITTEN.
 *
 * `whereRaw()` is the one door in the ORM an injection can still come through,
 * and it comes from the caller. The bindings are bound; the fragment is
 * concatenated into the statement exactly as given:
 *
 *     ->whereRaw('`status` = ? AND `score` > ?', [$status, $score])   // fine
 *     ->whereRaw('`name` = ' . $request->get('q'))                    // injection
 *
 * A literal cannot carry request data, so the shape that distinguishes the two
 * is whether the fragment is a constant expression. That is what this checks —
 * not what the string contains, which is the version of this rule that flags
 * console output and gets switched off (see {@see UnquotedSqlIdentifierRule}
 * for where that was already learned).
 *
 * ## By method name, deliberately
 *
 * No type lookup: the contract belongs to the METHOD, and it is the same
 * contract wherever `whereRaw()` is declared — Semitexa's two (ResourceModelQuery
 * and WhereTrait) and every other query builder that ships one. A rule that
 * first had to prove the receiver's type would say nothing whenever the type is
 * unknown, which is exactly the loosely-typed repository code most likely to be
 * assembling SQL.
 *
 * ## What counts as written
 *
 * A literal, literals concatenated together, and a constant — `self::FRAGMENT`
 * is as fixed as the string it holds, and refusing it would push people to
 * inline the same SQL in three places. Everything else is reported:
 * interpolation, sprintf(), implode(), a variable, a spread. Some of those are
 * safe in fact; none of them is safe by construction, and the entry in
 * {@see \Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\AcceptedViolations}
 * is where a call that has been looked at says so, with its reason.
 *
 * @implements Rule<MethodCall>
 */
final class BuiltSqlFragmentRule implements Rule
{
    private const METHOD = 'whereRaw';

    /** The fragment's position and name, so a named argument is found too. */
    private const ARGUMENT_POSITION = 0;
    private const ARGUMENT_NAME = 'sql';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }

        if (!$node->name instanceof Identifier || strcasecmp($node->name->name, self::METHOD) !== 0) {
            return [];
        }

        $fragment = self::fragmentArgument($node->getArgs());

        // A call with no fragment at all is somebody else's error to report.
        if ($fragment === null) {
            return [];
        }

        if (self::isWritten($fragment)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'whereRaw() is given a fragment that was built rather than written. The bindings are bound; '
                . 'the fragment is concatenated into the statement verbatim, so anything that reached it from '
                . 'a request is SQL. Write the fragment as a literal, put every VALUE in a ? placeholder, and '
                . 'pass every identifier that is not written out by hand through '
                . 'Semitexa\\Orm\\Adapter\\SqlIdentifier::quote(). If this call composes its fragment from '
                . 'identifiers that already went through SqlIdentifier, record it in AcceptedViolations with '
                . 'that reason instead of leaving the next reader to work it out.',
            )
                ->identifier('semitexa.builtSqlFragment')
                ->build(),
        ];
    }

    /**
     * @param list<Arg> $args
     */
    private static function fragmentArgument(array $args): ?Expr
    {
        foreach ($args as $position => $arg) {
            if ($arg->name instanceof Identifier) {
                if ($arg->name->name === self::ARGUMENT_NAME) {
                    return $arg->value;
                }
                continue;
            }

            // `->whereRaw(...$parts)` — the fragment arrives from an array this
            // rule cannot see into, which is reported rather than skipped.
            if ($arg->unpack) {
                return $arg->value;
            }

            if ($position === self::ARGUMENT_POSITION) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * Is this expression fixed at the point the code was written?
     *
     * Concatenation is walked rather than rejected: `'WHERE a = ?' . ' AND b = ?'`
     * is two literals and one statement, and a wrapped long fragment is how
     * most of them are actually written.
     */
    private static function isWritten(Expr $expr): bool
    {
        if ($expr instanceof String_ || $expr instanceof ClassConstFetch || $expr instanceof ConstFetch) {
            return true;
        }

        if ($expr instanceof Concat) {
            return self::isWritten($expr->left) && self::isWritten($expr->right);
        }

        return false;
    }
}
