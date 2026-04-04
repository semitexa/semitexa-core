<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Inject a fresh clone of the mutable prototype per get(); RequestContext is injected after clone.
 * Only allowed on protected properties. The type to inject is the property's type hint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsMutable
{
}
