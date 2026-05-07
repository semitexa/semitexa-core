<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

final class MalformedResourceObjectException extends RuntimeException
{
    /** @param class-string $resourceClass */
    public static function missingResourceObjectAttribute(string $resourceClass): self
    {
        return new self(sprintf(
            'Class %s is registered as a Resource but does not declare #[ResourceObject].',
            $resourceClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function notFinalReadonly(string $resourceClass): self
    {
        return new self(sprintf(
            'Resource class %s must be declared `final readonly`.',
            $resourceClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function missingResourceId(string $resourceClass): self
    {
        return new self(sprintf(
            'Resource class %s is used as a relation target but has no #[ResourceId] property.',
            $resourceClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function multipleResourceIds(string $resourceClass): self
    {
        return new self(sprintf(
            'Resource class %s declares more than one #[ResourceId] property; exactly one is required.',
            $resourceClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function nonStringResourceId(string $resourceClass, string $property, string $type): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResourceId] but its type is %s; must be `string`.',
            $resourceClass,
            $property,
            $type,
        ));
    }

    /** @param class-string $resourceClass */
    public static function attributeTypeMismatch(
        string $resourceClass,
        string $property,
        string $attributeShortName,
        string $expectedType,
        string $actualType,
    ): self {
        return new self(sprintf(
            'Resource class %s::$%s declares #[%s] but its PHP type is %s; expected %s.',
            $resourceClass,
            $property,
            $attributeShortName,
            $actualType,
            $expectedType,
        ));
    }

    /** @param class-string $resourceClass */
    public static function conflictingAttributes(string $resourceClass, string $property, string $first, string $second): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares both #[%s] and #[%s]; only one resource attribute is allowed per property.',
            $resourceClass,
            $property,
            $first,
            $second,
        ));
    }

    /** @param class-string $resourceClass */
    public static function unknownTarget(string $resourceClass, string $property, string $target): self
    {
        return new self(sprintf(
            'Resource class %s::$%s targets %s, which is not a registered Resource (#[ResourceObject] missing).',
            $resourceClass,
            $property,
            $target,
        ));
    }

    /** @param class-string $resourceClass */
    public static function unknownHrefTemplateField(string $resourceClass, string $property, string $missingField): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares an href template referencing `{%s}`, but that property does not exist on the parent.',
            $resourceClass,
            $property,
            $missingField,
        ));
    }

    /** @param class-string $resourceClass */
    public static function staticDomainFactory(string $resourceClass, string $method): self
    {
        return new self(sprintf(
            'Resource class %s must not declare static factory %s() that takes a domain entity. Use a Projector instead.',
            $resourceClass,
            $method,
        ));
    }

    public static function duplicateType(string $type, string $firstClass, string $secondClass): self
    {
        return new self(sprintf(
            'Resource type %s is declared twice: %s and %s. Each public type name must be unique.',
            $type,
            $firstClass,
            $secondClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function unionMissingTargets(string $resourceClass, string $property): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResourceUnion] without targets.',
            $resourceClass,
            $property,
        ));
    }

    /** @param class-string $resourceClass */
    public static function emptyType(string $resourceClass): self
    {
        return new self(sprintf(
            'Resource class %s declares #[ResourceObject] with an empty type. The type name must be a non-empty string.',
            $resourceClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function resolveWithOnNonRelation(string $resourceClass, string $property, string $kind): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResolveWith] but the field is %s, not a relation. '
                . '#[ResolveWith] is only allowed on #[ResourceRef], #[ResourceRefList], or #[ResourceUnion] properties.',
            $resourceClass,
            $property,
            $kind,
        ));
    }

    /** @param class-string $resourceClass */
    public static function resolveWithMissingClass(string $resourceClass, string $property, string $resolverClass): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResolveWith(%s::class)] but the resolver class does not exist.',
            $resourceClass,
            $property,
            $resolverClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function resolveWithWrongInterface(string $resourceClass, string $property, string $resolverClass): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResolveWith(%s::class)] but %s does not implement '
                . 'Semitexa\\Core\\Resource\\RelationResolverInterface.',
            $resourceClass,
            $property,
            $resolverClass,
            $resolverClass,
        ));
    }

    /** @param class-string $resourceClass */
    public static function resolveWithMissingAsService(string $resourceClass, string $property, string $resolverClass): self
    {
        return new self(sprintf(
            'Resource class %s::$%s declares #[ResolveWith(%s::class)] but %s is not marked '
                . '#[Semitexa\\Core\\Attribute\\AsService]. Resolvers must be container-managed services.',
            $resourceClass,
            $property,
            $resolverClass,
            $resolverClass,
        ));
    }
}
