<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Semitexa\Core\Http\Response\ResponseFormat;
use Attribute;

/**
 * Marks a class as a response DTO.
 * All parameters are matched by name; order in source does not matter.
 * Single positional value (e.g. #[AsResponse('about')]) sets handle.
 *
 * @see DocumentedAttributeInterface
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsResponse implements DocumentedAttributeInterface
{
    use DocumentedAttributeTrait;

    public readonly ?string $doc;

    public function __construct(
        public ?string $handle = null,
        ?string $doc = null,
        public ?string $base = null,
        public ?array $context = null,
        public ?ResponseFormat $format = null,
        public ?string $renderer = null,
    ) {
        $this->doc = $doc;
    }

    public function getDocPath(): string
    {
        return $this->doc ?? 'packages/semitexa/core/docs/attributes/AsResponse.md';
    }
}


