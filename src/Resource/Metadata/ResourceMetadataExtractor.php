<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceListOf;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttribute;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttribute;
use Semitexa\Core\Resource\Attribute\ResourceUnion;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;
use Semitexa\Core\Resource\ResourceRef as ResourceRefRuntime;
use Semitexa\Core\Resource\ResourceRefList as ResourceRefListRuntime;

#[AsService]
final class ResourceMetadataExtractor
{
    /**
     * @param class-string $class
     */
    public function extract(string $class): ResourceObjectMetadata
    {
        $reflection = new ReflectionClass($class);

        $objectAttrs = $reflection->getAttributes(ResourceObject::class);
        if ($objectAttrs === []) {
            throw MalformedResourceObjectException::missingResourceObjectAttribute($class);
        }
        /** @var ResourceObject $object */
        $object = $objectAttrs[0]->newInstance();

        if ($object->type === '') {
            throw MalformedResourceObjectException::emptyType($class);
        }

        if (!$reflection->isFinal() || !$reflection->isReadOnly()) {
            throw MalformedResourceObjectException::notFinalReadonly($class);
        }

        $idField = null;
        $fields  = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $type         = $property->getType();
            $typeInfo     = $this->describeType($type);

            $hasResourceId = $property->getAttributes(ResourceId::class) !== [];
            if ($hasResourceId) {
                if ($idField !== null) {
                    throw MalformedResourceObjectException::multipleResourceIds($class);
                }
                if ($typeInfo['name'] !== 'string') {
                    throw MalformedResourceObjectException::nonStringResourceId(
                        $class,
                        $propertyName,
                        $typeInfo['name'],
                    );
                }
                $idField = $propertyName;
            }

            $resourceAttributes = $this->collectResourceAttributes($property);
            $this->assertSingleResourceAttribute($class, $propertyName, $resourceAttributes);

            $relationAttribute = $resourceAttributes['relation'] ?? null;
            $listOfAttribute   = $resourceAttributes['listOf'] ?? null;
            $fieldAttribute    = $resourceAttributes['field'] ?? null;

            $resolveWithAttrs = $property->getAttributes(ResolveWith::class);
            $resolverClass    = null;
            if ($resolveWithAttrs !== []) {
                /** @var ResolveWith $resolveWith */
                $resolveWith   = $resolveWithAttrs[0]->newInstance();
                $resolverClass = $resolveWith->resolverClass;
            }

            $fieldMetadata = $this->buildFieldMetadata(
                class: $class,
                property: $propertyName,
                typeInfo: $typeInfo,
                relationAttribute: $relationAttribute,
                listOfAttribute: $listOfAttribute,
                fieldAttribute: $fieldAttribute,
                resolverClass: $resolverClass,
            );

            $fields[$propertyName] = $fieldMetadata;
        }

        return new ResourceObjectMetadata(
            class: $class,
            type: $object->type,
            idField: $idField,
            fields: $fields,
            description: $object->description,
            deprecated: $object->deprecated,
        );
    }

    /**
     * @return array{
     *     relation?: ResourceRefAttribute|ResourceRefListAttribute|ResourceUnion,
     *     listOf?: ResourceListOf,
     *     field?: ResourceField
     * }
     */
    private function collectResourceAttributes(ReflectionProperty $property): array
    {
        $found = [];

        $refAttr = $property->getAttributes(ResourceRefAttribute::class);
        if ($refAttr !== []) {
            $found['relation'] = $refAttr[0]->newInstance();
        }

        $refListAttr = $property->getAttributes(ResourceRefListAttribute::class);
        if ($refListAttr !== []) {
            $found['relation'] = $refListAttr[0]->newInstance();
        }

        $unionAttr = $property->getAttributes(ResourceUnion::class);
        if ($unionAttr !== []) {
            $found['relation'] = $unionAttr[0]->newInstance();
        }

        $listOf = $property->getAttributes(ResourceListOf::class);
        if ($listOf !== []) {
            $found['listOf'] = $listOf[0]->newInstance();
        }

        $field = $property->getAttributes(ResourceField::class);
        if ($field !== []) {
            $found['field'] = $field[0]->newInstance();
        }

        return $found;
    }

    /** @param array<string, object> $attributes */
    private function assertSingleResourceAttribute(string $class, string $property, array $attributes): void
    {
        $structural = [];
        if (isset($attributes['relation'])) {
            $structural[] = (new \ReflectionClass($attributes['relation']))->getShortName();
        }
        if (isset($attributes['listOf'])) {
            $structural[] = 'ResourceListOf';
        }

        if (count($structural) > 1) {
            throw MalformedResourceObjectException::conflictingAttributes(
                $class,
                $property,
                $structural[0],
                $structural[1],
            );
        }
    }

    /**
     * @param array{name: string, nullable: bool, builtin: bool} $typeInfo
     */
    private function buildFieldMetadata(
        string $class,
        string $property,
        array $typeInfo,
        ?object $relationAttribute,
        ?ResourceListOf $listOfAttribute,
        ?ResourceField $fieldAttribute,
        ?string $resolverClass,
    ): ResourceFieldMetadata {
        $description = $fieldAttribute->description ?? '';
        $deprecated  = $fieldAttribute->deprecated ?? false;

        if ($relationAttribute instanceof ResourceRefAttribute) {
            $this->assertType($class, $property, $typeInfo, ResourceRefRuntime::class, 'ResourceRef');
            $defaultInclude = $relationAttribute->include ?? $this->defaultInclude($property);

            return new ResourceFieldMetadata(
                name: $property,
                kind: ResourceFieldKind::RefOne,
                nullable: $typeInfo['nullable'],
                target: $relationAttribute->target,
                include: $defaultInclude,
                hrefTemplate: $relationAttribute->href,
                expandable: $relationAttribute->expandable,
                list: false,
                description: $relationAttribute->description !== '' ? $relationAttribute->description : $description,
                deprecated: $deprecated,
                resolverClass: $resolverClass,
            );
        }

        if ($relationAttribute instanceof ResourceRefListAttribute) {
            $this->assertType($class, $property, $typeInfo, ResourceRefListRuntime::class, 'ResourceRefList');
            $defaultInclude = $relationAttribute->include ?? $this->defaultInclude($property);

            return new ResourceFieldMetadata(
                name: $property,
                kind: ResourceFieldKind::RefMany,
                nullable: $typeInfo['nullable'],
                target: $relationAttribute->target,
                include: $defaultInclude,
                hrefTemplate: $relationAttribute->href,
                expandable: $relationAttribute->expandable,
                paginated: $relationAttribute->paginated,
                list: true,
                description: $relationAttribute->description !== '' ? $relationAttribute->description : $description,
                deprecated: $deprecated,
                resolverClass: $resolverClass,
            );
        }

        if ($relationAttribute instanceof ResourceUnion) {
            if ($relationAttribute->targets === []) {
                throw MalformedResourceObjectException::unionMissingTargets($class, $property);
            }

            $expectedRuntime  = $relationAttribute->list ? ResourceRefListRuntime::class : ResourceRefRuntime::class;
            $expectedShortName = $relationAttribute->list ? 'ResourceRefList' : 'ResourceRef';
            $this->assertType($class, $property, $typeInfo, $expectedRuntime, "ResourceUnion(list: " . ($relationAttribute->list ? 'true' : 'false') . ") => $expectedShortName");

            $defaultInclude = $relationAttribute->include ?? $this->defaultInclude($property);

            return new ResourceFieldMetadata(
                name: $property,
                kind: ResourceFieldKind::Union,
                nullable: $typeInfo['nullable'],
                target: null,
                include: $defaultInclude,
                hrefTemplate: null,
                expandable: $relationAttribute->expandable,
                list: $relationAttribute->list,
                description: $relationAttribute->description !== '' ? $relationAttribute->description : $description,
                deprecated: $deprecated,
                unionTargets: $relationAttribute->targets,
                discriminator: $relationAttribute->discriminator,
                resolverClass: $resolverClass,
            );
        }

        if ($listOfAttribute !== null) {
            if ($typeInfo['name'] !== 'array') {
                throw MalformedResourceObjectException::attributeTypeMismatch(
                    $class,
                    $property,
                    'ResourceListOf',
                    'array',
                    $typeInfo['name'],
                );
            }

            return new ResourceFieldMetadata(
                name: $property,
                kind: ResourceFieldKind::EmbeddedMany,
                nullable: $typeInfo['nullable'],
                target: $listOfAttribute->target,
                list: true,
                description: $listOfAttribute->description !== '' ? $listOfAttribute->description : $description,
                deprecated: $deprecated,
                resolverClass: $resolverClass,
            );
        }

        // No relation/list-of attribute: scalar or embedded-one.
        if ($typeInfo['builtin']) {
            return new ResourceFieldMetadata(
                name: $property,
                kind: ResourceFieldKind::Scalar,
                nullable: $typeInfo['nullable'],
                list: $typeInfo['name'] === 'array',
                description: $description,
                deprecated: $deprecated,
                resolverClass: $resolverClass,
            );
        }

        return new ResourceFieldMetadata(
            name: $property,
            kind: ResourceFieldKind::EmbeddedOne,
            nullable: $typeInfo['nullable'],
            target: $typeInfo['name'],
            list: false,
            description: $description,
            deprecated: $deprecated,
            resolverClass: $resolverClass,
        );
    }

    /**
     * @param array{name: string, nullable: bool, builtin: bool} $typeInfo
     */
    private function assertType(
        string $class,
        string $property,
        array $typeInfo,
        string $expectedFqcn,
        string $attributeShortName,
    ): void {
        if ($typeInfo['name'] !== $expectedFqcn) {
            throw MalformedResourceObjectException::attributeTypeMismatch(
                $class,
                $property,
                $attributeShortName,
                $expectedFqcn,
                $typeInfo['name'],
            );
        }
    }

    /**
     * @return array{name: string, nullable: bool, builtin: bool}
     */
    private function describeType(?\ReflectionType $type): array
    {
        if ($type === null) {
            return ['name' => 'mixed', 'nullable' => true, 'builtin' => true];
        }

        if ($type instanceof ReflectionNamedType) {
            return [
                'name'     => $type->getName(),
                'nullable' => $type->allowsNull(),
                'builtin'  => $type->isBuiltin(),
            ];
        }

        // Union/intersection types are not supported on Resource fields in v1.
        return [
            'name'     => $type instanceof ReflectionUnionType ? 'union' : 'intersection',
            'nullable' => $type->allowsNull(),
            'builtin'  => false,
        ];
    }

    private function defaultInclude(string $property): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $property) ?? $property);
    }
}
