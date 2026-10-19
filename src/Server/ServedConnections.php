<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * The client sessions this worker has served a request on and Swoole has not
 * closed yet — what a leaving worker closes once nothing runs any more (see
 * {@see WorkerExitDrain}).
 *
 * Swoole's own list cannot answer that. In BASE mode each worker keeps its
 * connections by fd in process memory, but the fd range every walk of them uses
 * (min_fd/max_fd) is one pair shared by all workers: a worker closing its highest
 * fd rescans ITS table and lowers the shared max, so another worker's live
 * connections above it drop out of $server->connections — and out of the walk
 * Swoole's own stop uses to remove their read events. Measured: workers held to
 * forced termination by client sockets their $server->connections did not list.
 * A session id is looked up through the session table, outside that range.
 *
 * Built before start(), like the drain, so every worker forks its own, empty copy.
 */
final class ServedConnections
{
    /** @var array<int, true> */
    private array $sessions = [];

    public function served(int $sessionId): void
    {
        $this->sessions[$sessionId] = true;
    }

    public function closed(int $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    /** @return list<int> */
    public function sessionIds(): array
    {
        return array_keys($this->sessions);
    }
}
