<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;

/**
 * semitexa.staticStateLifetime
 *
 * A Swoole worker is one PHP process that serves thousands of requests, many of
 * them concurrently in coroutines. A `static` is therefore not "per request" —
 * it is shared by every request and every coroutine until the worker exits.
 * Most of the serious bugs found in the framework had this one root cause:
 * state living in a longer scope than its data (an RBAC decision cache keyed
 * per coroutine instead of per request, so revoked permissions survived on SSE
 * streams; a factory handing every request the same execution-scoped
 * prototype; per-class caches able to hold request objects). Nothing in the
 * code distinguished the static that is correctly worker-wide from the one
 * that is a leak, so review could not either.
 *
 * This rule makes the lifetime explicit. Every `static` property — and every
 * `static $x` local inside a class method — whose type can hold an object or
 * an array must be declared one of:
 *
 *   - #[WorkerState(reason: '...')] on the property: worker-wide on purpose,
 *     with the reason no request data reaches it written next to it;
 *   - request scope: the class registers a reset with
 *     PerRequestStateRegistry::register(), which the framework runs after every
 *     request and queued message. Registration is the declaration — it is
 *     detected per class, because that is how the registry is used.
 *
 * Per-coroutine state has no static form at all: it belongs in CoroutineLocal.
 * A `static $x` local cannot carry an attribute, so the only fix for one is to
 * move it to a declared static property (or into CoroutineLocal).
 *
 * EXEMPT, and why each is safe:
 *   - Scalar types (bool/int/float/string, nullable or not). Flags such as
 *     "warned once" or "initialised", counters, and cached strings cannot hold a
 *     live object or an unbounded graph, which is the failure being targeted:
 *     an aliased request object or a cache that grows with traffic. (A scalar
 *     can still carry a request-derived VALUE; that is a narrower bug this rule
 *     does not claim to catch.)
 *   - Enum types. Enum cases are immutable process-wide singletons.
 *   - \WeakMap. Entries are dropped with their key object, so a cache keyed by
 *     a request-scoped object dies with that object and cannot outlive it.
 *   - Constants and readonly instance properties are not `static` and never
 *     reach this rule.
 *   - A `static $x` local initialised to a bool/int/float/string literal: the
 *     same reasoning as scalar properties (a local has no declared type, so the
 *     initialiser is the best available evidence).
 *
 * NOT exempt: "readonly after boot" registries. Nothing in the language or the
 * framework seals a static, so "only written at boot" is a claim — exactly the
 * kind of claim #[WorkerState(reason)] exists to record.
 *
 * Scope limits: static locals in free functions and closures outside a class
 * are not inspected; anonymous classes are skipped.
 *
 * @implements Rule<ClassLike>
 */
final class StaticStateLifetimeRule implements Rule
{
    private const WORKER_STATE_ATTRIBUTES = [
        WorkerState::class,
        'WorkerState',
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->namespacedName?->toString();
        if ($className === null || !$this->reflectionProvider->hasClass($className)) {
            return [];
        }

        if ($this->registersPerRequestReset($node)) {
            return [];
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        $errors = [];

        foreach ($node->getProperties() as $property) {
            if (!$property->isStatic() || $this->hasWorkerStateAttribute($property)) {
                continue;
            }

            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                $type = $this->propertyType($classReflection, $name);
                if ($type !== null && !$this->canHoldSharedState($type)) {
                    continue;
                }

                $errors[] = $this->propertyError($className, $name, $type, $prop->getStartLine());
            }
        }

        foreach ($node->getMethods() as $method) {
            foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Static_::class) as $static) {
                foreach ($static->vars as $var) {
                    if ($this->isScalarLiteral($var->default) || !is_string($var->var->name)) {
                        continue;
                    }

                    $errors[] = $this->staticLocalError(
                        $className,
                        $method->name->toString(),
                        $var->var->name,
                        $var->getStartLine(),
                    );
                }
            }
        }

        return $errors;
    }

    private function propertyError(string $className, string $name, ?Type $type, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            sprintf(
                'Static property %s::$%s (%s) is mutable state shared by every request and coroutine for the '
                . 'lifetime of the Swoole worker, but does not declare that lifetime. If it is genuinely '
                . 'worker-wide (boot-time registry, metadata keyed by class name), add #[WorkerState(reason: '
                . '\'...\')] saying why no request data can reach it; if it holds per-request state, register '
                . 'its reset with PerRequestStateRegistry::register(); per-coroutine state belongs in '
                . 'CoroutineLocal, not a static.',
                $className,
                $name,
                $type?->describe(VerbosityLevel::typeOnly()) ?? 'mixed',
            ),
        )->identifier('semitexa.staticStateLifetime')->line($line)->build();
    }

    private function staticLocalError(string $className, string $method, string $var, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            sprintf(
                'Static variable $%s in %s::%s() is mutable state that outlives the request on a Swoole worker '
                . 'and cannot carry a lifetime declaration. Move it to a static property with '
                . '#[WorkerState(reason: \'...\')] if it is worker-wide, reset it via '
                . 'PerRequestStateRegistry::register() if it is per-request, or keep it in CoroutineLocal if '
                . 'it is per-coroutine.',
                $var,
                $className,
                $method,
            ),
        )->identifier('semitexa.staticStateLifetime')->line($line)->build();
    }

    private function propertyType(ClassReflection $classReflection, string $name): ?Type
    {
        if (!$classReflection->hasNativeProperty($name)) {
            return null;
        }

        return $classReflection->getNativeProperty($name)->getReadableType();
    }

    /** True when the type can hold an object or array, i.e. is not one of the documented exemptions. */
    private function canHoldSharedState(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        if ($type->isScalar()->yes() || $type->isEnum()->yes()) {
            return false;
        }

        if ((new ObjectType(\WeakMap::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        return true;
    }

    private function isScalarLiteral(?Node\Expr $expr): bool
    {
        if ($expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_ || $expr instanceof Scalar\String_) {
            return true;
        }

        return $expr instanceof ConstFetch
            && in_array(strtolower($expr->name->toString()), ['true', 'false'], true);
    }

    private function hasWorkerStateAttribute(Property $property): bool
    {
        foreach ($property->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (in_array($attr->name->toString(), self::WORKER_STATE_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The class opts into request scope by registering a reset with PerRequestStateRegistry. */
    private function registersPerRequestReset(ClassLike $node): bool
    {
        $call = (new NodeFinder())->findFirst(
            $node->stmts,
            static fn (Node $n): bool => $n instanceof StaticCall
                && $n->class instanceof Name
                && ltrim($n->class->toString(), '\\') === PerRequestStateRegistry::class
                && $n->name instanceof Node\Identifier
                && $n->name->toLowerString() === 'register',
        );

        return $call !== null;
    }
}
