<?php

declare(strict_types=1);

namespace Semitexa\Core\Request\Attribute;

use Attribute;

/**
 * PathParam attribute
 * 
 * Marks a property as a path parameter extracted from the URL route pattern
 * 
 * Example:
 * ```php
 * #[AsRequest(path: '/api/users/{id}')]
 * class UserRequest implements PayloadInterface
 * {
 *     #[PathParam(name: 'id')]
 *     public int $id;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PathParam
{
    public function __construct(
        public string $name
    ) {
    }
}
