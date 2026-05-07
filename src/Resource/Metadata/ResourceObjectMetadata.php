<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

final readonly class ResourceObjectMetadata
{
    /**
     * @param class-string $class
     * @param array<string, ResourceFieldMetadata> $fields
     */
    public function __construct(
        public string $class,
        public string $type,
        public ?string $idField,
        public array $fields,
        public string $description = '',
        public bool $deprecated = false,
    ) {
    }

    public function getField(string $name): ?ResourceFieldMetadata
    {
        return $this->fields[$name] ?? null;
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** @return list<ResourceFieldMetadata> */
    public function relationFields(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            if ($field->isRelation()) {
                $result[] = $field;
            }
        }

        return $result;
    }
}
