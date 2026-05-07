<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5c: thrown when the parser encounters a GraphQL feature that
 * Semitexa's bounded selection bridge does not support — fragments,
 * variables, directives, aliases, mutations, subscriptions. Surfaces as
 * HTTP 400 so client developers see the actual reason instead of a
 * generic "malformed query".
 */
final class UnsupportedGraphqlFeatureException extends DomainException
{
    public function __construct(
        private readonly string $feature,
        private readonly string $hint = '',
    ) {
        parent::__construct(sprintf(
            'Unsupported GraphQL feature: %s. Phase 5c is a bounded selection-set bridge, not a full GraphQL executor.%s',
            $feature,
            $hint !== '' ? ' ' . $hint : '',
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'feature' => $this->feature,
            'hint'    => $this->hint,
        ];
    }
}
