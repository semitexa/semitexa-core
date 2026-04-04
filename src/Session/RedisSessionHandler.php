<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Predis\ClientInterface;
use Semitexa\Core\Environment;

/**
 * Reads/writes session data to Redis. Shared across all workers; survives restarts.
 * Used when REDIS_HOST is set in .env. Redis commands reconnect once on failure.
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private const DEFAULT_LIFETIME = 3600;
    private const KEY_PREFIX = 'semitexa_session:';

    private ClientInterface $redis;

    public function __construct(?ClientInterface $redis = null)
    {
        $this->redis = $redis ?? self::createClient();
    }

    public function read(string $sessionId): array
    {
        $key = self::KEY_PREFIX . $sessionId;
        $raw = $this->withReconnect(static fn (ClientInterface $redis): mixed => $redis->get($key));

        if (!is_string($raw) || $raw === '') {
            \Semitexa\Core\Debug\SessionDebugLog::log('RedisSessionHandler.read', [
                'session_id_preview' => substr($sessionId, 0, 8) . '…',
                'found' => false,
            ]);
            return [];
        }

        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];
        $keys = array_keys($data);
        \Semitexa\Core\Debug\SessionDebugLog::log('RedisSessionHandler.read', [
            'session_id_preview' => substr($sessionId, 0, 8) . '…',
            'found' => true,
            'keys' => $keys,
            'has_auth_user_id' => array_key_exists('_auth_user_id', $data),
        ]);
        return $data;
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = self::DEFAULT_LIFETIME): void
    {
        $key = self::KEY_PREFIX . $sessionId;
        $raw = json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($lifetimeSeconds > 0) {
            $this->withReconnect(static fn (ClientInterface $redis): mixed => $redis->setex($key, $lifetimeSeconds, $raw));
        } else {
            $this->withReconnect(static fn (ClientInterface $redis): mixed => $redis->set($key, $raw));
        }

        \Semitexa\Core\Debug\SessionDebugLog::log('RedisSessionHandler.write', [
            'session_id_preview' => substr($sessionId, 0, 8) . '…',
            'keys' => array_keys($data),
            'has_auth_user_id' => array_key_exists('_auth_user_id', $data),
            'ttl' => $lifetimeSeconds,
        ]);
    }

    public function destroy(string $sessionId): void
    {
        $this->withReconnect(static fn (ClientInterface $redis): mixed => $redis->del(self::KEY_PREFIX . $sessionId));
    }

    private static function createClient(): ClientInterface
    {
        $host = Environment::getEnvValue('REDIS_HOST', '127.0.0.1');
        $port = (int) Environment::getEnvValue('REDIS_PORT', '6379');
        $scheme = Environment::getEnvValue('REDIS_SCHEME', 'tcp');
        $password = Environment::getEnvValue('REDIS_PASSWORD');
        $params = [
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
        ];
        if ($password !== null && $password !== '') {
            $params['password'] = $password;
        }
        return new \Predis\Client($params);
    }

    private function withReconnect(callable $operation): mixed
    {
        try {
            return $operation($this->redis);
        } catch (\Throwable) {
            $this->redis = self::createClient();
            return $operation($this->redis);
        }
    }
}
