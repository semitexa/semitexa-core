<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\PHPStan\Rules\BuiltSqlFragmentRule;

/**
 * The one door left open, and the shape that tells an open one from a shut one.
 *
 * `whereRaw()` binds its values and concatenates its fragment verbatim, so the
 * ORM is not injectable but a caller who builds the fragment from request data
 * is. What separates the two is not what the string SAYS — that version of the
 * rule flags Markdown and gets switched off within a week, which
 * UnquotedSqlIdentifierRule already paid for — but whether the fragment was
 * written or assembled. These cases are the ones in the repository, plus the
 * injection the rule exists for.
 */
final class BuiltSqlFragmentRuleTest extends TestCase
{
    /** @param list<Arg> $args */
    private function fire(array $args, string $method = 'whereRaw'): bool
    {
        $call = new MethodCall(new Variable('query'), new Identifier($method), $args);

        return (new BuiltSqlFragmentRule())->processNode($call, $this->createStub(Scope::class)) !== [];
    }

    private function arg(Expr $value, ?string $name = null, bool $unpack = false): Arg
    {
        return new Arg($value, false, $unpack, [], $name === null ? null : new Identifier($name));
    }

    /** `[$a, $b]` as a bindings argument — never the thing under test, always present. */
    private function bindings(): Arg
    {
        return $this->arg(new Expr\Array_([]));
    }

    #[Test]
    public function a_written_fragment_is_silent(): void
    {
        // The nine call sites in this repository that are literals — the rule
        // must have nothing to say about any of them, or it is a rule people
        // work around.
        self::assertFalse($this->fire([
            $this->arg(new String_('(`submitted_at` > ? OR (`submitted_at` = ? AND `id` > ?))')),
            $this->bindings(),
        ]));
    }

    #[Test]
    public function a_long_fragment_split_across_literals_is_still_written(): void
    {
        $fragment = new Concat(
            new String_('`status` = ? '),
            new Concat(new String_('AND `score` > ? '), new String_('AND `tenant` = ?')),
        );

        self::assertFalse($this->fire([$this->arg($fragment), $this->bindings()]));
    }

    #[Test]
    public function a_constant_is_as_fixed_as_the_string_it_holds(): void
    {
        // Refusing this pushes people to inline the same SQL in three places,
        // which is worse SQL and no safer.
        $constant = new ClassConstFetch(new Name('self'), new Identifier('CURSOR_FRAGMENT'));

        self::assertFalse($this->fire([$this->arg($constant), $this->bindings()]));
    }

    /**
     * THE INJECTION THIS EXISTS FOR, written the way a consumer writes it:
     * `->whereRaw('`name` = ' . $request->get('q'))`.
     */
    #[Test]
    public function a_fragment_concatenated_with_a_value_is_reported(): void
    {
        $fragment = new Concat(
            new String_('`name` = '),
            new MethodCall(new Variable('request'), new Identifier('get'), [$this->arg(new String_('q'))]),
        );

        self::assertTrue($this->fire([$this->arg($fragment)]));
    }

    #[Test]
    public function an_interpolated_fragment_is_reported(): void
    {
        $fragment = new InterpolatedString([
            new InterpolatedStringPart('`name` = '),
            new Variable('q'),
        ]);

        self::assertTrue($this->fire([$this->arg($fragment), $this->bindings()]));
    }

    #[Test]
    public function a_fragment_built_by_a_call_is_reported(): void
    {
        // implode() is the real in-tree shape (CollectionQueryCompiler), and
        // sprintf() is the one people reach for next. Neither is safe by
        // construction, whatever it happens to contain today.
        $implode = new FuncCall(new Name('implode'), [
            $this->arg(new String_(' OR ')),
            $this->arg(new Variable('branches')),
        ]);
        $sprintf = new FuncCall(new Name('sprintf'), [
            $this->arg(new String_('`%s` = ?')),
            $this->arg(new Variable('column')),
        ]);

        self::assertTrue($this->fire([$this->arg($implode), $this->bindings()]));
        self::assertTrue($this->fire([$this->arg($sprintf), $this->bindings()]));
    }

    #[Test]
    public function a_bare_variable_is_reported(): void
    {
        self::assertTrue($this->fire([$this->arg(new Variable('sql')), $this->bindings()]));
        self::assertTrue($this->fire([$this->arg(new PropertyFetch(new Variable('this'), 'sql'))]));
    }

    /**
     * A named argument puts the fragment somewhere other than first, and a rule
     * that only reads position zero would read the BINDINGS and find an array
     * it has no opinion about — silence, on the call that needed the opinion.
     */
    #[Test]
    public function the_fragment_is_found_when_it_is_named(): void
    {
        self::assertTrue($this->fire([
            $this->arg(new Expr\Array_([]), 'bindings'),
            $this->arg(new Variable('sql'), 'sql'),
        ]));

        self::assertFalse($this->fire([
            $this->arg(new Expr\Array_([]), 'bindings'),
            $this->arg(new String_('`id` = ?'), 'sql'),
        ]));
    }

    /**
     * `->whereRaw(...$parts)` hands the fragment over from an array this rule
     * cannot see into. Unreadable is reported, not skipped: a guard that goes
     * quiet on the shapes it cannot parse is quietest exactly where the code is
     * least ordinary.
     */
    #[Test]
    public function a_spread_argument_is_reported(): void
    {
        self::assertTrue($this->fire([$this->arg(new Variable('parts'), null, true)]));
    }

    #[Test]
    public function another_method_of_the_same_name_shape_is_not_this_rule_s_business(): void
    {
        self::assertFalse($this->fire([$this->arg(new Variable('sql'))], 'where'));
        self::assertFalse($this->fire([$this->arg(new Variable('sql'))], 'whereLike'));
    }

    /**
     * A call with no arguments at all is a type error somebody else reports.
     * This rule inventing a second message for it would put two findings on one
     * line and make neither of them the one to act on.
     */
    #[Test]
    public function a_call_with_no_fragment_says_nothing(): void
    {
        self::assertFalse($this->fire([]));
    }

    /**
     * `->whereRaw()`, `?->whereRaw()` and `Foo::whereRaw()` are three parser
     * nodes and one contract. Registered on MethodCall alone the rule could not
     * see the nullsafe one — the shape most likely to appear in exactly the
     * loosely-typed repository code it is written for.
     */
    #[Test]
    public function a_nullsafe_and_a_static_call_are_the_same_contract(): void
    {
        $built = new Concat(new String_('`name` = '), new Variable('input'));
        $rule = new BuiltSqlFragmentRule();
        $scope = $this->createStub(Scope::class);

        $nullsafe = new NullsafeMethodCall(new Variable('q'), new Identifier('whereRaw'), [$this->arg($built)]);
        $static = new StaticCall(new Name('Builder'), new Identifier('whereRaw'), [$this->arg($built)]);

        self::assertNotSame([], $rule->processNode($nullsafe, $scope), 'a nullsafe call is invisible to this rule');
        self::assertNotSame([], $rule->processNode($static, $scope), 'a static call is invisible to this rule');

        $writtenNullsafe = new NullsafeMethodCall(
            new Variable('q'),
            new Identifier('whereRaw'),
            [$this->arg(new String_('`name` = ?')), $this->bindings()],
        );

        self::assertSame([], $rule->processNode($writtenNullsafe, $scope), 'and a written fragment is still silent');
    }

    /**
     * `$q->whereRaw(...)` is a reference to the method, not a call of it, and
     * asking it for its arguments is FATAL: PhpParser asserts
     * `!isFirstClassCallable()` in getArgs(), so with assertions on the rule
     * dies with an AssertionError — PHPStan reports an internal error and stops
     * checking the file, which is the security rule turning itself off.
     */
    #[Test]
    public function a_first_class_callable_is_not_a_fragment_and_does_not_crash(): void
    {
        $callable = new MethodCall(new Variable('q'), new Identifier('whereRaw'), [new VariadicPlaceholder()]);

        self::assertTrue($callable->isFirstClassCallable(), 'the fixture must be the shape under test');
        self::assertSame([], (new BuiltSqlFragmentRule())->processNode($callable, $this->createStub(Scope::class)));
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        // It travels verbatim into the ai:verify NDJSON and into the
        // AcceptedViolations entries that name it.
        $call = new MethodCall(new Variable('q'), new Identifier('whereRaw'), [$this->arg(new Variable('sql'))]);
        $errors = (new BuiltSqlFragmentRule())->processNode($call, $this->createStub(Scope::class));

        self::assertCount(1, $errors);
        self::assertSame('semitexa.builtSqlFragment', $errors[0]->getIdentifier());
        self::assertStringContainsString('SqlIdentifier::quote()', $errors[0]->getMessage());
    }
}
