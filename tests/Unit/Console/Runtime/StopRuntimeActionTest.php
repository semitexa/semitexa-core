<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Console\Runtime\StopRuntimeAction;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * server:stop SIGTERMs, then SIGKILLs, every listener on SWOOLE_PORT, with no
 * pidfile identity check — so the port match has to be exact. It was a prefix
 * match: stopping a server on :80 killed whatever listened on :8080 or :8000.
 *
 * A stub `ss` on PATH feeds the real pipeline a fixed listener table.
 */
final class StopRuntimeActionTest extends TestCase
{
    private const SS_OUTPUT = <<<'SS'
        State  Recv-Q Send-Q Local Address:Port  Peer Address:Port Process
        LISTEN 0      511          0.0.0.0:8080       0.0.0.0:*     users:(("nginx",pid=111,fd=6))
        LISTEN 0      511          0.0.0.0:8000       0.0.0.0:*     users:(("php",pid=222,fd=4))
        LISTEN 0      511          0.0.0.0:80         0.0.0.0:*     users:(("php",pid=333,fd=4))
        LISTEN 0      511             [::]:80            [::]:*     users:(("php",pid=444,fd=5))
        LISTEN 0      511             [::]:8081          [::]:*     users:(("node",pid=555,fd=7))
        LISTEN 0      4096   127.0.0.53%lo:53         0.0.0.0:*     users:(("resolved",pid=666,fd=13))
        LISTEN 0      511          0.0.0.0:9502       0.0.0.0:*     users:(("php",pid=777,fd=3),("php",pid=778,fd=3),("php",pid=779,fd=3))
        SS;

    private string $binDir;
    private string|false $previousPath;

    protected function setUp(): void
    {
        $this->binDir = sys_get_temp_dir() . '/stop-runtime-ss-' . bin2hex(random_bytes(6));
        mkdir($this->binDir);
        file_put_contents($this->binDir . '/listeners.txt', self::SS_OUTPUT . "\n");
        file_put_contents($this->binDir . '/ss', "#!/bin/sh\ncat '{$this->binDir}/listeners.txt'\n");
        chmod($this->binDir . '/ss', 0o755);

        $this->previousPath = getenv('PATH');
        putenv('PATH=' . $this->binDir . ($this->previousPath !== false ? ':' . $this->previousPath : ''));
    }

    protected function tearDown(): void
    {
        putenv($this->previousPath !== false ? 'PATH=' . $this->previousPath : 'PATH');
        @unlink($this->binDir . '/ss');
        @unlink($this->binDir . '/listeners.txt');
        @rmdir($this->binDir);
    }

    #[Test]
    public function only_listeners_on_exactly_the_swoole_port_are_selected(): void
    {
        self::assertSame([333, 444], $this->pidsOnPort(80), 'IPv4 and IPv6 listeners on :80, and nothing on :8080/:8000/:8081');
        self::assertSame([111], $this->pidsOnPort(8080));
        self::assertSame([666], $this->pidsOnPort(53), 'an interface-scoped address still matches');
        self::assertSame([], $this->pidsOnPort(8));
    }

    #[Test]
    public function every_process_sharing_one_listening_socket_is_selected(): void
    {
        // Swoole master/manager/workers share the socket: ss lists them all
        // on ONE line, and keeping only the last pid left the rest running.
        self::assertSame([777, 778, 779], $this->pidsOnPort(9502));
    }

    /** @return list<int> */
    private function pidsOnPort(int $port): array
    {
        $action = new StopRuntimeAction(new SymfonyStyle(new ArrayInput([]), new NullOutput()));
        $pids = (new \ReflectionMethod(StopRuntimeAction::class, 'getPidsOnPort'))->invoke($action, $port);
        sort($pids);

        return $pids;
    }
}
