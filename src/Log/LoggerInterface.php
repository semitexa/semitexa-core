<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

/**
 * Logger interface for Semitexa.
 * All implementations should support JSON output and async writing when running under Swoole.
 */
interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;
}
