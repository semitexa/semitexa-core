<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Environment;
use Semitexa\Core\ErrorHandler;
use Semitexa\Core\Http\SwooleResponseEmitter;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SwooleSessionTableHolder;
use Semitexa\Ssr\Asset\ModuleAssetRegistry;
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

    /** Swoole Table: max string length for session_id column (session identifiers). */
    private const TABLE_COLUMN_SESSION_ID_MAX_LENGTH = 128;

    /** @return array{0: SwooleRequest, 1: SwooleResponse, 2: Server, 3: Table, 4: Table, 5: Table|null}|null */
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

        define('SEMITEXA_SWOOLE', true);

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

        // SSE cross-worker routing table (session_id -> worker_id)
        $sessionWorkerTable = new Table($env->swooleSseWorkerTableSize);
        $sessionWorkerTable->column('worker_id', Table::TYPE_INT, self::TABLE_COLUMN_INT_SIZE);
        $sessionWorkerTable->create();

        $deliverTable = new Table($env->swooleSseDeliverTableSize);
        $deliverTable->column('session_id', Table::TYPE_STRING, self::TABLE_COLUMN_SESSION_ID_MAX_LENGTH);
        $deliverTable->column('worker_id', Table::TYPE_INT, self::TABLE_COLUMN_INT_SIZE);
        $deliverTable->column('payload', Table::TYPE_STRING, $env->swooleSsePayloadMaxBytes);
        $deliverTable->create();

        $pendingDeliverTable = new Table($env->swooleSseDeliverTableSize);
        $pendingDeliverTable->column('session_id', Table::TYPE_STRING, self::TABLE_COLUMN_SESSION_ID_MAX_LENGTH);
        $pendingDeliverTable->column('payload', Table::TYPE_STRING, $env->swooleSsePayloadMaxBytes);
        $pendingDeliverTable->create();

        // Isomorphic SSR deferred-request registry — must be created pre-fork so all
        // workers share the same mmap-backed Table (page context keyed by request ID).
        $deferredRequestTable = null;
        if (class_exists(\Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::class)) {
            $isomorphicConfig = \Semitexa\Ssr\Configuration\IsomorphicConfig::fromEnvironment();
            if ($isomorphicConfig->enabled) {
                $deferredRequestTable = \Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::createSharedTable($isomorphicConfig);
            }
        }

        $corsHandler = new CorsHandler($env);
        $healthHandler = new HealthCheckHandler();
        $staticAssetHandler = new StaticAssetHandler();

        $server->on('WorkerStart', function (Server $server, int $workerId) use ($sessionWorkerTable, $deliverTable, $pendingDeliverTable, $deferredRequestTable) {
            Environment::syncEnvFromFiles();
            // Refresh the Composer autoloader classmap so any packages added since the master
            // process started (e.g. after composer update + graceful reload) are auto-loadable.
            self::refreshComposerClassMap();
            ContainerFactory::create();
            ModuleAssetRegistry::initialize();
            if (class_exists(\Semitexa\Ssr\Asset\AssetCollector::class)) {
                \Semitexa\Ssr\Asset\AssetCollector::boot();
            }
            if (class_exists(\Semitexa\Ssr\Async\AsyncResourceSseServer::class)) {
                \Semitexa\Ssr\Async\AsyncResourceSseServer::setServer($server);
                \Semitexa\Ssr\Async\AsyncResourceSseServer::setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
            }
            // Inject the pre-fork shared table so every worker can read deferred request
            // context written by any other worker, then start the per-worker GC timer.
            if ($deferredRequestTable !== null && class_exists(\Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::class)) {
                \Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::setTable($deferredRequestTable);
                \Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::initialize();
            }
            // Register the 'ssr' asset alias and publish template files in every worker
            // so StaticAssetHandler can serve /assets/ssr/tpl/*.twig from any worker.
            if (class_exists(\Semitexa\Ssr\Isomorphic\DeferredTemplateRegistry::class)) {
                \Semitexa\Ssr\Isomorphic\DeferredTemplateRegistry::initialize();
            }
        });

        $server->on('WorkerStop', function (Server $server, int $workerId) {
            // future: close DB pools, flush logs
        });

        $server->on('WorkerError', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal) {
            error_log("[Semitexa] Worker #{$workerId} (PID:{$workerPid}) error: exit={$exitCode} signal={$signal}");
        });

        $server->on('Start', function (Server $server) {
            // pid_file is written automatically via server option
        });

        $server->on('Shutdown', function (Server $server) {
            // future: cleanup
        });

        $emitter = new SwooleResponseEmitter();

        $server->on('request', function (SwooleRequest $request, SwooleResponse $response) use ($emitter, $corsHandler, $healthHandler, $staticAssetHandler, $server, $sessionWorkerTable, $deliverTable, $pendingDeliverTable) {
            $sent = false;
            $ensureResponseSent = function () use ($response, &$sent): void {
                if ($sent) {
                    return;
                }
                try {
                    @$response->status(500);
                    @$response->header('Content-Type', 'text/plain');
                    @$response->end('Internal Server Error');
                    $sent = true;
                } catch (\Throwable) {
                }
            };

            if ($healthHandler->handle($request, $response)) {
                return;
            }

            if ($staticAssetHandler->handle($request, $response)) {
                return;
            }

            if ($corsHandler->handle($request, $response)) {
                return;
            }

            Coroutine::getContext()[self::COROUTINE_CONTEXT_KEY] = [$request, $response, $server, $sessionWorkerTable, $deliverTable, $pendingDeliverTable];
            $app = null;

            try {
                $app = new Application();
                $semitexaRequest = Request::create($request);
                $semitexaResponse = $app->handleRequest($semitexaRequest);

                $emitter->emit($semitexaResponse, $response);
                $sent = true;
            } catch (\Throwable $e) {
                try {
                    $env = $app !== null ? $app->environment : Environment::create();
                    self::handleError($e, $response, $env);
                    $sent = true;
                } catch (\Throwable $inner) {
                    $ensureResponseSent();
                }
            } finally {
                unset(Coroutine::getContext()[self::COROUTINE_CONTEXT_KEY]);
                if ($app !== null) {
                    $app->requestScopedContainer->reset();
                }
                if (class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
                    \Semitexa\Ssr\Asset\AssetCollectorStore::reset();
                }
                if (!$sent) {
                    $ensureResponseSent();
                }
            }
        });

        self::printBanner($env, $config);
        $server->start();
    }

    /**
     * Re-read the Composer classmap from disk and add any new entries to the registered ClassLoader.
     * Needed in graceful-reload scenarios: the master process loaded an older classmap, and forked
     * workers inherit the stale autoloader. Calling this at WorkerStart ensures newly-installed
     * packages are auto-loadable without a full server restart.
     */
    private static function refreshComposerClassMap(): void
    {
        $composerDir = \Semitexa\Core\Util\ProjectRoot::get() . '/vendor/composer';
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

    private static function handleError(\Throwable $e, SwooleResponse $response, Environment $env): void
    {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $payload = ['error' => 'Internal Server Error'];
        if ($env->isDebug()) {
            $payload['message'] = $e->getMessage();
            $payload['file'] = $e->getFile() . ':' . $e->getLine();
            $payload['trace'] = $e->getTraceAsString();
        }
        $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function printBanner(Environment $env, ServerConfigurator $config): void
    {
        $debug = $env->isDebug() ? ' [DEBUG]' : '';
        echo "\n";
        echo "  Semitexa Server{$debug}\n";
        echo "  ─────────────────────────────\n";
        echo "  URL:       http://{$config->getHost()}:{$config->getPort()}\n";
        echo "  Env:       {$env->appEnv}\n";
        echo "  Workers:   {$env->swooleWorkerNum}\n";
        echo "  PHP:       " . PHP_VERSION . "\n";
        echo "  Swoole:    " . swoole_version() . "\n";
        echo "\n";
    }
}
