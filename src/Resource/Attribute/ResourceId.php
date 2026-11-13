<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * The property that identifies a resource object.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceId
{
}
