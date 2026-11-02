<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Environment;

/**
 * The worker start-up an in-process request needs, run once per process:
 * semitexa/testing's CallsRoutes calls it before its first request.
 *
 * Found by the delivery benchmark (bench-inprocess-twig-500): a page test
 * through CallsRoutes got 500 for any Twig page, because the registries a page
 * render reads (assets, components, templates, translation overrides) are
 * wired by WorkerStartAfterContainer listeners, and in a test no worker has
 * started. The benchmark's baseline ran that phase by hand in
 * setUpBeforeClass(); every project's page test would have had to.
 *
 * So the first in-process request runs the phase, as a Swoole worker runs it
 * after building its container, with no server and no worker id.
 */
final class InProcessWorkerStart
{
    #[WorkerState('Whether this process ran the worker start-up phase: once per process, as a worker does.')]
    private static bool $started = false;

    public static function once(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        $container = ContainerFactory::get();
        /** @var ServerLifecycleRegistry $registry */
        $registry = $container->get(ServerLifecycleRegistry::class);
        /** @var Environment $environment */
        $environment = $container->get(Environment::class);

        (new ServerLifecycleInvoker($registry))->invokePhase(
            ServerLifecyclePhase::WorkerStartAfterContainer,
            new ServerLifecycleContext(server: null, workerId: null, environment: $environment, container: $container),
            true,
        );
    }
}
