<?php

declare(strict_types=1);

namespace Semitexa\Core\Debug;

/**
 * Writes one JSON line per call to var/log/session-debug.log for debugging session/cookie flow.
 * Remove or disable after fixing. Path: getcwd()/var/log/session-debug.log
 */
final class SessionDebugLog
{
    private const LOG_NAME = 'session-debug.log';

    public static function log(string $place, array $data): void
    {
        $dir = getcwd() ? (getcwd() . '/var/log') : null;
        if ($dir === null || !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => date('Y-m-d H:i:s'),
            'place' => $place,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($dir . '/' . self::LOG_NAME, $line, FILE_APPEND | LOCK_EX);
    }
}
