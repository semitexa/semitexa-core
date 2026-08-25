<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\WorkerCrashLoopBreaker;
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
        /**
         * How many times THIS worker has died abnormally in a row, within the
         * crash-loop window — 0 for a worker booting clean. Populated on the
         * WorkerStart phases; see {@see workerIsCrashLooping()}.
         */
        public int $recentWorkerCrashes = 0,
    ) {
    }

    /**
     * True when this worker has been dying and respawning rather than running.
     *
     * Optional boot-path work — warm-ups, prefetches, anything the server is correct
     * without — should check this and stand down. Swoole cannot be told to stop
     * respawning, so if the optional work is what kills the worker, repeating it on
     * every respawn is what turns one unreachable dependency into a permanently
     * pinned core. Work the server genuinely needs should NOT consult this: failing
     * loudly is better than booting half a server.
     */
    public function workerIsCrashLooping(): bool
    {
        return $this->recentWorkerCrashes >= WorkerCrashLoopBreaker::TRIP_THRESHOLD;
    }
}
