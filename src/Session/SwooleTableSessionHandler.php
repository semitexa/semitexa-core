<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * Reads/writes session data to Swoole Table. Used by Session.
 */
final class SwooleTableSessionHandler
{
    private const COLUMN_DATA = 'data';
    private const COLUMN_EXPIRES = 'expires_at';
    private const DEFAULT_LIFETIME = 3600; // 1 hour

    public function read(string $sessionId): array
    {
        $table = SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return [];
        }

        $row = $table->get($sessionId);
        if ($row === false) {
            return [];
        }

        $expires = (int) ($row[self::COLUMN_EXPIRES] ?? 0);
        if ($expires > 0 && $expires < time()) {
            $table->delete($sessionId);
            return [];
        }

        $raw = $row[self::COLUMN_DATA] ?? '';
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = self::DEFAULT_LIFETIME): void
    {
        $table = SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return;
        }

        $expires = $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0;
        $raw = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $table->set($sessionId, [
            self::COLUMN_DATA => $raw,
            self::COLUMN_EXPIRES => $expires,
        ]);
    }

    public function destroy(string $sessionId): void
    {
        $table = SwooleSessionTableHolder::getTable();
        if ($table !== null) {
            $table->delete($sessionId);
        }
    }
}
