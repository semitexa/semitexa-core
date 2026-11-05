<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Server;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A worker recycled by request count must tell its keep-alive clients first.
 * Swoole's own max_request closes those connections with no warning, and what was
 * on its way down one is gone: about one request a recycle here. Telling them
 * leaves the clients it could not tell, such as one that connects in the worker's
 * last moment, so the bound below is small, not zero. Needs a real server: the
 * loss happens between a worker's exit and a client's next write.
 */
final class WorkerRecycleKeepsRequestsTest extends TestCase
{
    private const SERVER = <<<'PHP'
        <?php
        require $argv[1];
        [, , $port, $dir, $maxRequests] = $argv;
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $recycle = new Semitexa\Core\Server\WorkerRecycle((int) $maxRequests, 2);
        $server = new Swoole\Http\Server('127.0.0.1', (int) $port, SWOOLE_BASE);
        $server->set(['worker_num' => 2, 'max_request' => 0, 'reload_async' => true, 'max_wait_time' => 3,
            'log_file' => $dir . '/swoole.log', 'enable_coroutine' => true]);
        // Wired as SwooleBootstrap wires them.
        $connections = new Semitexa\Core\Server\ServedConnections();
        $drain = new Semitexa\Core\Server\WorkerExitDrain(
            Semitexa\Core\Server\WorkerExitDrainPolicy::forMaxWaitTime(3),
            new Semitexa\Core\Server\WorkerExitDrainReporter(),
            $connections,
        );
        $server->on('workerExit', function (Swoole\Server $server, int $id) use ($drain): void {
            $drain->pass($id, $server);
        });
        $server->on('connect', function (Swoole\Server $server, int $sessionId) use ($connections): void {
            $connections->served($sessionId);
        });
        $server->on('close', function (Swoole\Server $server, int $sessionId) use ($connections, $recycle): void {
            $connections->closed($sessionId);
            $recycle->closed($sessionId);
        });
        $server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($server, $recycle): void {
            $cid = Swoole\Coroutine::getCid();
            $clientTold = $recycle->arrived($request->fd);
            if ($clientTold) {
                $response->header('Connection', 'close');
            }
            if ($recycle->dueToLeave()) {
                Swoole\Timer::after(Semitexa\Core\Server\WorkerRecycle::LEAVE_AFTER_MS, static fn () => $server->stop());
            }
            Semitexa\Core\Server\InFlightRequests::enter($cid);
            $response->end(getmypid() . ':END');
            Semitexa\Core\Server\InFlightRequests::leave($cid);
            if ($clientTold && !$response->isWritable()) {
                $sessionId = $request->fd;
                Swoole\Timer::after(Semitexa\Core\Server\WorkerRecycle::CLOSE_AFTER_MS, static function () use ($server, $sessionId): void {
                    if ($server->exist($sessionId)) {
                        $server->close($sessionId);
                    }
                });
            }
        });
        $server->start();
        PHP;

    private const WORKERS_SEEN = 14;

    private string $dir = '';

    /** @var resource|null */
    private $process = null;

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    #[Test]
    public function a_recycle_almost_never_costs_a_request(): void
    {
        $port = $this->startServer(maxRequests: 30);

        $lost = 0;
        $workers = [];
        $socket = null;
        // Until workers have really been replaced, not for a number of requests: a
        // leaving worker answers for 100 ms, and a fast client gets a lot done in that.
        $deadline = microtime(true) + 15;
        while (count($workers) < self::WORKERS_SEEN && microtime(true) < $deadline) {
            $socket ??= $this->connect($port);
            [$pid, $closing] = $this->ask($socket);
            if ($pid === '') {
                $lost++;
            } else {
                $workers[$pid] = true;
            }
            if ($closing || $pid === '') {
                fclose($socket);
                $socket = null;
            }
        }

        // Without this the test would pass on a server that never recycled at all.
        self::assertGreaterThanOrEqual(self::WORKERS_SEEN, count($workers), 'workers were recycled and replaced');
        // Half of what Swoole's own recycle loses here (13), with room for the few that remain.
        self::assertLessThanOrEqual(6, $lost, 'clients were told to go before the worker left');
    }

    #[Test]
    public function the_server_itself_ends_a_connection_it_said_it_would(): void
    {
        $port = $this->startServer(maxRequests: 30);
        $socket = $this->connect($port);

        do {
            [$pid, $closing] = $this->ask($socket);
            self::assertNotSame('', $pid, 'answered until it says close');
        } while (!$closing);

        // A client that reads to the end of the stream must get that end. It keeps
        // the socket open and sends nothing, so only the server can end this.
        self::assertTrue($this->endsSoon($socket), 'closed by the server, not left to the client');
    }

    /** @param resource $socket */
    private function endsSoon($socket): bool
    {
        stream_set_timeout($socket, 2);
        $tail = fread($socket, 8192);

        return ($tail === '' || $tail === false) && feof($socket);
    }

    /** @param resource $socket */
    private function send($socket, string $path): void
    {
        @fwrite($socket, "GET $path HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
    }

    /**
     * @param resource $socket
     * @return array{string, bool} the worker that answered, and whether it ended the connection
     */
    private function ask($socket): array
    {
        $this->send($socket, '/');

        return $this->read($socket);
    }

    /**
     * @param resource $socket
     * @return array{string, bool}
     */
    private function read($socket): array
    {
        $reply = '';
        $deadline = microtime(true) + 3;
        while (!str_ends_with($reply, ':END') && !feof($socket) && microtime(true) < $deadline) {
            $reply .= (string) fread($socket, 8192);
        }
        if (!str_ends_with($reply, ':END')) {
            return ['', true];
        }
        $body = substr($reply, (int) strrpos($reply, "\r\n\r\n") + 4, -4);

        return [$body, stripos($reply, "connection: close\r\n") !== false];
    }

    private function startServer(int $maxRequests): int
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-recycle-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        file_put_contents($this->dir . '/server.php', self::SERVER);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $process = proc_open(
            [PHP_BINARY, $this->dir . '/server.php', $autoload, (string) $port, $this->dir, (string) $maxRequests],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/stdout', 'w'], 2 => ['file', $this->dir . '/stderr', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->process = $process;

        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }
        self::fail('the server did not start: ' . @file_get_contents($this->dir . '/stderr'));
    }

    private function stopServer(): void
    {
        if ($this->dir === '') {
            return;
        }
        // The master, its manager and the workers all carry the fixture's path:
        // killing only the master would leave the rest running, port and all.
        // Repeated, because a manager can respawn a worker after one sweep.
        for ($sweep = 0; $sweep < 20; $sweep++) {
            $found = false;
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
                if (str_contains((string) @file_get_contents($cmdline), $this->dir . '/server.php')) {
                    $found = true;
                    @posix_kill((int) basename(dirname($cmdline)), SIGKILL);
                }
            }
            if (!$found) {
                break;
            }
            usleep(50_000);
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        $this->dir = '';
    }

    /** @return resource */
    private function connect(int $port)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0);
        self::assertNotFalse($socket, (string) $error);
        stream_set_timeout($socket, 1);

        return $socket;
    }
}
