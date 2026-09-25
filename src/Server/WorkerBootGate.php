<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Swoole\Coroutine\Channel;

/**
 * Holds requests back until the worker that received them has finished booting.
 *
 * Swoole runs onWorkerStart in a coroutine, and the moment that coroutine
 * yields — hooked file I/O while the container is built, a database warm-up
 * connecting — the event loop is free to dispatch onRequest on the same
 * worker. The request then meets registries the later lifecycle listeners
 * have not wired yet. Measured on a fresh semitexa-ultimate: the first request
 * after every start answered 500 with "AssetCollector requires ModuleRegistry
 * instance".
 *
 * One instance per worker process: close() at the top of onWorkerStart, open()
 * when it returns. A closed Swoole channel wakes every coroutine parked in
 * pop(), so all held requests resume together.
 */
final class WorkerBootGate
{
    private ?Channel $channel = null;

    private bool $open = true;

    public function close(): void
    {
        $this->open = false;
        $this->channel = new Channel(1);
    }

    public function open(): void
    {
        $this->open = true;
        $channel = $this->channel;
        $this->channel = null;
        $channel?->close();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * @return bool true once the worker has booted; false when $timeoutSeconds ran out first
     */
    public function wait(float $timeoutSeconds): bool
    {
        if ($this->open) {
            return true;
        }

        $this->channel?->pop($timeoutSeconds);

        return $this->open;
    }
}
