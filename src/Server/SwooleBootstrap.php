<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Environment;
use Semitexa\Core\ErrorHandler;
use Semitexa\Core\Request;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Table;

class SwooleBootstrap
{
    /** @var array{0: SwooleRequest, 1: SwooleResponse, 2: Server, 3: Table, 4: Table}|null */
    private static ?array $currentSwooleContext = null;

    public static function getCurrentSwooleRequestResponse(): ?array
    {
        return self::$currentSwooleContext;
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

        $sessionWorkerTable = new Table(4096);
        $sessionWorkerTable->column('worker_id', Table::TYPE_INT, 4);
        $sessionWorkerTable->create();

        $deliverTable = new Table(8192);
        $deliverTable->column('session_id', Table::TYPE_STRING, 128);
        $deliverTable->column('worker_id', Table::TYPE_INT, 4);
        $deliverTable->column('payload', Table::TYPE_STRING, 262144);
        $deliverTable->create();

        $pendingDeliverTable = new Table(8192);
        $pendingDeliverTable->column('session_id', Table::TYPE_STRING, 128);
        $pendingDeliverTable->column('payload', Table::TYPE_STRING, 262144);
        $pendingDeliverTable->create();

        $corsHandler = new CorsHandler($env);
        $healthHandler = new HealthCheckHandler();

        $server->on('WorkerStart', function (Server $server, int $workerId) use ($sessionWorkerTable, $deliverTable, $pendingDeliverTable) {
            Environment::syncEnvFromFiles();
            ContainerFactory::create();
            if (class_exists(\Semitexa\Ssr\Async\AsyncResourceSseServer::class)) {
                \Semitexa\Ssr\Async\AsyncResourceSseServer::setServer($server);
                \Semitexa\Ssr\Async\AsyncResourceSseServer::setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
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

        $server->on('request', function (SwooleRequest $request, SwooleResponse $response) use ($corsHandler, $healthHandler, $server, $sessionWorkerTable, $deliverTable, $pendingDeliverTable) {
            if ($healthHandler->handle($request, $response)) {
                return;
            }

            if ($corsHandler->handle($request, $response)) {
                return;
            }

            self::$currentSwooleContext = [$request, $response, $server, $sessionWorkerTable, $deliverTable, $pendingDeliverTable];
            $app = new Application();

            try {
                $semitexaRequest = Request::create($request);
                $semitexaResponse = $app->handleRequest($semitexaRequest);

                $response->status($semitexaResponse->getStatusCode());
                foreach ($semitexaResponse->getHeaders() as $name => $value) {
                    $response->header($name, $value);
                }
                if (!$semitexaResponse->isAlreadySent()) {
                    $response->end($semitexaResponse->getContent());
                }
            } catch (\Throwable $e) {
                self::handleError($e, $response, $app->getEnvironment());
            } finally {
                self::$currentSwooleContext = null;
                $app->getRequestScopedContainer()->reset();
            }
        });

        self::printBanner($env, $config);
        $server->start();
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
