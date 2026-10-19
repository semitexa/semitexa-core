<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Server;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * The memory watchdog, end to end on a real server (tk-parity-proof-gaps):
 * the unit test proves the threshold arithmetic, not that a worker which grew
 * past it is actually stopped, stops gracefully and is replaced by one that
 * keeps serving. The fixture wires the listener and the exit drain as
 * SwooleBootstrap does; memory_limit 256M at ratio 0.25 puts the line at 64M,
 * and the 100M a request holds crosses it with room left to leave gracefully.
 *
 * Two workers, as any real deployment has: in SWOOLE_BASE there is no manager
 * for a single worker, and Server::stop() on it ends the whole server.
 */
final class WorkerMemoryWatchdogRecycleTest extends TestCase
{
    private const SERVER = <<<'PHP'
        <?php
        require $argv[1];
        [, , $port, $dir] = $argv;
        $server = new Swoole\Http\Server('127.0.0.1', (int) $port, SWOOLE_BASE);
        $server->set(['worker_num' => 2, 'reload_async' => true, 'max_wait_time' => 3,
            'log_file' => $dir . '/swoole.log', 'enable_coroutine' => true]);
        $drain = new Semitexa\Core\Server\WorkerExitDrain(
            Semitexa\Core\Server\WorkerExitDrainPolicy::forMaxWaitTime(3),
            new Semitexa\Core\Server\WorkerExitDrainReporter(),
            new Semitexa\Core\Server\ServedConnections(),
        );
        $environment = (new ReflectionClass(Semitexa\Core\Environment::class))->newInstanceWithoutConstructor();
        // The worker logger, set at boot as SwooleBootstrap sets it.
        Semitexa\Core\Log\StaticLoggerBridge::set(new class ($dir . '/worker.log') implements Semitexa\Core\Log\LoggerInterface {
            public function __construct(private string $file) {}
            public function error(string $message, array $context = []): void { $this->write($message, $context); }
            public function critical(string $message, array $context = []): void { $this->write($message, $context); }
            public function warning(string $message, array $context = []): void { $this->write($message, $context); }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            private function write(string $message, array $context): void
            {
                file_put_contents($this->file, json_encode(['message' => $message] + $context) . "\n", FILE_APPEND);
            }
        });
        $server->on('workerStart', function (Swoole\Server $server, int $id) use ($environment): void {
            (new Semitexa\Core\Server\Lifecycle\WorkerMemoryWatchdogListener())->handle(
                new Semitexa\Core\Server\Lifecycle\ServerLifecycleContext($server, $id, $environment, workerPid: getmypid()),
            );
        });
        $server->on('workerExit', function (Swoole\Server $server, int $id) use ($drain): void {
            $drain->pass($id, $server);
        });
        $server->on('workerStop', function (Swoole\Server $server, int $id) use ($dir): void {
            file_put_contents($dir . '/stopped-' . getmypid(), (string) microtime(true));
        });
        $hog = [];
        $server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use (&$hog): void {
            if ($request->server['request_uri'] === '/hog') {
                $hog[] = str_repeat('x', 100 << 20);
            }
            $response->end(getmypid() . ':END');
        });
        $server->start();
        PHP;

    private string $dir = '';

    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class) || !function_exists('proc_open') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs the Swoole extension, proc_open and posix_kill.');
        }
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    #[Test]
    public function a_worker_that_grows_past_the_threshold_is_replaced_and_the_server_keeps_serving(): void
    {
        $port = $this->startServer();

        $before = $this->workers($port);
        self::assertCount(2, $before);
        usleep(2_500_000);
        foreach ($before as $pid) {
            self::assertTrue(posix_kill($pid, 0), 'under the threshold a worker is left alone');
        }

        $grown = (int) $this->ask($port, '/hog');
        self::assertContains($grown, $before);
        $deadline = microtime(true) + 8;
        while (posix_kill($grown, 0) && microtime(true) < $deadline) {
            usleep(200_000);
        }

        self::assertFalse(posix_kill($grown, 0), 'the grown worker is still running');
        self::assertFileExists($this->dir . '/stopped-' . $grown, 'a forced kill never reaches workerStop: the stop was not graceful');
        $after = $this->workers($port);
        self::assertCount(2, $after, 'a replacement took the grown worker\'s place');
        self::assertNotContains($grown, $after);
        $said = json_decode(trim((string) @file_get_contents($this->dir . '/worker.log')), true);
        self::assertIsArray($said, 'the worker left without saying why');
        self::assertStringContainsString('recycling the worker', $said['message']);
        self::assertGreaterThan($said['threshold_bytes'], $said['memory_bytes']);
        self::assertArrayHasKey('parked_at', $said, 'and what it was holding');
    }

    /**
     * The worker processes: the master's children. Which worker takes a
     * connection is the kernel's choice, so they are read from /proc rather
     * than asked over HTTP; HTTP only proves that someone still answers.
     *
     * @return list<int>
     */
    private function workers(int $port): array
    {
        self::assertMatchesRegularExpression('/^\d+$/', $this->ask($port, '/'), 'the server stopped serving');
        self::assertIsResource($this->process);
        $master = proc_get_status($this->process)['pid'];
        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            // pid (comm) state ppid …; comm may hold spaces, so split after the last ')'.
            $fields = explode(' ', substr((string) @file_get_contents($stat), (int) strrpos((string) @file_get_contents($stat), ')') + 2));
            if ((int) ($fields[1] ?? 0) === $master) {
                $children[] = (int) basename(dirname($stat));
            }
        }
        sort($children);

        return $children;
    }

    private function ask(int $port, string $path): string
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2.0);
        if ($socket === false) {
            return 'unreachable: ' . $error;
        }
        stream_set_timeout($socket, 2);
        fwrite($socket, "GET $path HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $reply = '';
        $deadline = microtime(true) + 5;
        while (!str_ends_with($reply, ':END') && !feof($socket) && microtime(true) < $deadline) {
            $reply .= (string) fread($socket, 8192);
        }
        fclose($socket);

        return str_ends_with($reply, ':END') ? substr($reply, (int) strrpos($reply, "\r\n\r\n") + 4, -4) : 'no answer';
    }

    private function startServer(): int
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-watchdog-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        file_put_contents($this->dir . '/server.php', self::SERVER);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $env = getenv() + ['SWOOLE_MEMORY_WATCHDOG_RATIO' => '0.25', 'SWOOLE_MEMORY_WATCHDOG_INTERVAL' => '1'];
        $env['SWOOLE_MEMORY_WATCHDOG_RATIO'] = '0.25';
        $env['SWOOLE_MEMORY_WATCHDOG_INTERVAL'] = '1';
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=256M', $this->dir . '/server.php', $autoload, (string) $port, $this->dir],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/stdout', 'w'], 2 => ['file', $this->dir . '/stderr', 'w']],
            $pipes,
            null,
            $env,
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
        // Master, manager and workers all carry the fixture's path; repeated,
        // because a manager can respawn a worker after one sweep.
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
}
