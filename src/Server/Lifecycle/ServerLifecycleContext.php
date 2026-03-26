<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

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
    ) {
    }
}
