<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

class ProjectRoot
{
    private static ?string $root = null;

    public static function get(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        // 0. Explicit root (set in server.php so Swoole workers see it after fork)
        if (defined('SEMITEXA_PROJECT_ROOT') && is_string(SEMITEXA_PROJECT_ROOT) && SEMITEXA_PROJECT_ROOT !== '' && is_dir(SEMITEXA_PROJECT_ROOT)) {
            self::$root = rtrim(SEMITEXA_PROJECT_ROOT, '/\\');
            return self::$root;
        }

        // 1. Try known candidates. CWD is checked before /var/www/html so tests
        //    can chdir() into a throwaway fixture root + ProjectRoot::reset()
        //    to isolate state. Production hosts set SEMITEXA_PROJECT_ROOT
        //    explicitly (see step 0), so this ordering only affects CLI/tests.
        $candidates = [];
        $cwd = getcwd();
        if (is_string($cwd)) {
            $candidates[] = $cwd;
        }
        $candidates[] = '/var/www/html';
        foreach ($candidates as $dir) {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                self::$root = $dir;
                return self::$root;
            }
        }

        // 2. Walk up from this file until composer.json + src/modules found
        $dir = __DIR__;
        while ($dir !== '/') {
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

        // 3. Fallback: 4 levels up from Support/
        self::$root = dirname(__DIR__, 4);
        return self::$root;
    }

    public static function reset(): void
    {
        self::$root = null;
    }
}
