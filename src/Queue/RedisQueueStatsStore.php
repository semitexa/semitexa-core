<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Predis\Client;
use Predis\ClientContextInterface;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Counters in Redis hashes, one per queue and minute
 * (`semitexa:qstats:<queue>:<minute>`), kept a week. HINCRBY is atomic, so
 * workers in any container add to the same numbers.
 */
final class RedisQueueStatsStore implements QueueStatsStoreInterface
{
    public const PREFIX = 'semitexa:qstats:';
    private const TTL_SECONDS = 7 * 86_400;

    public function __construct(private readonly RedisConnectionPool $pool) {}

    public function add(string $queue, int $minute, array $deltas): void
    {
        if ($deltas === []) {
            return;
        }
        $key = self::PREFIX . $queue . ':' . $minute;
        $this->pool->withConnection(static function ($redis) use ($key, $deltas): void {
            /** @var Client $redis */
            // One round trip for the whole job.
            $redis->pipeline(static function (ClientContextInterface $pipe) use ($key, $deltas): void {
                foreach ($deltas as $field => $amount) {
                    if ($amount !== 0) {
                        $pipe->hincrby($key, $field, $amount);
                    }
                }
                $pipe->expire($key, self::TTL_SECONDS);
            });
        });
    }

    public function sum(string $queue, int $fromMinute, int $toMinute): array
    {
        $total = [];
        $this->pool->withConnection(static function ($redis) use ($queue, $fromMinute, $toMinute, &$total): void {
            /** @var Client $redis */
            // One round trip for the window, not one per minute.
            $replies = $redis->pipeline(static function (ClientContextInterface $pipe) use ($queue, $fromMinute, $toMinute): void {
                for ($minute = $fromMinute; $minute <= $toMinute; $minute++) {
                    $pipe->hgetall(self::PREFIX . $queue . ':' . $minute);
                }
            });
            foreach ((array) $replies as $hash) {
                foreach ((array) $hash as $field => $value) {
                    $total[(string) $field] = ($total[(string) $field] ?? 0) + (is_numeric($value) ? (int) $value : 0);
                }
            }
        });

        return $total;
    }

    public function describe(): string
    {
        return 'redis';
    }
}
