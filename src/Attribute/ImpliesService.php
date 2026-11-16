<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

/**
 * Implemented by an attribute class whose carriers are container services. A
 * class carrying such an attribute is registered and built like an
 * #[AsService], so it needs no #[AsService] beside it.
 *
 * A package declares what its own attributes mean to the container without
 * core naming the package, the reason DiscoveryContributor exists. The first
 * user is semitexa-scheduler: the worker builds a job by its class name, and a
 * job carried #[AsService] for nothing else.
 *
 * A carrier is what an #[AsService] is: one instance per worker, its
 * dependencies injected into #[InjectAs*] properties, built when the container
 * is. An attribute whose carriers must be built per execution is not covered.
 */
interface ImpliesService
{
}
