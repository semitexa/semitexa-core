<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6g: thrown when a `?include=` token nests deeper than
 * `ResourceExpansionPipeline::MAX_NESTED_DEPTH`. Aligned with
 * `GraphqlSelectionToIncludeSet::MAX_DEPTH = 1`. A two-segment dotted
 * token (e.g. `profile.preferences`) is permitted; three or more
 * segments are rejected before any resolver runs.
 *
 * Maps to HTTP 400. The client can fix the request by trimming the
 * include path; this is not a server bug.
 */
final class NestedIncludeDepthExceededException extends DomainException
{
    public function __construct(
        public readonly string $token,
        public readonly int $maxDepth,
    ) {
        parent::__construct(sprintf(
            'Nested include token "%s" exceeds the maximum supported depth of %d level(s) beyond the root.',
            $token,
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
            'token'     => $this->token,
            'max_depth' => $this->maxDepth,
        ];
    }
}
