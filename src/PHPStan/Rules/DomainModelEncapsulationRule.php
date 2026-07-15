<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionMethod;
use ReflectionProperty;

/**
 * semitexa.domainModelEncapsulation
 *
 * Enforces encapsulation on the domain (business) model a #[AsMapper] maps to:
 * every instance property must be `private`, exposed only through accessors.
 * The domain model is discovered from the mapper's `domainModel:` argument, so a
 * class cannot dodge the rule — persisting a business model requires a mapper
 * that names it. Resource models (public promoted props for ORM hydration) are
 * excluded: the no-op case (domainModel == resourceModel) is left to
 * {@see NoOpMapperRule}, and any class carrying #[FromTable] is skipped.
 *
 * Field contract (readonly-aware):
 *   - public / protected property              → violation (must be private)
 *   - private property without a getter         → violation
 *   - mutable (non-readonly) private property
 *     without a setter                          → violation (make it readonly or add one)
 *   - readonly private property with a getter   → OK (immutability allowed)
 *
 * @implements Rule<Class_>
 */
final class DomainModelEncapsulationRule implements Rule
{
    private const ORM_RESOURCE_ATTRIBUTE = 'Semitexa\\Orm\\Attribute\\FromTable';

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        [$resource, $domain] = $this->readMapper($node, $scope);
        if ($domain === null) {
            return [];
        }

        // The no-op form (domainModel == resourceModel) is NoOpMapperRule's job.
        if ($resource !== null && strcasecmp($resource, $domain) === 0) {
            return [];
        }

        if (!$this->reflectionProvider->hasClass($domain)) {
            return [];
        }

        $reflection = $this->reflectionProvider->getClass($domain)->getNativeReflection();

        // A class marked as an ORM resource model is not a domain model.
        foreach ($reflection->getAttributes() as $classAttribute) {
            if ($classAttribute->getName() === self::ORM_RESOURCE_ATTRIBUTE) {
                return [];
            }
        }

        $mapperName = $node->name instanceof Node\Identifier ? $node->name->name : 'anonymous';
        $errors     = [];

        // Lowercased public method names, as a lookup set for accessor detection.
        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[strtolower($method->getName())] = true;
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            // Only enforce the model's own fields, not those declared on a parent.
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $errors = array_merge(
                $errors,
                $this->checkProperty($property, $methods, $domain, $mapperName),
            );
        }

        return $errors;
    }

    /**
     * @param array<string, true> $methods lowercased public method names
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkProperty(
        ReflectionProperty $property,
        array $methods,
        string $domain,
        string $mapperName,
    ): array {
        $name  = $property->getName();
        $studly = ucfirst($name);

        // Visibility is the primary fix. Report it alone and defer the accessor
        // requirement to the next pass (once the field is private) to keep the
        // signal on a badly-encapsulated model from ballooning to two-per-field.
        if (!$property->isPrivate()) {
            $visibility = $property->isProtected() ? 'protected' : 'public';

            return [
                RuleErrorBuilder::message(sprintf(
                    'Domain model %s (mapped by %s) declares property $%s as %s; domain-model '
                    . 'fields must be private, exposed only through getters/setters.',
                    $domain,
                    $mapperName,
                    $name,
                    $visibility,
                ))->identifier('semitexa.domainModelEncapsulation')->build(),
            ];
        }

        $errors = [];

        $hasGetter = isset($methods['get' . strtolower($studly)])
            || isset($methods['is' . strtolower($studly)])
            || isset($methods['has' . strtolower($studly)]);

        if (!$hasGetter) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Domain model %s (mapped by %s) has no getter for property $%s; '
                . 'add get%s() (or is%s()/has%s()) — fields are reachable only via accessors.',
                $domain,
                $mapperName,
                $name,
                $studly,
                $studly,
                $studly,
            ))->identifier('semitexa.domainModelEncapsulation')->build();
        }

        if (!$property->isReadOnly()) {
            $hasSetter = isset($methods['set' . strtolower($studly)])
                || isset($methods['with' . strtolower($studly)]);

            if (!$hasSetter) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Domain model %s (mapped by %s) has mutable property $%s without a setter; '
                    . 'add set%s() (or with%s()), or make the property readonly if it is immutable.',
                    $domain,
                    $mapperName,
                    $name,
                    $studly,
                    $studly,
                ))->identifier('semitexa.domainModelEncapsulation')->build();
            }
        }

        return $errors;
    }

    /**
     * @return array{0: ?string, 1: ?string} [resourceModel FQCN, domainModel FQCN]
     */
    private function readMapper(Class_ $node, Scope $scope): array
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                // Resolve against the file scope so an aliased import
                // (`use ... AsMapper as Mapper;`) still matches.
                $name = ltrim($scope->resolveName($attr->name), '\\');
                if ($name !== 'Semitexa\\Orm\\Attribute\\AsMapper') {
                    continue;
                }

                $resource = null;
                $domain   = null;
                $positional = 0;

                foreach ($attr->args as $arg) {
                    if ($arg->name !== null) {
                        $param = $arg->name->name;
                    } else {
                        $param = match ($positional) {
                            0 => 'resourceModel',
                            1 => 'domainModel',
                            default => null,
                        };
                        $positional++;
                    }

                    if ($param === 'resourceModel') {
                        $resource = $this->resolveClassName($arg->value, $scope);
                    } elseif ($param === 'domainModel') {
                        $domain = $this->resolveClassName($arg->value, $scope);
                    }
                }

                return [$resource, $domain];
            }
        }

        return [null, null];
    }

    /**
     * Resolve an attribute argument to a real FQCN (for reflection). Handles both
     * `Foo::class` and string-literal forms, resolving `use`-aliases via scope.
     */
    private function resolveClassName(Node\Expr $value, Scope $scope): ?string
    {
        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name) {
            return ltrim($scope->resolveName($value->class), '\\');
        }

        if ($value instanceof Node\Scalar\String_) {
            return ltrim($value->value, '\\');
        }

        return null;
    }
}
