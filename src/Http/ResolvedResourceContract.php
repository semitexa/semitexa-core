<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * One Way Pattern: the `output` block of the route contract
 * document — {@see ResourceObjectMetadata} serialized for the wire.
 *
 * Pure serialization of what {@see \Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor}
 * already extracted from the `Resource*` attributes; no re-reflection. Per
 * field the served keys are exactly the design's binding set:
 * name / kind / nullable / list (always) + target / href / description
 * (when declared). `target` is served as the target resource's registry
 * TYPE handle when the registry knows the class (the wire-stable identifier
 * OpenAPI also keys on), degrading to the class basename otherwise — FQCNs
 * are never served.
 */
final readonly class ResolvedResourceContract
{
    /** @param list<array<string, mixed>> $fields */
    public function __construct(
        public string $type,
        public ?string $idField,
        public array $fields,
    ) {
    }

    public static function fromMetadata(
        ResourceObjectMetadata $metadata,
        ?ResourceMetadataRegistry $registry = null,
    ): self {
        $fields = [];
        foreach ($metadata->fields as $field) {
            $entry = [
                'name'     => $field->name,
                'kind'     => $field->kind->value,
                'nullable' => $field->nullable,
                'list'     => $field->list,
            ];
            if ($field->target !== null) {
                $entry['target'] = self::wireTarget($field->target, $registry);
            }
            if ($field->hrefTemplate !== null) {
                $entry['href'] = $field->hrefTemplate;
            }
            if ($field->description !== '') {
                $entry['description'] = $field->description;
            }
            $fields[] = $entry;
        }

        return new self($metadata->type, $metadata->idField, $fields);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'idField' => $this->idField,
            'fields'  => $this->fields,
        ];
    }

    private static function wireTarget(string $target, ?ResourceMetadataRegistry $registry): string
    {
        if ($registry !== null && class_exists($target)) {
            $meta = $registry->get($target);
            if ($meta !== null) {
                return $meta->type;
            }
        }

        $pos = strrpos($target, '\\');

        return $pos === false ? $target : substr($target, $pos + 1);
    }
}
