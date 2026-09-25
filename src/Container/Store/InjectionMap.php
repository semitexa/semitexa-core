<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Store;

/**
 * Stores injection metadata for all container-managed classes.
 * Each entry maps a class to its property injection specifications.
 *
 * @internal Used only by SemitexaContainer and ContainerBootstrapper.
 */
final class InjectionMap
{
    /** @var array<class-string, array<string, array{kind: string, type: class-string, optional?: bool}>> */
    public array $injections = [];
}
