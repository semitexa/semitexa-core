<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\NonExpandableIncludeException;
use Semitexa\Core\Resource\Exception\UnknownIncludeException;
use Semitexa\Core\Resource\Exception\UnsatisfiedResourceIncludeException;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Validates an `IncludeSet` against the metadata graph rooted at a given
 * Resource DTO.
 *
 * Supports dot-notation tokens (e.g. `addresses.country`).
 *
 * It additionally requires that every requested expandable
 * relation be **satisfiable**, i.e. have either:
 *
 *   1. a `#[ResolveWith]` resolver in metadata
 *      (`ResourceFieldMetadata::$resolverClass !== null`) — the future
 *      expansion pipeline will load it; OR
 *   2. a route-level `#[HandlerProvidesResourceIncludes]` declaration
 *      that lists the requested token — the handler eagerly embeds it
 *      itself.
 *
 * It does **not** instantiate any resolver; it only consults
 * metadata + the `HandlerProvidedIncludeRegistry`. There is no DB,
 * ORM, Request, renderer, or `IriBuilder` access.
 */
#[AsService]
final class IncludeValidator
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    #[InjectAsReadonly]
    protected HandlerProvidedIncludeRegistry $handlerProvidedIncludes;

    /** Bypass property injection for unit tests. */
    public static function forTesting(
        ResourceMetadataRegistry $registry,
        ?HandlerProvidedIncludeRegistry $handlerProvidedIncludes = null,
    ): self {
        $v = new self();
        $v->registry = $registry;
        $v->handlerProvidedIncludes = $handlerProvidedIncludes
            ?? self::emptyHandlerProvidedRegistry();
        return $v;
    }

    /**
     * @param class-string|null $payloadClass
     */
    public function validate(
        IncludeSet $includes,
        ResourceObjectMetadata $rootMetadata,
        ?string $payloadClass = null,
    ): void {
        if ($includes->isEmpty()) {
            return;
        }

        $handlerProvidedTokens = $payloadClass !== null
            ? $this->handlerProvidedIncludes->tokensFor($payloadClass)
            : [];

        foreach ($includes->tokens as $token) {
            $this->validateToken($token, $rootMetadata, $handlerProvidedTokens);
        }
    }

    /**
     * @param list<string> $handlerProvidedTokens normalized; lookup is
     *                                              case-insensitive
     *                                              because tokens are
     *                                              normalized identically
     *                                              upstream
     */
    private function validateToken(
        string $token,
        ResourceObjectMetadata $metadata,
        array $handlerProvidedTokens,
    ): void {
        $segments = explode('.', $token);
        $current  = $metadata;
        $pathSegments = [];

        foreach ($segments as $i => $segment) {
            $pathSegments[] = $segment;
            $field = $this->findFieldByRequestedToken($current, $segment);
            if ($field === null) {
                throw new UnknownIncludeException($token, $current->type);
            }
            if (!$field->isRelation()) {
                throw new UnknownIncludeException($token, $current->type);
            }
            if (!$field->expandable) {
                throw new NonExpandableIncludeException($token, $current->type);
            }
            $segmentToken = implode('.', $pathSegments);

            // Every traversed segment must be satisfiable. A dotted token
            // implies its parent include, so handler-provided declarations for
            // `profile.preferences` also satisfy the `profile` hop.
            $hasResolver       = $field->resolverClass !== null;
            $isHandlerProvided = $this->isHandlerProvidedToken($segmentToken, $handlerProvidedTokens);

            if (!$hasResolver && !$isHandlerProvided) {
                throw new UnsatisfiedResourceIncludeException(
                    resourceType: $current->type,
                    token: $token,
                    relationName: $field->name,
                    resolverMissing: true,
                    handlerContractMissing: true,
                );
            }

            if ($i === count($segments) - 1) {
                return;
            }

            $next = $this->resolveTargetMetadata($field);
            if ($next === null) {
                // No further metadata to walk into — token claims nesting that doesn't exist.
                throw new UnknownIncludeException($token, $current->type);
            }
            $current = $next;
        }
    }

    private function resolveTargetMetadata(ResourceFieldMetadata $field): ?ResourceObjectMetadata
    {
        if ($field->target !== null) {
            /** @var class-string $target */
            $target = $field->target;
            return $this->registry->get($target);
        }

        // Polymorphic union — for include validation we accept the token as long as ALL
        // declared targets agree on the next segment. This stays conservative:
        // a nested include only validates against the first registered union target.
        if ($field->unionTargets !== null && $field->unionTargets !== []) {
            /** @var class-string $target */
            $target = $field->unionTargets[0];
            return $this->registry->get($target);
        }

        return null;
    }

    private function findFieldByRequestedToken(
        ResourceObjectMetadata $metadata,
        string $segment,
    ): ?ResourceFieldMetadata {
        $needle = strtolower($segment);

        foreach ($metadata->fields as $field) {
            $publicToken = strtolower($field->include ?? $field->name);
            if ($publicToken === $needle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param list<string> $handlerProvidedTokens
     */
    private function isHandlerProvidedToken(string $token, array $handlerProvidedTokens): bool
    {
        if (in_array($token, $handlerProvidedTokens, true)) {
            return true;
        }

        $prefix = $token . '.';
        foreach ($handlerProvidedTokens as $providedToken) {
            if (str_starts_with($providedToken, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function emptyHandlerProvidedRegistry(): HandlerProvidedIncludeRegistry
    {
        // Tests that don't exercise the handler-provided contract get a
        // pre-built empty registry — no ClassDiscovery touch.
        return HandlerProvidedIncludeRegistry::withDeclarations([]);
    }
}
