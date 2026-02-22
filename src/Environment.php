<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Util\ProjectRoot;

/**
 * Immutable Environment configuration handler
 */
readonly class Environment
{
    public function __construct(
        public string $appEnv,
        public bool $appDebug,
        public string $appName,
        public string $appHost,
        public int $appPort,
        public int $swoolePort,
        public string $swooleHost,
        public int $swooleWorkerNum,
        public int $swooleMaxRequest,
        public int $swooleMaxCoroutine,
        public string $swooleLogFile,
        public int $swooleLogLevel,
        public string $corsAllowOrigin,
        public string $corsAllowMethods,
        public string $corsAllowHeaders,
        public bool $corsAllowCredentials,
    ) {}
    
    public static function create(): self
    {
        $fileEnv = self::loadEnv();
        
        $get = fn(string $key, $default = null) => 
            getenv($key) !== false ? getenv($key) : (
                $_ENV[$key] ?? (
                    $_SERVER[$key] ?? (
                        $fileEnv[$key] ?? $default
                    )
                )
            );
        
        return new self(
            appEnv: $get('APP_ENV', 'prod'),
            appDebug: (bool) $get('APP_DEBUG', '0'),
            appName: $get('APP_NAME', 'Semitexa Framework'),
            appHost: $get('APP_HOST', 'localhost'),
            appPort: (int) $get('APP_PORT', '8000'),
            swoolePort: (int) $get('SWOOLE_PORT', '9501'),
            swooleHost: $get('SWOOLE_HOST', '0.0.0.0'),
            swooleWorkerNum: (int) $get('SWOOLE_WORKER_NUM', '4'),
            swooleMaxRequest: (int) $get('SWOOLE_MAX_REQUEST', '10000'),
            swooleMaxCoroutine: (int) $get('SWOOLE_MAX_COROUTINE', '100000'),
            swooleLogFile: $get('SWOOLE_LOG_FILE', 'var/log/swoole.log'),
            swooleLogLevel: (int) $get('SWOOLE_LOG_LEVEL', '1'),
            corsAllowOrigin: $get('CORS_ALLOW_ORIGIN', '*'),
            corsAllowMethods: $get('CORS_ALLOW_METHODS', 'GET, POST, PUT, DELETE, OPTIONS'),
            corsAllowHeaders: $get('CORS_ALLOW_HEADERS', 'Content-Type, Authorization'),
            corsAllowCredentials: (bool) $get('CORS_ALLOW_CREDENTIALS', '0'),
        );
    }
    
    private static function loadEnv(): array
    {
        $env = [];
        $root = ProjectRoot::get();

        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            $env = array_merge($env, self::parseEnvFile($envFile));
        }

        $envLocalFile = $root . '/.env.local';
        if (is_file($envLocalFile)) {
            $env = array_merge($env, self::parseEnvFile($envLocalFile));
        }

        return $env;
    }
    
    private static function parseEnvFile(string $file): array
    {
        $env = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                continue; // Skip comments
            }
            
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (($value[0] ?? '') === '"' && ($value[-1] ?? '') === '"') {
                    $value = substr($value, 1, -1);
                }
                
                $env[$key] = $value;
            }
        }
        
        return $env;
    }
    
    public function get(string $key, string $default = ''): string
    {
        return match($key) {
            'APP_ENV' => $this->appEnv,
            'APP_DEBUG' => $this->appDebug ? '1' : '0',
            'APP_NAME' => $this->appName,
            'APP_HOST' => $this->appHost,
            'APP_PORT' => (string) $this->appPort,
            'SWOOLE_PORT' => (string) $this->swoolePort,
            'SWOOLE_HOST' => $this->swooleHost,
            'SWOOLE_WORKER_NUM' => (string) $this->swooleWorkerNum,
            'SWOOLE_MAX_REQUEST' => (string) $this->swooleMaxRequest,
            'SWOOLE_MAX_COROUTINE' => (string) $this->swooleMaxCoroutine,
            'SWOOLE_LOG_FILE' => $this->swooleLogFile,
            'SWOOLE_LOG_LEVEL' => (string) $this->swooleLogLevel,
            'CORS_ALLOW_ORIGIN' => $this->corsAllowOrigin,
            'CORS_ALLOW_METHODS' => $this->corsAllowMethods,
            'CORS_ALLOW_HEADERS' => $this->corsAllowHeaders,
            'CORS_ALLOW_CREDENTIALS' => $this->corsAllowCredentials ? '1' : '0',
            default => $default
        };
    }
    
    public function isDev(): bool
    {
        return $this->appEnv === 'dev';
    }
    
    public function isDebug(): bool
    {
        return $this->appDebug;
    }
    
    /**
     * Get any environment variable value (not just predefined ones)
     * Checks .env, .env.local, and $_ENV/$_SERVER
     * 
     * @param string $key Environment variable name
     * @param string|null $default Default value if not found
     * @return string|null
     */
    public static function getEnvValue(string $key, ?string $default = null): ?string
    {
        // 1. System Env (getenv)
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        // 2. $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        
        // 3. $_SERVER
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // 4. .env files
        $env = self::loadEnv();
        if (isset($env[$key])) {
            return $env[$key];
        }
        
        return $default;
    }

    /**
     * Load .env and .env.local into getenv() / $_ENV / $_SERVER.
     * Call from Swoole WorkerStart so workers have DB_*, etc. (fork may not inherit env in some setups).
     */
    public static function syncEnvFromFiles(): void
    {
        $env = self::loadEnv();
        foreach ($env as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
