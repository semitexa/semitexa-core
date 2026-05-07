<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6d: thrown when the live container produces something for the
 * configured resolver class FQCN but the value does not implement
 * `RelationResolverInterface`.
 *
 * Server configuration failure (HTTP 500). `lint:resources` would
 * normally catch this at static-analysis time; this exception is the
 * runtime safety net for cases where the container hands back the
 * wrong type (e.g. an aliased binding misconfigured at boot).
 */
final class InvalidResourceResolverException extends DomainException
{
    public function __construct(
        public readonly string $resolverClass,
        public readonly string $relationName,
        public readonly string $actualClass,
    ) {
        parent::__construct(sprintf(
            'Resolver class %s (declared on relation "%s") was resolved to an instance of %s, '
                . 'which does not implement Semitexa\\Core\\Resource\\RelationResolverInterface.',
            $resolverClass,
            $relationName,
            $actualClass,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::InternalServerError;
    }

    public function getErrorContext(): array
    {
        return [
            'resolver_class' => $this->resolverClass,
            'relation'       => $this->relationName,
            'actual_class'   => $this->actualClass,
        ];
    }
}
