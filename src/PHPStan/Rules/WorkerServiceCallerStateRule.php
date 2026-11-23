<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\WorkerState;

/**
 * semitexa.workerServiceCallerState
 *
 * A plain #[AsService] (or contract-satisfying) class is a WORKER SINGLETON: one
 * instance serves every request and every coroutine on the worker. A property of
 * it that a method fills from its own parameter therefore keeps what ONE caller
 * passed and shows it to all the others: the tenant a handler set is the tenant
 * the next request reads, and under Swoole a concurrent one reads it mid-flight.
 *
 * The signal is deliberately narrow, so that a hit is worth reading: a property
 *   - of a container-managed class that is not cloned per request
 *     (the same notion as {@see WorkerServiceConnectionHandleRule}),
 *   - carrying no #[InjectAs*] and no #[Config] (the container owns those),
 *   - assigned through `$this`, directly or as an array element, an expression
 *     that reads a parameter of the enclosing method,
 *   - anywhere but `__construct()` and `initialize()`, which run once per
 *     instance, before any request.
 *
 * Two fixes. Request data does not belong on the instance at all: read it when
 * the method runs, from the coroutine-local context (tenant, auth, locale) or
 * from the arguments. A value that really is one per worker (a registry filled
 * at boot, a store wired at worker start, a test seam) says so with
 * #[WorkerState('why')] on the property, and the rule leaves it alone.
 *
 * BLIND SPOTS, stated so nobody reads silence as proof:
 *   - a service that reads the context itself and memoises it (no parameter on
 *     the right-hand side) is not reported;
 *   - a parameter copied to a local first (`$t = $tenant; $this->t = $t;`) is
 *     not followed;
 *   - a parameter used only as the array key (`$this->seen[$tenant] = true`) is
 *     not reported;
 *   - writes from a trait's method, through another variable than `$this`, and
 *     to static properties ({@see StaticStateLifetimeRule} owns those) are not
 *     inspected.
 *
 * @implements Rule<InClassNode>
 */
final class WorkerServiceCallerStateRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: an #[AsService] is one instance per worker, so a property a method fills from its parameter holds one caller\'s value for every request and coroutine that follows. Learned 2026-11-22: a service keeping `$tenant` between two calls gave 168 to 184 of 400 concurrent answers the other tenant\'s name, and neither lint:di nor PHPStan reported it.';

    private const CONTAINER_MANAGED_ATTRIBUTES = [
        AsService::class,
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
        'Semitexa\\Orm\\Attribute\\AsRepository',
        'AsService',
        'SatisfiesServiceContract',
        'SatisfiesRepositoryContract',
        'AsRepository',
    ];

    private const SCOPING_ATTRIBUTES = [
        ExecutionScoped::class,
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        'ExecutionScoped',
        'AsPayloadHandler',
        'AsEventListener',
        'AsPipelineListener',
    ];

    /** The container fills these; what they hold is its business, not a caller's. */
    private const CONTAINER_OWNED_ATTRIBUTES = [
        InjectAsReadonly::class,
        InjectAsMutable::class,
        InjectAsFactory::class,
        Config::class,
    ];

    /** Run once per instance, before the first request reaches it. */
    private const INITIALISING_METHODS = ['__construct', 'initialize'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        if ($classReflection->isAnonymous()) {
            return [];
        }

        $nativeReflection = $classReflection->getNativeReflection();
        if (!$this->hasAnyAttribute($nativeReflection, self::CONTAINER_MANAGED_ATTRIBUTES)) {
            return [];
        }

        // Cloned per request: what a caller leaves on it dies with the request.
        if ($this->hasAnyAttribute($nativeReflection, self::SCOPING_ATTRIBUTES)) {
            return [];
        }

        $finder = new NodeFinder();
        $errors = [];

        foreach ($node->getOriginalNode()->getMethods() as $method) {
            if ($method->isStatic() || in_array($method->name->toLowerString(), self::INITIALISING_METHODS, true)) {
                continue;
            }

            $parameters = $this->parameterNames($method);
            if ($parameters === [] || $method->stmts === null) {
                continue;
            }

            /** @var list<Assign|AssignOp|AssignRef> $assignments */
            $assignments = $finder->find(
                $method->stmts,
                static fn (Node $n): bool => $n instanceof Assign || $n instanceof AssignOp || $n instanceof AssignRef,
            );

            foreach ($assignments as $assignment) {
                $property = $this->assignedProperty($assignment->var);
                // One report per property: the fix is made once, where it is declared.
                if ($property === null || isset($errors[$property])) {
                    continue;
                }

                $parameter = $this->parameterReadBy($assignment->expr, $parameters, $finder);
                if ($parameter === null || !$this->isCallerWritable($classReflection, $property)) {
                    continue;
                }

                $errors[$property] = $this->error(
                    $classReflection->getName(),
                    $property,
                    $parameter,
                    $method->name->toString(),
                    $assignment->getStartLine(),
                );
            }
        }

        return array_values($errors);
    }

    private function error(string $class, string $property, string $parameter, string $method, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            sprintf(
                'Property %s::$%s is assigned from parameter $%s of %s(), and %s is a worker-shared service '
                . '(one instance per worker): the value a caller passed would be shared by every request on the '
                . 'worker, and a concurrent request would read it. Do not keep it: read the '
                . 'context at call time (the coroutine-local tenant, auth or locale context, or the method\'s own '
                . 'arguments). If the value really is one per worker (a registry filled at boot, a store wired '
                . 'at worker start), mark the property #[WorkerState(\'why\')].',
                $class,
                $property,
                $parameter,
                $method,
                $class,
            ),
        )->tip(self::RATIONALE)->identifier('semitexa.workerServiceCallerState')->line($line)->build();
    }

    /** @return list<string> */
    private function parameterNames(ClassMethod $method): array
    {
        $names = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    /**
     * The property written by `$this->p = ...`, `$this->p[] = ...` or
     * `$this->p[$k][$j] = ...`; null for any other target.
     */
    private function assignedProperty(Expr $target): ?string
    {
        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }

        if (!$target instanceof PropertyFetch
            || !$target->var instanceof Variable
            || $target->var->name !== 'this'
            || !$target->name instanceof Node\Identifier
        ) {
            return null;
        }

        return $target->name->toString();
    }

    /**
     * The first parameter the assigned expression reads, or null.
     *
     * @param list<string> $parameters
     */
    private function parameterReadBy(Expr $expr, array $parameters, NodeFinder $finder): ?string
    {
        $variable = $finder->findFirst(
            [$expr],
            static fn (Node $n): bool => $n instanceof Variable
                && is_string($n->name)
                && in_array($n->name, $parameters, true),
        );

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : null;
    }

    /**
     * False when the container owns the property (#[InjectAs*], #[Config]),
     * when it is declared worker-wide (#[WorkerState]), or when it is static or
     * not a declared property at all.
     */
    private function isCallerWritable(ClassReflection $classReflection, string $property): bool
    {
        $nativeReflection = $classReflection->getNativeReflection();
        if (!$nativeReflection->hasProperty($property)) {
            return false;
        }

        $reflection = $nativeReflection->getProperty($property);
        if ($reflection->isStatic()) {
            return false;
        }

        // Only the real Semitexa attribute opts out; a foreign `WorkerState` does not.
        if ($reflection->getAttributes(WorkerState::class) !== []) {
            return false;
        }

        foreach (self::CONTAINER_OWNED_ATTRIBUTES as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass|\PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum $nativeReflection
     * @param list<string> $attributeNames
     */
    private function hasAnyAttribute(\ReflectionClass $nativeReflection, array $attributeNames): bool
    {
        foreach ($attributeNames as $attributeName) {
            // @phpstan-ignore argument.type (the bare short names are deliberate: they match an attribute written without its import)
            if ($nativeReflection->getAttributes($attributeName) !== []) {
                return true;
            }
        }

        return false;
    }
}
