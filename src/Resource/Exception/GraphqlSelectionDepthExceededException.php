<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5c: thrown when a client GraphQL query nests selection sets
 * deeper than the bounded bridge supports. Default cap is depth 1
 * (mirrors `IncludeTokenCollector::MAX_DEPTH`). Maps to HTTP 400.
 */
final class GraphqlSelectionDepthExceededException extends DomainException
{
    public function __construct(
        private readonly int $maxDepth,
        private readonly string $offendingPath,
    ) {
        parent::__construct(sprintf(
            'GraphQL selection nesting too deep at "%s". The bounded bridge supports at most %d level(s) of relation nesting.',
            $offendingPath,
            $maxDepth,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'max_depth'      => $this->maxDepth,
            'offending_path' => $this->offendingPath,
        ];
    }
}
