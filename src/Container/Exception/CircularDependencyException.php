<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Exception;

final class CircularDependencyException extends \RuntimeException
{
    /**
     * @param list<string> $chain Full cycle path, e.g. ['A', 'B', 'C', 'A']
     */
    public function __construct(
        public readonly array $chain,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
