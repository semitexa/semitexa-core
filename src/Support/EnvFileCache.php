<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

use Semitexa\Core\Attribute\WorkerState;

/**
 * The parsed .env.default/.env values, once per worker.
 *
 * Lives here rather than in {@see \Semitexa\Core\Environment} because that is a
 * `readonly class`, which cannot declare a static property — and a `static $x`
 * local cannot carry the lifetime declaration every worker-wide static needs.
 *
 * @internal Used by Environment::getEnvValue().
 */
final class EnvFileCache
{
    /** @var array<string, string>|null */
    #[WorkerState('Values parsed from the project env files; the files do not change under a worker.')]
    private static ?array $values = null;

    /**
     * @param callable(): array<string, string> $load Parses the env files on first use.
     * @return array<string, string>
     */
    public static function values(callable $load): array
    {
        return self::$values ??= $load();
    }
}
