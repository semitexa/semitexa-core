<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.noOpMapper
 *
 * Flags a #[AsMapper] whose `resourceModel` and `domainModel` resolve to the
 * SAME class. A mapper exists to translate between a persistence-facing
 * resource model and a domain (business) model; pointing both arguments at one
 * class collapses that boundary into a no-op `clone`, letting the DB layer leak
 * straight into the domain. Reusing the resource model as the domain model is
 * the lazy shortcut this rule refuses to let through.
 *
 * @implements Rule<Class_>
 */
final class NoOpMapperRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
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
                        // Constructor order: resourceModel (0), domainModel (1).
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

                if ($resource === null || $domain === null || $resource !== $domain) {
                    continue;
                }

                $className = $node->name instanceof Node\Identifier ? $node->name->name : 'anonymous';

                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            '#[AsMapper] on %s declares the same class for resourceModel and '
                            . 'domainModel (%s), which makes the mapper a no-op that clones the '
                            . 'resource model straight back. A mapper must translate between a '
                            . 'persistence resource model and a distinct domain model — reusing '
                            . 'the resource model as the domain model collapses that boundary and '
                            . 'leaks the DB layer into the domain. Introduce a separate domain '
                            . 'model and map its fields in toDomain() / toSourceModel().',
                            $className,
                            $domain,
                        )
                    )->identifier('semitexa.noOpMapper')->build(),
                ];
            }
        }

        return [];
    }

    /**
     * Resolve an attribute argument to a normalised class name for comparison.
     * Handles both authoring forms — `Foo::class` (ClassConstFetch) and a string
     * literal FQCN — and resolves `use`-aliased names against the current scope.
     */
    private function resolveClassName(Node\Expr $value, Scope $scope): ?string
    {
        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name) {
            return $this->normalise($scope->resolveName($value->class));
        }

        if ($value instanceof Node\Scalar\String_) {
            return $this->normalise($value->value);
        }

        return null;
    }

    private function normalise(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }
}
