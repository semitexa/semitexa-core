<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Semitexa\Core\Http\Response\ResponseFormat;
use Attribute;

/**
 * Marks a class as a resource DTO (for template/JSON response).
 * All parameters are matched by name; order in source does not matter.
 * Single positional value (e.g. #[AsResource('about')]) sets handle.
 *
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsResource
{
    public readonly ?string $doc;

    public function __construct(
        public ?string $handle = null,
        ?string $doc = null,
        public ?string $base = null,
        /** @var array<string, mixed>|null $context */
        public ?array $context = null,
        public ?ResponseFormat $format = null,
        public ?string $renderer = null,
    ) {
        $this->doc = $doc;
    }
}
