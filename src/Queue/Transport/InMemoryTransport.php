<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue\Transport;

use Semitexa\Core\Support\StandingCoroutines;
use Semitexa\Core\Queue\QueueTransportInterface;

/**
 * In-memory queue transport for development and testing.
 *
 * Queue data is instance-scoped (not static) to prevent cross-coroutine leaks
 * under Swoole. Each transport instance owns its own queues.
 */
class InMemoryTransport implements QueueTransportInterface
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $queues = [];

    public function publish(string $queueName, string $payload): void
    {
        $queueName = $this->normalizeQueue($queueName);
        $this->queues[$queueName][] = $payload;
    }

    public function consume(string $queueName, callable $callback): void
    {
        $queueName = $this->normalizeQueue($queueName);

        StandingCoroutines::declare(
            'queue consumer',
            'waiting for work on queue ' . $queueName . ' — by design, never returns',
        );

        while (true) {
            if (!empty($this->queues[$queueName])) {
                $payload = array_shift($this->queues[$queueName]);
                // Not standing while the handler runs: the label describes the
                // WAIT, and leaving it up would report a stuck handler as
                // waiting by design.
                StandingCoroutines::busy(static fn () => $callback($payload));
            } else {
                if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
                    \Swoole\Coroutine::sleep(0.25);
                } else {
                    usleep(250000);
                }
            }
        }
    }

    private function normalizeQueue(string $queue): string
    {
        return strtolower($queue ?: 'default');
    }
}
