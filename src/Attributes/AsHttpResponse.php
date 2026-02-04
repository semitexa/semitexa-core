<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Semitexa\Core\Http\Response\ResponseFormat;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsResponse
{
    public function __construct(
        public string $handle,
        public array $context = [],
        public ?ResponseFormat $format = null,
        public ?string $renderer = null
    ) {}
}


