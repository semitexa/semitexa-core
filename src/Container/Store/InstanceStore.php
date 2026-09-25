<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Store;

use Semitexa\Core\Container\ContractFactory;

/**
 * Stores all container-managed object instances: readonly (worker-scoped),
 * execution-scoped prototypes (cloned per request), and contract factories.
 *
 * @internal Used only by SemitexaContainer and ContainerBootstrapper.
 */
final class InstanceStore
{
    /** @var array<string, object> id (class/interface) => shared instance (readonly, worker-scoped) */
    public array $readonly = [];

    /** @var array<class-string, object> class => prototype instance (cloned per execution) */
    public array $prototypes = [];

    /** @var array<string, object> factory interface => generated typed factory (or the generic one when not generated) */
    public array $factories = [];

    /** @var array<string, ContractFactory> factory interface => generic ContractFactory instance */
    public array $genericFactories = [];
}
