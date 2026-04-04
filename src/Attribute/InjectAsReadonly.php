<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Inject a shared instance from the readonly graph (one per worker, same for all requests).
 * Only allowed on protected properties. The type to inject is the property's type hint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsReadonly
{
}
