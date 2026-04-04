<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

use Semitexa\Core\Support\CoroutineLocal;

final class ErrorPageContextStore
{
    private const CTX_KEY = '__error_page_context_stack';

    public static function push(ErrorPageContext $context): void
    {
        /** @var list<ErrorPageContext> $stack */
        $stack = CoroutineLocal::get(self::CTX_KEY) ?? [];
        $stack[] = $context;
        CoroutineLocal::set(self::CTX_KEY, $stack);
    }

    public static function current(): ?ErrorPageContext
    {
        /** @var list<ErrorPageContext> $stack */
        $stack = CoroutineLocal::get(self::CTX_KEY) ?? [];

        if ($stack === []) {
            return null;
        }

        return $stack[array_key_last($stack)];
    }

    public static function pop(): void
    {
        /** @var list<ErrorPageContext> $stack */
        $stack = CoroutineLocal::get(self::CTX_KEY) ?? [];

        if ($stack === []) {
            return;
        }

        array_pop($stack);
        if ($stack === []) {
            CoroutineLocal::remove(self::CTX_KEY);
            return;
        }

        CoroutineLocal::set(self::CTX_KEY, $stack);
    }
}
