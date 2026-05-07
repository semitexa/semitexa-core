<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6l: thrown when the `?cursor=` query parameter fails
 * decoding, schema validation, or context binding. Covers:
 *
 *   - malformed base64url body,
 *   - malformed JSON inside the decoded body,
 *   - missing or wrong-typed required fields,
 *   - unsupported `version` value,
 *   - sort signature mismatch (cursor was generated for a different
 *     `?sort=` than the current request),
 *   - filter signature mismatch (cursor was generated for a
 *     different `?filter=` than the current request),
 *   - simultaneous `?cursor=` and `?page=` (mutually exclusive).
 *
 * Maps to HTTP 400. The cursor is opaque to clients; the error
 * envelope's `reason` carries enough context to surface the cause
 * without leaking the encoded sort key values.
 */
final class InvalidCursorException extends DomainException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Invalid cursor parameter: %s.',
            $reason,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'parameter' => 'cursor',
            'reason'    => $this->reason,
        ];
    }
}
