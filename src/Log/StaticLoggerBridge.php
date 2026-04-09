<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

use Semitexa\Core\Container\ContainerFactory;

final class StaticLoggerBridge
{
    private static ?LoggerInterface $logger = null;

    public static function set(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function reset(): void
    {
        self::$logger = null;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        $logger = self::resolveLogger();
        $context = self::withChannelContext($channel, $context);

        if ($logger !== null) {
            $logger->error($message, $context);
            return;
        }

        FallbackErrorLogger::log(sprintf('[%s] %s', strtoupper($channel), $message), $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        $logger = self::resolveLogger();
        $context = self::withChannelContext($channel, $context);

        if ($logger !== null) {
            $logger->warning($message, $context);
            return;
        }

        FallbackErrorLogger::log(sprintf('[%s] %s', strtoupper($channel), $message), $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public static function debug(string $channel, string $message, array $context = []): void
    {
        $logger = self::resolveLogger();
        if ($logger === null) {
            return;
        }

        $logger->debug($message, self::withChannelContext($channel, $context));
    }

    private static function resolveLogger(): ?LoggerInterface
    {
        if (self::$logger !== null) {
            return self::$logger;
        }

        try {
            $logger = ContainerFactory::get()->getOrNull(LoggerInterface::class);
        } catch (\Throwable) {
            return null;
        }

        return $logger instanceof LoggerInterface ? $logger : null;
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private static function withChannelContext(string $channel, array $context): array
    {
        if (!array_key_exists('_channel', $context)) {
            $context['_channel'] = $channel;
        }

        return $context;
    }
}
