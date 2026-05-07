<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use RuntimeException;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Phase 6c: static-only validator for `#[HandlerProvidesResourceIncludes]`
 * declarations. Run from `lint:resources` after the Resource metadata
 * registry has been populated.
 *
 * Rules per declaration:
 *
 *   1. The declared `resource` class must be a registered Resource
 *      DTO (`#[ResourceObject]`).
 *   2. Every declared token (top-level or dot-notation) must walk the
 *      metadata graph:
 *        - each segment exists as a field on the current metadata,
 *        - each segment is a relation (RefOne / RefMany / Union),
 *        - each segment is `expandable=true`.
 *   3. Polymorphic-union targets follow the existing Phase 5c
 *      conservative rule: nested validation walks the first declared
 *      union target.
 *
 * Surface: a list of human-readable error strings. Empty list = valid.
 *
 * This validator never instantiates the payload, never resolves any
 * service, never invokes a resolver. It is pure metadata.
 */
#[AsService]
final class HandlerProvidedIncludeValidator
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $v = new self();
        $v->registry = $registry;
        return $v;
    }

    /**
     * @return list<string> human-readable error messages
     */
    public function validate(HandlerProvidedIncludeRegistry $declarations): array
    {
        $declarations->ensureBuilt();
        $errors = [];

        foreach ($declarations->all() as $payloadClass => $tokens) {
            $resourceClass = $declarations->resourceFor($payloadClass);
            if ($resourceClass === null) {
                continue; // structurally impossible — registry pairs them.
            }

            if (!$this->registry->has($resourceClass)) {
                $errors[] = sprintf(
                    'Payload %s declares #[HandlerProvidesResourceIncludes(resource: %s)] '
                        . 'but %s is not a registered Resource (#[ResourceObject] missing).',
                    $payloadClass,
                    $resourceClass,
                    $resourceClass,
                );
                continue; // can't walk an unknown root.
            }

            $rootMetadata = $this->registry->require($resourceClass);

            foreach ($tokens as $token) {
                try {
                    $this->validateToken($payloadClass, $resourceClass, $token, $rootMetadata);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return $errors;
    }

    /** @param class-string $payloadClass */
    /** @param class-string $resourceClass */
    private function validateToken(
        string $payloadClass,
        string $resourceClass,
        string $token,
        ResourceObjectMetadata $rootMetadata,
    ): void {
        $segments = explode('.', $token);
        $current  = $rootMetadata;

        foreach ($segments as $i => $segment) {
            $field = $current->getField($segment);
            if ($field === null) {
                throw new RuntimeException(sprintf(
                    'Payload %s declares handler-provided include token "%s" but '
                        . 'segment "%s" does not exist on resource "%s".',
                    $payloadClass,
                    $token,
                    $segment,
                    $current->type,
                ));
            }

            if (!$field->isRelation()) {
                throw new RuntimeException(sprintf(
                    'Payload %s declares handler-provided include token "%s" but '
                        . 'segment "%s" on resource "%s" is %s, not a relation.',
                    $payloadClass,
                    $token,
                    $segment,
                    $current->type,
                    $field->kind->value,
                ));
            }

            if (!$field->expandable) {
                throw new RuntimeException(sprintf(
                    'Payload %s declares handler-provided include token "%s" but '
                        . 'segment "%s" on resource "%s" is not expandable.',
                    $payloadClass,
                    $token,
                    $segment,
                    $current->type,
                ));
            }

            if ($i === count($segments) - 1) {
                return;
            }

            $next = $this->resolveTargetMetadata($field);
            if ($next === null) {
                throw new RuntimeException(sprintf(
                    'Payload %s declares handler-provided include token "%s" but '
                        . 'segment "%s" on resource "%s" has no resolvable target metadata to walk into.',
                    $payloadClass,
                    $token,
                    $segment,
                    $current->type,
                ));
            }

            $current = $next;
        }
    }

    private function resolveTargetMetadata(ResourceFieldMetadata $field): ?ResourceObjectMetadata
    {
        if ($field->target !== null) {
            return $this->registry->get($field->target);
        }

        if ($field->unionTargets !== null && $field->unionTargets !== []) {
            // Phase 5c parity: walk the first declared union target.
            return $this->registry->get($field->unionTargets[0]);
        }

        return null;
    }
}
