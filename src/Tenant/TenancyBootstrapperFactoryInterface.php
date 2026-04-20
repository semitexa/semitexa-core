<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Psr\Container\ContainerInterface;

/**
 * Factory seam for {@see TenancyBootstrapperInterface}. Contributed by the
 * tenancy integration (see semitexa-tenancy) so Core's
 * {@see \Semitexa\Core\Lifecycle\LifecycleComponentRegistry} can construct the
 * bootstrapper without importing any concrete tenancy class.
 */
interface TenancyBootstrapperFactoryInterface
{
    public function create(ContainerInterface $container): TenancyBootstrapperInterface;
}
