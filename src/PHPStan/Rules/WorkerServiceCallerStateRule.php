<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\ImpliesService;
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
 *   - of a container-managed class that is not cloned per request: the notion
 *     of {@see WorkerServiceConnectionHandleRule}, plus a class carrying an
 *     attribute that implements {@see ImpliesService}, which the container
 *     registers and shares exactly like an #[AsService];
 *   - carrying no #[InjectAs*] and no #[Config] (the container owns those);
 *   - assigned through `$this`, directly, as an array element or as one target
 *     of a destructuring, an expression that READS a parameter of the enclosing
 *     method (a parameter that is only assigned inside the expression, as in
 *     `$this->p = ($param = 'x')`, is not read);
 *   - in any method but `__construct()`.
 *
 * WHY THE CONSTRUCTOR, AND ONLY IT. The container refuses a container-managed
 * class whose constructor takes parameters, and the one kind it does build
 * through a constructor, a generated Factory* class, is built once, when the
 * container is. So no request reaches a constructor argument. No other method
 * is exempt by its name: the real hook,
 * InitializesAfterInjectionInterface::initialize(), takes no parameter and so
 * has nothing to report, while an `initialize(string $tenant)` on a service is
 * an ordinary setter any handler may call.
 *
 * WHAT IS READ AS THE SERVICE'S OWN CODE. A closure or an arrow function that is
 * not `static` runs with the service as `$this`, so a write inside it counts.
 * It sees a parameter of the method only as PHP lets it: a closure through
 * `use`, an arrow function unless its own parameter has the same name. The body
 * of a class declared inside the method (`new class { ... }`) is another
 * object's code and is not inspected; the arguments of that `new` are.
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
 *   - values are not followed: a parameter copied to a local first
 *     (`$v = $value; $this->p = $v;`) is missed, and one overwritten before the
 *     write (`$value = 'x'; $this->p = $value;`) is still reported;
 *   - a parameter used only as the array key (`$this->seen[$tenant] = true`) is
 *     not reported;
 *   - a parameter of a closure or an arrow function, as opposed to one of the
 *     method, is not a parameter to this rule: `function ($value) {
 *     $this->p = $value; }` is not reported;
 *   - a write that is not an assignment is not seen: `array_push($this->p, $x)`,
 *     a `foreach (... as $this->p)`, a reference taken earlier
 *     (`$ref = &$this->p; $ref = $x;`);
 *   - a method the service INHERITS is not inspected, whether it comes from a
 *     trait or from a base class that is not itself container-managed: its body
 *     is analysed in the scope of the trait or the base, where nothing says
 *     which services will carry it (reaching it needs PHPStan collectors);
 *   - a destructuring assignment is read as a whole: when its right side reads
 *     a parameter, every `$this` target in it is reported, also one that
 *     receives a constant (`[$this->a, $this->b] = ['x', $param]`); assigning
 *     the two separately is the workaround;
 *   - a capture that no code can read is not reported: an arrow function whose
 *     only use of the name is a nested arrow function's own parameter still
 *     captures the method's parameter, but only reflection could reach it;
 *   - writes through another variable than `$this` and to static properties
 *     ({@see StaticStateLifetimeRule} owns those) are not inspected.
 *
 * @implements Rule<InClassNode>
 */
final class WorkerServiceCallerStateRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: an #[AsService] is one instance per worker, so a property a method fills from its parameter holds one caller\'s value for every request and coroutine that follows. Learned 2026-11-22: a service keeping `$tenant` between two calls gave 168 to 184 of 400 concurrent answers the other tenant\'s name, and neither lint:di nor PHPStan reported it.';

    /**
     * The bare short names stay here on purpose: they match an attribute written
     * without its import, and matching more only inspects more.
     */
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

    /**
     * Mirrors the execution-scoped set of
     * {@see \Semitexa\Core\Container\InjectionAnalyzer::collectExecutionScopedClasses()}:
     * a class the container clones per request is one this rule leaves alone.
     * The auth and slot handler attributes are named as strings because core
     * does not depend on the packages that own them.
     *
     * Resolved names only. A bare `ExecutionScoped` here would be the unsafe
     * direction: it would exempt a class carrying some other `#[\ExecutionScoped]`
     * that the container does not know and therefore still shares.
     */
    private const SCOPING_ATTRIBUTES = [
        ExecutionScoped::class,
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        'Semitexa\\Auth\\Attribute\\AsAuthHandler',
        'Semitexa\\Ssr\\Attribute\\AsSlotHandler',
    ];

    /** The container fills these; what they hold is its business, not a caller's. */
    private const CONTAINER_OWNED_ATTRIBUTES = [
        InjectAsReadonly::class,
        InjectAsMutable::class,
        InjectAsFactory::class,
        Config::class,
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        if ($classReflection->isAnonymous() || !$this->isWorkerShared($classReflection)) {
            return [];
        }

        $errors = [];

        foreach ($node->getOriginalNode()->getMethods() as $method) {
            if ($method->isStatic() || $method->name->toLowerString() === '__construct') {
                continue;
            }

            $parameters = $this->parameterNames($method->params);
            if ($parameters === [] || $method->stmts === null) {
                continue;
            }

            foreach ($this->assignmentsIn($method->stmts, $parameters) as [$assignment, $visible]) {
                $parameter = null;

                foreach ($this->assignedProperties($assignment->var) as [$property, $line]) {
                    // One report per property: the fix is made once, where it is declared.
                    if (isset($errors[$property]) || !$this->isCallerWritable($classReflection, $property)) {
                        continue;
                    }

                    $parameter ??= $this->parameterRead($assignment->expr, $visible);
                    if ($parameter === null) {
                        break;
                    }

                    $errors[$property] = $this->error(
                        $classReflection->getName(),
                        $property,
                        $parameter,
                        $method->name->toString(),
                        $line,
                    );
                }
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

    /**
     * Container-managed and not cloned per request: what the container builds
     * once per worker and hands to every request.
     */
    private function isWorkerShared(ClassReflection $classReflection): bool
    {
        $nativeReflection = $classReflection->getNativeReflection();

        if (!$this->hasAnyAttribute($nativeReflection, self::CONTAINER_MANAGED_ATTRIBUTES)
            && !$this->carriesAnImpliedServiceAttribute($classReflection)
        ) {
            return false;
        }

        // Cloned per request: what a caller leaves on it dies with the request.
        return !$this->hasAnyAttribute($nativeReflection, self::SCOPING_ATTRIBUTES);
    }

    /**
     * True when one of the class's attributes implements ImpliesService:
     * ServiceRegistrationPhase registers its carriers beside the #[AsService]
     * classes, and nothing but an explicit scoping attribute scopes them.
     */
    private function carriesAnImpliedServiceAttribute(ClassReflection $classReflection): bool
    {
        foreach ($classReflection->getNativeReflection()->getAttributes() as $attribute) {
            $name = $attribute->getName();
            // An attribute whose class cannot be found says nothing to the container either.
            if ($this->reflectionProvider->hasClass($name)
                && $this->reflectionProvider->getClass($name)->implementsInterface(ImpliesService::class)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node\Param> $params
     * @return list<string>
     */
    private function parameterNames(array $params): array
    {
        $names = [];
        foreach ($params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    /**
     * Every assignment that runs as the service's own code, in source order,
     * each with the method parameters visible where it stands.
     *
     * @param array<mixed>|Node $nodes
     * @param list<string> $visible
     * @return list<array{Assign|AssignOp|AssignRef, list<string>}>
     */
    private function assignmentsIn(array|Node $nodes, array $visible): array
    {
        if (is_array($nodes)) {
            $found = [];
            foreach ($nodes as $node) {
                if ($node instanceof Node || is_array($node)) {
                    $found = [...$found, ...$this->assignmentsIn($node, $visible)];
                }
            }

            return $found;
        }

        // A static closure has no $this: nothing in it can write to the service.
        if (($nodes instanceof Closure || $nodes instanceof ArrowFunction) && $nodes->static) {
            return [];
        }

        $visible = $this->visibleInside($nodes, $visible);
        if ($visible === null) {
            return [];
        }

        $found = [];
        if ($nodes instanceof Assign || $nodes instanceof AssignOp || $nodes instanceof AssignRef) {
            $found[] = [$nodes, $visible];
        }

        foreach ($nodes->getSubNodeNames() as $name) {
            $child = $nodes->{$name};
            if ($child instanceof Node || is_array($child)) {
                $found = [...$found, ...$this->assignmentsIn($child, $visible)];
            }
        }

        return $found;
    }

    /**
     * The method parameters still visible inside the node, or null when the
     * node is not the method's code at all: the body of a class or a function
     * declared inside it has its own `$this` and its own variables.
     *
     * @param list<string> $visible
     * @return list<string>|null
     */
    private function visibleInside(Node $node, array $visible): ?array
    {
        if ($node instanceof ClassLike || $node instanceof Function_) {
            return null;
        }

        if ($node instanceof Closure) {
            // A closure sees an outer variable only through `use`.
            $used = [];
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $used[] = $use->var->name;
                }
            }

            return array_values(array_diff(array_intersect($visible, $used), $this->parameterNames($node->params)));
        }

        if ($node instanceof ArrowFunction) {
            // An arrow function sees them all, unless its own parameter takes the name.
            return array_values(array_diff($visible, $this->parameterNames($node->params)));
        }

        return $visible;
    }

    /**
     * The properties of `$this` an assignment target writes, each with its
     * line: `$this->p`, `$this->p[] `, `$this->p[$k][$j]`, and every such
     * target of a destructuring (`[$this->a, $this->b] = ...`).
     *
     * @return list<array{string, int}>
     */
    private function assignedProperties(Expr $target): array
    {
        if ($target instanceof Array_ || $target instanceof List_) {
            $properties = [];
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $properties = [...$properties, ...$this->assignedProperties($item->value)];
                }
            }

            return $properties;
        }

        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }

        if (!$target instanceof PropertyFetch
            || !$target->var instanceof Variable
            || $target->var->name !== 'this'
        ) {
            return [];
        }

        // `$this->{'p'}` is `$this->p`; a name computed at run time stays unknown.
        $name = match (true) {
            $target->name instanceof Node\Identifier => $target->name->toString(),
            $target->name instanceof Node\Scalar\String_ => $target->name->value,
            default => null,
        };

        return $name === null ? [] : [[$name, $target->getStartLine()]];
    }

    /**
     * The first visible parameter the expression READS, or null. The target of
     * a plain or by-reference assignment inside it is written, not read; the
     * target of a compound one (`.=`, `+=`) is both.
     *
     * @param array<mixed>|Node $nodes
     * @param list<string> $visible
     */
    private function parameterRead(array|Node $nodes, array $visible): ?string
    {
        if ($visible === []) {
            return null;
        }

        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if ($node instanceof Node || is_array($node)) {
                    $read = $this->parameterRead($node, $visible);
                    if ($read !== null) {
                        return $read;
                    }
                }
            }

            return null;
        }

        if ($nodes instanceof Variable && is_string($nodes->name)) {
            return in_array($nodes->name, $visible, true) ? $nodes->name : null;
        }

        if ($nodes instanceof Assign || $nodes instanceof AssignRef) {
            return $this->parameterReadByTarget($nodes->var, $visible) ?? $this->parameterRead($nodes->expr, $visible);
        }

        $visible = $this->visibleInside($nodes, $visible);
        if ($visible === null) {
            return null;
        }

        foreach ($nodes->getSubNodeNames() as $name) {
            $child = $nodes->{$name};
            if ($child instanceof Node || is_array($child)) {
                $read = $this->parameterRead($child, $visible);
                if ($read !== null) {
                    return $read;
                }
            }
        }

        return null;
    }

    /**
     * What an assignment target reads while being written: nothing for a plain
     * variable, the keys for a destructuring or an array element.
     *
     * @param list<string> $visible
     */
    private function parameterReadByTarget(Expr $target, array $visible): ?string
    {
        if ($target instanceof Variable && is_string($target->name)) {
            return null;
        }

        if ($target instanceof Array_ || $target instanceof List_) {
            foreach ($target->items as $item) {
                if ($item === null) {
                    continue;
                }
                $read = ($item->key !== null ? $this->parameterRead($item->key, $visible) : null)
                    ?? $this->parameterReadByTarget($item->value, $visible);
                if ($read !== null) {
                    return $read;
                }
            }

            return null;
        }

        if ($target instanceof ArrayDimFetch) {
            return $this->parameterReadByTarget($target->var, $visible)
                ?? ($target->dim !== null ? $this->parameterRead($target->dim, $visible) : null);
        }

        return $this->parameterRead($target, $visible);
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
