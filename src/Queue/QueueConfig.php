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
