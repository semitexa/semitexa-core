<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

final class ErrorPageContextStore
{
    /** @var array<int|string, list<ErrorPageContext>> */
    private static array $stackByRequest = [];

    public static function push(ErrorPageContext $context): void
    {
        $key = self::requestKey();
        self::$stackByRequest[$key] ??= [];
        self::$stackByRequest[$key][] = $context;
    }

    public static function current(): ?ErrorPageContext
    {
        $key = self::requestKey();
        $stack = self::$stackByRequest[$key] ?? [];

        if ($stack === []) {
            return null;
        }

        return $stack[array_key_last($stack)] ?? null;
    }

    public static function pop(): void
    {
        $key = self::requestKey();
        if (!isset(self::$stackByRequest[$key])) {
            return;
        }

        array_pop(self::$stackByRequest[$key]);
        if (self::$stackByRequest[$key] === []) {
            unset(self::$stackByRequest[$key]);
        }
    }

    /**
     * Key stacks by coroutine when available; CLI/tests fall back to one process-local stack.
     */
    private static function requestKey(): int|string
    {
        if (class_exists(\Swoole\Coroutine::class)) {
            try {
                $cid = \Swoole\Coroutine::getCid();
                if ($cid >= 0) {
                    return $cid;
                }
            } catch (\Throwable) {
            }
        }

        return 'default';
    }
}
