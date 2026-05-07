<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5c: thrown by the bounded GraphQL selection parser when the
 * query string cannot be tokenised or the brace structure is malformed.
 * Maps to HTTP 400.
 */
final class MalformedGraphqlSelectionException extends DomainException
{
    public function __construct(
        private readonly string $reason,
        private readonly int $offset = -1,
        private readonly string $excerpt = '',
    ) {
        parent::__construct(sprintf(
            'Malformed GraphQL selection: %s%s',
            $reason,
            $offset >= 0 ? sprintf(' (at offset %d, near "%s")', $offset, $excerpt) : '',
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'reason'  => $this->reason,
            'offset'  => $this->offset,
            'excerpt' => $this->excerpt,
        ];
    }
}
