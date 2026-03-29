<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Exception;

final class ContainerBuildException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
