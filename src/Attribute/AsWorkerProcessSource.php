<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a class that names the long-running processes its package needs, read
 * by `worker:processes` and run by the scaffold's worker container. The class
 * must implement WorkerProcessSourceInterface and be a service (#[AsService]):
 * it is built through the container, so it can read the package's config.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsWorkerProcessSource
{
}
