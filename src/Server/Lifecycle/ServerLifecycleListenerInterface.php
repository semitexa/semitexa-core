<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

/** @api */
interface ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void;
}
