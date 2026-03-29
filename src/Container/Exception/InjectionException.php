<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Exception;

final class InjectionException extends \RuntimeException
{
    public function __construct(
        public readonly string $targetClass,
        public readonly string $propertyName,
        public readonly string $propertyType,
        public readonly string $injectionKind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
