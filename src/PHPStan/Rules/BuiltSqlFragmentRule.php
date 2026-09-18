<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
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
 * ## Every spelling of the call
 *
 * `->whereRaw()`, `?->whereRaw()` and `Foo::whereRaw()` are three different
 * parser nodes and one contract. A rule registered on `MethodCall` alone cannot
 * see the nullsafe one — which is exactly the loosely-typed repository code the
 * rule is written for — so it is registered on their common parent and picks
 * the three call shapes out of it.
 *
 * A NULLSAFE call is analysed in more than one scope, so the same
 * `?->whereRaw()` reaches this rule twice and was reported twice — noise for a
 * reader, and two diagnostics against an allowance written for one. Reports are
 * therefore deduplicated by source position, and only when the node carries one:
 * a hand-built node in a unit test has no position and must never be silenced by
 * the previous test's.
 *
 * A first-class callable (`$q->whereRaw(...)`) passes no fragment at all, and
 * asking it for its arguments is fatal: PhpParser's `CallLike::getArgs()`
 * asserts `!isFirstClassCallable()`, so with assertions on the rule dies with
 * an AssertionError (PHPStan reports an internal error and the file stops being
 * checked at all) and with them off it reads a VariadicPlaceholder as an Arg.
 * A security rule that crashes is a security rule that is turned off.
 *
 * @implements Rule<CallLike>
 */
final class BuiltSqlFragmentRule implements Rule
{
    private const METHOD = 'whereRaw';

    /** The fragment's position and name, so a named argument is found too. */
    private const ARGUMENT_POSITION = 0;
    private const ARGUMENT_NAME = 'sql';

    /** @var array<string, true> file:offset of every call already reported */
    private array $reported = [];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
            return [];
        }

        if (!$node->name instanceof Identifier || strcasecmp($node->name->name, self::METHOD) !== 0) {
            return [];
        }

        // `$q->whereRaw(...)` — a reference to the method, not a call of it.
        // There is no fragment to judge, and getArgs() is fatal here.
        if ($node->isFirstClassCallable()) {
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

        if ($this->alreadyReported($node, $scope)) {
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
     * Has this exact call already been reported in this run?
     *
     * PHPStan walks a nullsafe call in more than one scope, so the node arrives
     * here twice. Position is the identity; a node without one — built by hand
     * in a test — is always reported, or one test would silence the next.
     */
    private function alreadyReported(CallLike $node, Scope $scope): bool
    {
        $offset = $node->getAttribute('startFilePos');
        if (!is_int($offset)) {
            return false;
        }

        $key = $scope->getFile() . ':' . $offset;
        if (isset($this->reported[$key])) {
            return true;
        }

        $this->reported[$key] = true;

        return false;
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
