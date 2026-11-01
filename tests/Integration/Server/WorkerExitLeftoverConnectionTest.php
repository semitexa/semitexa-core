<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Server;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * A leaving worker with no coroutine and no timer left must still be able to
 * exit, yet an idle keep-alive client held it to "worker exit timeout, forced
 * termination" two ways: Swoole re-arms the read event of a connection whose
 * response was still flushing when the stop began, and a connection above the
 * fd range all workers share is skipped by the stop altogether. Needs a real
 * server: both happen inside Swoole's reactor, nowhere a unit seam can see.
 */
final class WorkerExitLeftoverConnectionTest extends TestCase
{
    private const SERVER = <<<'PHP'
        <?php
        require $argv[1];
        [, , $port, $dir, $workers, $maxRequest] = $argv;
        $server = new Swoole\Http\Server('127.0.0.1', (int) $port, SWOOLE_BASE);
        $server->set(['worker_num' => (int) $workers, 'max_request' => (int) $maxRequest, 'reload_async' => true,
            'max_wait_time' => 3, 'log_file' => $dir . '/swoole.log', 'enable_coroutine' => true]);
        // Wired as SwooleBootstrap wires them.
        $connections = new Semitexa\Core\Server\ServedConnections();
        $drain = new Semitexa\Core\Server\WorkerExitDrain(
            Semitexa\Core\Server\WorkerExitDrainPolicy::forMaxWaitTime(3),
            new Semitexa\Core\Server\WorkerExitDrainReporter(),
            $connections,
        );
        $server->on('workerExit', function (Swoole\Server $server, int $id) use ($drain, $dir): void {
            // Whether a response was still flushing when the drain began: a write
            // event in this worker's epoll, read from the kernel's own record.
            if (!is_file($dir . '/flushing-' . $id)) {
                $writing = false;
                foreach (scandir('/proc/self/fd') ?: [] as $fd) {
                    if (@readlink('/proc/self/fd/' . $fd) === 'anon_inode:[eventpoll]') {
                        foreach (file('/proc/self/fdinfo/' . $fd) ?: [] as $line) {
                            $writing = $writing || (preg_match('/^tfd:\s*\d+\s+events:\s*([0-9a-f]+)/', $line, $m) === 1 && (hexdec($m[1]) & 0x4) !== 0);
                        }
                    }
                }
                file_put_contents($dir . '/flushing-' . $id, $writing ? 'yes' : 'no');
            }
            $drain->pass($id, $server);
        });
        $server->on('workerStop', function (Swoole\Server $server, int $id) use ($dir): void {
            file_put_contents($dir . '/stopped-' . $id, (string) microtime(true));
        });
        $server->on('close', function (Swoole\Server $server, int $sessionId) use ($connections): void {
            $connections->closed($sessionId);
        });
        $server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($server, $connections): void {
            $connections->served($request->fd);
            $listed = static fn (): bool => in_array($request->fd, iterator_to_array($server->connections), true);
            $response->end(match ($request->server['request_uri']) {
                '/big' => str_repeat('x', 32 << 20) . 'END',
                '/who' => $server->getWorkerId() . ':' . $server->getClientInfo($request->fd)['socket_fd'] . ':END',
                '/listed' => ($listed() ? 'yes' : 'no') . ':END',
                default => 'ok:END',
            });
        });
        $server->start();
        PHP;

    private string $dir = '';

    /** @var resource|null */
    private $process = null;


    protected function tearDown(): void
    {
        $this->stopServer();
    }

    #[Test]
    public function a_keep_alive_client_on_a_flushed_response_does_not_hold_the_worker(): void
    {
        // max_request 2: the second request starts the drain while the first still flushes.
        $port = $this->startServer(workers: 1, maxRequest: 2);

        $big = $this->connect($port);
        fwrite($big, "GET /big HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
        // Not read yet: the 32 MiB response stays in Swoole's buffer, write event on.
        usleep(200_000);
        $last = $this->connect($port);
        $this->ask($last, '/');
        $drainBegan = microtime(true);
        usleep(500_000);

        $body = '';
        $deadline = microtime(true) + 10;
        while (!str_ends_with($body, 'END') && !feof($big) && microtime(true) < $deadline) {
            $body .= (string) fread($big, 1 << 20);
        }
        self::assertSame('yes', @file_get_contents($this->dir . '/flushing-0'), 'the response was not flushing when the drain began');
        self::assertStringEndsWith('END', $body, 'the response that was flushing when the drain began arrives whole');

        // The client keeps the connection open, as a browser's keep-alive pool does.
        $this->assertStoppedPromptly(0, $drainBegan);
        fclose($big);
        fclose($last);
    }

    #[Test]
    public function a_connection_above_the_shared_fd_range_does_not_hold_the_worker(): void
    {
        // Which worker the kernel hands a connection to is not ours to choose, so
        // the arrangement is retried until the precondition really holds.
        $kept = null;
        for ($attempt = 0; $attempt < 8 && $kept === null; $attempt++) {
            $this->stopServer();
            $kept = $this->hideAConnectionAboveTheSharedRange($this->startServer(workers: 2, maxRequest: 1000));
        }
        // Never arranged: Swoole no longer lowers the shared range by one worker's
        // table, and ServedConnections may have outlived its reason.
        self::assertNotNull($kept, 'no connection could be hidden above the shared fd range');

        $reloadedAt = microtime(true);
        self::assertIsResource($this->process);
        posix_kill(proc_get_status($this->process)['pid'], SIGUSR1);

        $this->assertStoppedPromptly($kept['worker'], $reloadedAt);
        fclose($kept['socket']);
    }

    /**
     * Opens connections until both workers hold one, keeps one of worker A's and
     * closes the rest, highest fd first: a worker closing the shared max_fd lowers
     * it by its own table, so B's last close can leave A's connection above it.
     *
     * @return array{worker: int, socket: resource}|null null when A's connection stayed listed
     */
    private function hideAConnectionAboveTheSharedRange(int $port): ?array
    {
        $owned = [];
        for ($i = 0; $i < 24 && count(array_unique(array_column($owned, 'worker'))) < 2; $i++) {
            $socket = $this->connect($port);
            [$worker, $fd] = array_map('intval', explode(':', $this->ask($socket, '/who')));
            $owned[] = ['worker' => $worker, 'fd' => $fd, 'socket' => $socket];
        }
        $kept = $owned[0];
        usort($owned, static fn (array $x, array $y): int => [$y['fd'], $x['worker'] !== $kept['worker']] <=> [$x['fd'], $y['worker'] !== $kept['worker']]);
        foreach ($owned as $connection) {
            if ($connection['socket'] !== $kept['socket']) {
                fclose($connection['socket']);
                usleep(20_000);
            }
        }
        usleep(100_000);
        if ($this->ask($kept['socket'], '/listed') !== 'no') {
            fclose($kept['socket']);

            return null;
        }

        return ['worker' => $kept['worker'], 'socket' => $kept['socket']];
    }

    private function assertStoppedPromptly(int $workerId, float $since): void
    {
        $stoppedAt = null;
        for ($i = 0; $i < 100 && $stoppedAt === null; $i++) {
            usleep(50_000);
            if (is_file($this->dir . '/stopped-' . $workerId)) {
                $stoppedAt = (float) file_get_contents($this->dir . '/stopped-' . $workerId);
            }
        }
        // A held worker is killed by the manager and never reaches workerStop; and
        // under 2 s is inside max_wait_time (3 s), before any forced termination.
        // verify:accept-test-change the swoole.log check passed whenever the log was never written; the stop time inside max_wait_time is the check
        self::assertNotNull($stoppedAt, 'the worker was held until it was killed');
        self::assertLessThan(2.0, $stoppedAt - $since, 'the worker waited on a connection nothing was running for');
    }

    /** @param resource $socket */
    private function ask($socket, string $path): string
    {
        fwrite($socket, "GET $path HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
        $reply = '';
        $deadline = microtime(true) + 5;
        while (!str_ends_with($reply, ':END') && !feof($socket) && microtime(true) < $deadline) {
            $reply .= (string) fread($socket, 8192);
        }

        return substr($reply, (int) strrpos($reply, "\r\n\r\n") + 4, -4);
    }

    private function startServer(int $workers, int $maxRequest): int
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-drain-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        file_put_contents($this->dir . '/server.php', self::SERVER);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $process = proc_open(
            [PHP_BINARY, $this->dir . '/server.php', $autoload, (string) $port, $this->dir, (string) $workers, (string) $maxRequest],
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
        // A server that stops answering fails the test instead of hanging the suite.
        stream_set_timeout($socket, 1);

        return $socket;
    }
}
