<?php

declare(strict_types=1);

namespace Semitexa\Core\Util;

class ProjectRoot
{
    private static ?string $root = null;

    public static function get(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        // 0. Explicit root (set in server.php so Swoole workers see it after fork)
        if (defined('SEMITEXA_PROJECT_ROOT') && SEMITEXA_PROJECT_ROOT !== '' && is_dir(SEMITEXA_PROJECT_ROOT)) {
            self::$root = rtrim(SEMITEXA_PROJECT_ROOT, '/\\');
            return self::$root;
        }

        // 1. Try known candidates (Docker, CWD)
        $candidates = ['/var/www/html'];
        $cwd = getcwd();
        if ($cwd !== false && $cwd !== '') {
            $candidates[] = $cwd;
        }
        foreach ($candidates as $dir) {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                self::$root = $dir;
                return self::$root;
            }
        }

        // 2. Walk up from this file until composer.json + src/modules found
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                self::$root = $dir;
                return self::$root;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // 3. Fallback: 4 levels up from Util/
        self::$root = dirname(__DIR__, 4);
        return self::$root;
    }

    public static function reset(): void
    {
        self::$root = null;
    }
}
