<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;

/**
 * Produces AuthBootstrapperInterface instances for Core's lifecycle.
 *
 * This seam exists so Core does not import the concrete bootstrapper class.
 * semitexa-auth registers an implementation via
 * #[SatisfiesServiceContract(of: AuthBootstrapperFactoryInterface::class)];
 * when that package is not installed Core proceeds without auth integration.
 *
 * The factory receives the framework containers at call time so the concrete
 * bootstrapper can wire request-scoped handler resolution without Core having
 * to know about the handler shape.
 */
interface AuthBootstrapperFactoryInterface
{
    public function create(
        ContainerInterface $container,
        RequestScopedContainer $requestScopedContainer,
    ): AuthBootstrapperInterface;
}
