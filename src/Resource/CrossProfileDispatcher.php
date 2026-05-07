<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\UnsupportedAcceptHeaderException;

/**
 * Phase 3e: pick the response class for a multi-profile route based on the
 * request's Accept header.
 *
 * Inputs are intentionally narrow:
 *   - the route's declared profile list (`array<RenderProfile>`)
 *   - the route's profile→class map (`array<string profileValue, class-string>`)
 *   - the raw Accept header
 *
 * No DB, no ORM, no HTTP, no Request object — the caller (RouteExecutor)
 * extracts the header string and hands it in. That keeps the dispatcher
 * a pure function and trivially testable.
 *
 * On unmappable Accept the dispatcher throws `UnsupportedAcceptHeaderException`
 * (HTTP 406). Single-profile routes are not the dispatcher's concern.
 */
#[AsService]
final class CrossProfileDispatcher
{
    #[InjectAsReadonly]
    protected AcceptHeaderResolver $resolver;

    public static function forTesting(AcceptHeaderResolver $resolver): self
    {
        $d = new self();
        $d->resolver = $resolver;
        return $d;
    }

    /**
     * Resolve the response class for the request.
     *
     * @param list<RenderProfile>             $declaredProfiles ordered as declared
     * @param array<string, class-string>     $responsesByProfile profile-value → class
     * @return class-string
     */
    public function resolveResponseClass(
        array $declaredProfiles,
        array $responsesByProfile,
        ?string $acceptHeader,
        ?string $routeContext = null,
    ): string {
        $profile = $this->resolver->resolve($acceptHeader, $declaredProfiles);

        if ($profile === null) {
            throw new UnsupportedAcceptHeaderException(
                requestedAccept:     (string) $acceptHeader,
                supportedProfiles:   $declaredProfiles,
                supportedMediaTypes: AcceptHeaderResolver::mediaTypesForProfiles($declaredProfiles),
                route:               $routeContext,
            );
        }

        $responseClass = $responsesByProfile[$profile->value] ?? null;
        if (!is_string($responseClass) || $responseClass === '' || !class_exists($responseClass)) {
            // Declared profile but no response class wired — treat as a
            // configuration bug surfaced as 406, with a clear context.
            throw new UnsupportedAcceptHeaderException(
                requestedAccept:     (string) $acceptHeader,
                supportedProfiles:   $declaredProfiles,
                supportedMediaTypes: AcceptHeaderResolver::mediaTypesForProfiles($declaredProfiles),
                route:               $routeContext,
            );
        }

        /** @var class-string $responseClass */
        return $responseClass;
    }
}
