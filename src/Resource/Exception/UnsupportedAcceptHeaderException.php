<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Resource\RenderProfile;

/**
 * Phase 3e: thrown when the request `Accept` header does not map to any of
 * the route's declared render profiles. Surfaces as HTTP 406 Not Acceptable.
 *
 * The error context lists the requested media type(s) and the supported
 * profiles so client developers can fix their headers without reading the
 * server logs.
 */
final class UnsupportedAcceptHeaderException extends DomainException
{
    /**
     * @param list<RenderProfile> $supportedProfiles
     * @param list<string>        $supportedMediaTypes
     */
    public function __construct(
        private readonly string $requestedAccept,
        private readonly array $supportedProfiles,
        private readonly array $supportedMediaTypes,
        private readonly ?string $route = null,
    ) {
        parent::__construct(sprintf(
            'No supported render profile for Accept "%s" on %s. Supported media types: %s.',
            $requestedAccept !== '' ? $requestedAccept : '<empty>',
            $route ?? 'this route',
            $supportedMediaTypes === [] ? '<none>' : implode(', ', $supportedMediaTypes),
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::NotAcceptable;
    }

    public function getErrorContext(): array
    {
        return [
            'requested_accept'      => $this->requestedAccept,
            'supported_profiles'    => array_map(static fn (RenderProfile $p): string => $p->value, $this->supportedProfiles),
            'supported_media_types' => $this->supportedMediaTypes,
            'route'                 => $this->route,
        ];
    }
}
