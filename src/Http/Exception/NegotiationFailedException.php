<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

final class NegotiationFailedException extends \RuntimeException
{
    /**
     * @param list<string> $produces Available response types
     * @param string $requested What the client asked for
     */
    public function __construct(
        public readonly array $produces,
        public readonly string $requested,
    ) {
        parent::__construct(
            "Cannot produce a response matching '{$requested}'. Available: " . implode(', ', $produces)
        );
    }
}
