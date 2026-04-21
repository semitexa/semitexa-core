<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use JsonException;
use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Environment;
use Semitexa\Core\ErrorHandler;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\SwooleResponseEmitter;
use Semitexa\Core\Console\Runtime\RuntimePidfile;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerBootstrapState;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleInvoker;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleRegistry;
use Semitexa\Core\Session\SwooleSessionTableHolder;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Asset\StaticAssetHandler;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Table;
class SwooleBootstrap
{
    private const COROUTINE_CONTEXT_KEY = '__semitexa_swoole_ctx';

    /** Swoole Table: integer column size in bytes (32-bit). */
    private const TABLE_COLUMN_INT_SIZE = 4;

    /** @return array{0: SwooleRequest, 1: SwooleResponse, 2: Server}|null */
    public static function getCurrentSwooleRequestResponse(): ?array
    {
        if (Coroutine::getCid() < 0) {
            return null;
        }
        return Coroutine::getContext()[self::COROUTINE_CONTEXT_KEY] ?? null;
    }

    public static function run(): void
    {
        self::verifyRequirements();

        // Hook all blocking PHP functions (PDO, streams, file I/O, sleep, etc.)
        // so they yield to other coroutines instead of blocking the worker.
        // Must be called before Server creation.
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $env = Environment::create();
        ErrorHandler::configure($env);

        $config = new ServerConfigurator($env);
        $server = new Server($config->getHost(), $config->getPort());
        $server->set($config->getServerOptions());

        // Session storage table (worker-shared, per-session data)
        $sessionTable = new Table($env->swooleSessionTableSize);
        $sessionTable->column('data', Table::TYPE_STRING, $env->swooleSessionMaxBytes);
        $sessionTable->column('expires_at', Table::TYPE_INT, self::TABLE_COLUMN_INT_SIZE);
        $sessionTable->create();
        SwooleSessionTableHolder::setTable($sessionTable);

        $corsHandler = new CorsHandler($env);
        $healthHandler = new HealthCheckHandler();
        $metricsHandler = new MetricsHandler($server);
        /** @var ModuleRegistry $moduleRegistry */
        $moduleRegistry = ContainerFactory::get()->get(ModuleRegistry::class);
        $staticAssetHandler = new StaticAssetHandler($moduleRegistry);
        $bootstrapState = new ServerBootstrapState();
        $lifecycleRegistry = new ServerLifecycleRegistry(new ClassDiscovery());
        $lifecycleInvoker = new ServerLifecycleInvoker($lifecycleRegistry);
        $bootstrapContext = new ServerLifecycleContext(
            server: $server,
            workerId: null,
            environment: $env,
            bootstrapState: $bootstrapState,
        );
        $lifecycleInvoker->invokePhase(ServerLifecyclePhase::PreStart, $bootstrapContext, false);

        $server->on(SwooleEvent::WorkerStart->value, function (Server $server, int $workerId) use ($bootstrapState, $lifecycleInvoker) {
            self::syncInheritedComposerAutoloader();
            Environment::syncEnvFromFiles();
            $workerEnv = Environment::create();
            $context = new ServerLifecycleContext(
                server: $server,
                workerId: $workerId,
                environment: $workerEnv,
                bootstrapState: $bootstrapState,
            );
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerStartBeforeContainer, $context, false);
            ContainerFactory::create();
            // Switch to container-managed registry (uses container's ClassDiscovery,
            // avoiding duplicate attribute scanning).
            $containerRegistry = ContainerFactory::get()->get(ServerLifecycleRegistry::class);
            if ($containerRegistry instanceof ServerLifecycleRegistry) {
                $lifecycleInvoker->setRegistry($containerRegistry);
            }
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerStartAfterContainer, $context, true);
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerStartAfterServerBindings, $context, true);
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerStartFinalize, $context, true);
        });

        $server->on(SwooleEvent::WorkerStop->value, function (Server $server, int $workerId) use ($bootstrapState, $lifecycleInvoker) {
            $context = new ServerLifecycleContext(
                server: $server,
                workerId: $workerId,
                environment: Environment::create(),
                bootstrapState: $bootstrapState,
            );
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerStop, $context, true);
        });

        $server->on(SwooleEvent::WorkerError->value, function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal) use ($bootstrapState, $lifecycleInvoker) {
            ServerLifecycleFallbackLogger::logWorkerError($workerId, $workerPid, $exitCode, $signal);
            $context = new ServerLifecycleContext(
                server: $server,
                workerId: $workerId,
                environment: Environment::create(),
                bootstrapState: $bootstrapState,
                workerPid: $workerPid,
                exitCode: $exitCode,
                signal: $signal,
            );
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::WorkerError, $context, false);
        });

        $server->on(SwooleEvent::Start->value, function (Server $server) use ($bootstrapState, $lifecycleInvoker) {
            // Write the identity cookie so server:reload / server:stop can verify the
            // pidfile PID before signalling; without this the PID can be recycled to
            // an unrelated process and the signal delivered to the wrong target.
            //
            // The cookie MUST align with Swoole's own pidfile (the `pid_file` config
            // option), because that is the PID the reload/stop verifier reads and the
            // target of SIGUSR1/SIGTERM. Using `$server->master_pid` drifts in the
            // Docker-PID-1 case: Swoole daemonises, the original process is stuck as
            // PID 1 (container init cannot exit), so Swoole treats PID 1 as manager
            // and the forked child as master. The pidfile is written pre-fork (PID 1),
            // but `$server->master_pid` is the post-fork child — they disagree and
            // verifyProcess rejects the signal.
            $projectRoot = \Semitexa\Core\Support\ProjectRoot::get();
            $pidfilePath = $projectRoot . '/var/run/semitexa.pid';
            $pidfilePid = 0;
            if (is_readable($pidfilePath)) {
                $pidfileRaw = @file_get_contents($pidfilePath);
                if ($pidfileRaw !== false) {
                    $pidfilePid = (int) trim($pidfileRaw);
                }
            }
            /** @var int $masterPid */
            $masterPid = $server->master_pid;
            $cookiePid = $pidfilePid > 0 ? $pidfilePid : $masterPid;

            $rawScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $scriptPath = is_string($rawScript) ? $rawScript : '';

            RuntimePidfile::writeCookie(
                $projectRoot,
                $cookiePid,
                $scriptPath,
            );

            $context = new ServerLifecycleContext(
                server: $server,
                workerId: null,
                environment: Environment::create(),
                bootstrapState: $bootstrapState,
            );
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::Start, $context, false);
        });

        $server->on(SwooleEvent::Shutdown->value, function (Server $server) use ($bootstrapState, $lifecycleInvoker) {
            $context = new ServerLifecycleContext(
                server: $server,
                workerId: null,
                environment: Environment::create(),
                bootstrapState: $bootstrapState,
            );
            $lifecycleInvoker->invokePhase(ServerLifecyclePhase::Shutdown, $context, false);
        });

        $emitter = new SwooleResponseEmitter();

        $server->on(SwooleEvent::Request->value, function (SwooleRequest $request, SwooleResponse $response) use ($emitter, $corsHandler, $healthHandler, $metricsHandler, $staticAssetHandler, $server) {
            $sent = false;
            $ensureResponseSent = function () use ($response, &$sent): void {
                if ($sent) {
                    return;
                }
                try {
                    @$response->status(HttpStatus::InternalServerError->value);
                    @$response->header('Content-Type', 'text/plain');
                    @$response->end('Internal Server Error');
                    $sent = true;
                } catch (\Throwable) {
                }
            };

            if ($healthHandler->handle($request, $response)) {
                return;
            }

            if ($metricsHandler->handle($request, $response)) {
                return;
            }

            if ($staticAssetHandler->handle($request, $response)) {
                return;
            }

            if ($corsHandler->handle($request, $response)) {
                return;
            }

            Coroutine::getContext()[self::COROUTINE_CONTEXT_KEY] = [$request, $response, $server];
            $app = null;
            $semitexaRequest = null;

            try {
                $app = new Application();
                $semitexaRequest = Request::create($request);
                $semitexaResponse = $app->handleRequest($semitexaRequest);

                $emitter->emit($semitexaResponse, $response);
                $sent = true;
            } catch (\Throwable $e) {
                try {
                    $env = $app !== null ? $app->environment : Environment::create();
                    self::handleError($e, $response, $env, $semitexaRequest, $app);
                    $sent = true;
                } catch (\Throwable $inner) {
                    $ensureResponseSent();
                }
            } finally {
                unset(Coroutine::getContext()[self::COROUTINE_CONTEXT_KEY]);
                $app?->requestScopedContainer->reset();
                if (class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
                    \Semitexa\Ssr\Asset\AssetCollectorStore::reset();
                }
                if (!$sent) {
                    $ensureResponseSent();
                }
            }
        });

        $server->start();
    }

    /**
     * Sync the inherited Composer ClassLoader with the latest files from vendor/composer.
     *
     * The reload command does not rebuild autoload itself; this hook keeps newly-forked workers
     * aligned with whatever autoload files are already present on disk after a restart or build.
     */
    private static function syncInheritedComposerAutoloader(): void
    {
        $composerDir = \Semitexa\Core\Support\ProjectRoot::get() . '/vendor/composer';
        $classMapFile = $composerDir . '/autoload_classmap.php';
        $psr4File = $composerDir . '/autoload_psr4.php';

        if (!is_file($classMapFile)) {
            return;
        }

        try {
            $freshClassMap = require $classMapFile;
            $freshPsr4 = is_file($psr4File) ? require $psr4File : [];

            foreach (spl_autoload_functions() as $loader) {
                if (!is_array($loader) || !($loader[0] instanceof \Composer\Autoload\ClassLoader)) {
                    continue;
                }
                /** @var \Composer\Autoload\ClassLoader $classLoader */
                $classLoader = $loader[0];
                $classLoader->addClassMap($freshClassMap);
                foreach ($freshPsr4 as $namespace => $dirs) {
                    $classLoader->addPsr4($namespace, $dirs);
                }
                break;
            }
        } catch (\Throwable) {
            // Autoloader refresh is best-effort; never block worker startup.
        }
    }

    private static function verifyRequirements(): void
    {
        if (PHP_VERSION_ID < 80400) {
            fwrite(STDERR, "Error: Semitexa requires PHP 8.4+, got " . PHP_VERSION . "\n");
            exit(1);
        }
        if (!extension_loaded('swoole')) {
            fwrite(STDERR, "Error: Swoole extension is required.\n");
            exit(1);
        }
    }

    /**
     * @throws JsonException
     */
    private static function handleError(
        \Throwable $e,
        SwooleResponse $response,
        Environment $env,
        ?Request $request = null,
        ?Application $app = null,
    ): void
    {
        $errorResponse = self::buildFatalErrorResponse($e, $env, $request, $app);
        /** @var array<string, mixed> $headers */
        $headers = $errorResponse->getHeaders();

        $response->status($errorResponse->getStatusCode());
        self::emitResponseHeaders(
            $headers,
            static fn (string $header, mixed $value): mixed => call_user_func([$response, 'header'], $header, $value),
        );

        $response->end($errorResponse->getContent());
    }

    public static function buildFatalErrorResponse(
        \Throwable $e,
        Environment $env,
        ?Request $request = null,
        ?Application $app = null,
    ): HttpResponse {
        if ($app !== null && $request !== null) {
            try {
                $routeResponse = $app->renderErrorThrowable($e, $request);
                if ($routeResponse !== null) {
                    return $routeResponse;
                }
            } catch (\Throwable) {
                // Fatal recovery must degrade immediately to the safe renderer.
            }
        }

        return \Semitexa\Core\Http\ErrorRenderer::render($e, $request, $env->isDebug());
    }

    /**
     * @param array<string, mixed> $headers
     * @param \Closure(string, mixed): mixed $headerEmitter
     */
    private static function emitResponseHeaders(array $headers, \Closure $headerEmitter): void
    {
        foreach ($headers as $header => $value) {
            $headerEmitter($header, $value);
        }
    }
}
