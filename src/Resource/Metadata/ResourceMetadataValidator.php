<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;
use Semitexa\Core\Resource\RelationResolverInterface;

#[AsService]
final class ResourceMetadataValidator
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    /** Bypass property injection for unit tests. */
    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $v = new self();
        $v->registry = $registry;
        return $v;
    }

    /** @return list<MalformedResourceObjectException> */
    public function validate(): array
    {
        $errors = [];
        $relationTargets = $this->collectRelationTargets();

        foreach ($this->registry->all() as $class => $metadata) {
            $this->validateOneCollecting($class, $metadata, $relationTargets, $errors);
        }

        return $errors;
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $relationTargets
     * @param list<MalformedResourceObjectException> $errors
     */
    private function validateOneCollecting(
        string $class,
        ResourceObjectMetadata $metadata,
        array $relationTargets,
        array &$errors,
    ): void {
        try {
            $this->validateOne($class, $metadata, $relationTargets);
        } catch (MalformedResourceObjectException $e) {
            $errors[] = $e;
        }

        // Phase 6b: resolver checks are independent of structural checks
        // and report per-field, so a single bad resolver does not mask the
        // remaining fields.
        foreach ($metadata->fields as $field) {
            if ($field->resolverClass === null) {
                continue;
            }
            try {
                $this->validateResolver($class, $field);
            } catch (MalformedResourceObjectException $e) {
                $errors[] = $e;
            }
        }
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $relationTargets
     */
    private function validateOne(string $class, ResourceObjectMetadata $metadata, array $relationTargets): void
    {
        // Rule 1: relation targets must declare #[ResourceId].
        if (isset($relationTargets[$class]) && $metadata->idField === null) {
            throw MalformedResourceObjectException::missingResourceId($class);
        }

        // Rule 2: every relation target must point at a known ResourceObject.
        foreach ($metadata->fields as $field) {
            if ($field->target !== null && !$this->registry->has($field->target)) {
                throw MalformedResourceObjectException::unknownTarget($class, $field->name, $field->target);
            }
            if ($field->unionTargets !== null) {
                foreach ($field->unionTargets as $target) {
                    if (!$this->registry->has($target)) {
                        throw MalformedResourceObjectException::unknownTarget($class, $field->name, $target);
                    }
                }
            }
        }

        // Rule 3: href templates only reference fields the parent has.
        foreach ($metadata->fields as $field) {
            if ($field->hrefTemplate === null) {
                continue;
            }
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $field->hrefTemplate, $matches);
            foreach ($matches[1] as $placeholder) {
                if (!$metadata->hasField($placeholder)) {
                    throw MalformedResourceObjectException::unknownHrefTemplateField(
                        $class,
                        $field->name,
                        $placeholder,
                    );
                }
            }
        }

        // Rule 4: no static `from*(DomainEntity ...)` factories on Resource DTOs.
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            if (!str_starts_with($method->getName(), 'from')) {
                continue;
            }
            $params = $method->getParameters();
            if ($params === []) {
                continue;
            }
            $type = $params[0]->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            // The factory takes an object that is NOT a Resource DTO -> looks like a domain leak.
            $arg = $type->getName();
            if (!$this->registry->has($arg) && $arg !== 'self' && $arg !== 'static') {
                throw MalformedResourceObjectException::staticDomainFactory($class, $method->getName());
            }
        }
    }

    /**
     * Phase 6b: validate a single `#[ResolveWith]` declaration.
     *
     * Rules:
     *   1. Resolver may only be declared on a relation field
     *      (RefOne / RefMany / Union).
     *   2. The resolver class must exist.
     *   3. The resolver class must implement `RelationResolverInterface`.
     *   4. The resolver class must be marked `#[AsService]` so the
     *      readonly container can resolve it per worker.
     *
     * @param class-string $class
     */
    private function validateResolver(string $class, ResourceFieldMetadata $field): void
    {
        if (!$field->isRelation()) {
            throw MalformedResourceObjectException::resolveWithOnNonRelation(
                $class,
                $field->name,
                $field->kind->value,
            );
        }

        $resolverClass = $field->resolverClass;
        if ($resolverClass === null) {
            return;
        }

        if (!class_exists($resolverClass)) {
            throw MalformedResourceObjectException::resolveWithMissingClass(
                $class,
                $field->name,
                $resolverClass,
            );
        }

        $resolverReflection = new ReflectionClass($resolverClass);

        if (!$resolverReflection->implementsInterface(RelationResolverInterface::class)) {
            throw MalformedResourceObjectException::resolveWithWrongInterface(
                $class,
                $field->name,
                $resolverClass,
            );
        }

        if ($resolverReflection->getAttributes(AsService::class) === []) {
            throw MalformedResourceObjectException::resolveWithMissingAsService(
                $class,
                $field->name,
                $resolverClass,
            );
        }
    }

    /** @return array<class-string, true> */
    private function collectRelationTargets(): array
    {
        $targets = [];
        foreach ($this->registry->all() as $metadata) {
            foreach ($metadata->fields as $field) {
                if ($field->target !== null) {
                    $targets[$field->target] = true;
                }
                if ($field->unionTargets !== null) {
                    foreach ($field->unionTargets as $unionTarget) {
                        $targets[$unionTarget] = true;
                    }
                }
            }
        }

        return $targets;
    }
}
