<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Environment;
use Semitexa\Core\Exception\ConfigurationException;

class QueueConfig
{
    /**
     * When EVENTS_ASYNC=1 (or true/yes): NATS when installed, otherwise the
     * broker-less 'database' transport (semitexa/orm). Without async:
     * in-memory (sync). Override with EVENTS_TRANSPORT=nats|database|in-memory.
     */
    public static function defaultTransport(): string
    {
        $override = Environment::getEnvValue('EVENTS_TRANSPORT');
        if ($override !== null && $override !== '') {
            return $override;
        }

        $async = Environment::getEnvValue('EVENTS_ASYNC', '0') ?? '0';
        if (!self::isAsyncEnabled($async)) {
            return 'in-memory';
        }

        if (QueueTransportRegistry::has('nats')) {
            return 'nats';
        }

        if (QueueTransportRegistry::has('database')) {
            return 'database';
        }

        throw new ConfigurationException(
            'EVENTS_ASYNC=1 but no async queue transport is available. Install semitexa-ledger '
            . '+ NATS for a broker, or semitexa/orm for the broker-less database transport '
            . '(or set EVENTS_ASYNC=0 for in-process sync dispatch).',
        );
    }

    public static function isAsyncEnabled(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    /**
     * A transport whose messages never leave the process that published them:
     * the in-memory one, which is what EVENTS_ASYNC=0 resolves to. No worker
     * can consume it, so a producer runs the work itself instead of
     * publishing it into a queue nobody reads. Decided by the transport the
     * registry hands out, not by its name: a capturing or spying transport
     * registered under that name (the replay sandbox) is published to.
     */
    public static function isProcessLocal(string $transport): bool
    {
        try {
            return QueueTransportRegistry::has($transport)
                && QueueTransportRegistry::create($transport) instanceof \Semitexa\Core\Queue\Transport\InMemoryTransport;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Single queue name by default so one worker can process all async events.
     * Set EVENTS_QUEUE_DEFAULT to override (e.g. per-handler queue).
     */
    public static function defaultQueueName(string $requestClass): string
    {
        $single = Environment::getEnvValue('EVENTS_QUEUE_DEFAULT');
        if ($single !== null && $single !== '') {
            return $single;
        }
        return 'events';
    }
}
