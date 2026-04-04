<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a class as a worker-scoped singleton service.
 * The container will auto-discover, build, and inject it as a readonly instance.
 *
 * Use for plain service classes that are not service contract implementations,
 * payload handlers, or pipeline listeners, but still need to be injectable
 * via #[InjectAsReadonly].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsService
{
}
