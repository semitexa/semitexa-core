<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Environment;
use Swoole\Http\Server;

readonly class ServerLifecycleContext
{
    public function __construct(
        public Server $server,
        public ?int $workerId,
        public Environment $environment,
        public ?ServerBootstrapState $bootstrapState = null,
        public ?int $workerPid = null,
        public ?int $exitCode = null,
        public ?int $signal = null,
        /**
         * The application container, populated for post-container phases
         * (WorkerStartAfterContainer onward). Lets lifecycle listeners resolve
         * services through their injected context instead of the static
         * ContainerFactory — the DI-compliant path the staticContainerAccess
         * rule wants. Null for phases that run before the container exists.
         */
        public ?SemitexaContainer $container = null,
    ) {
    }
}
