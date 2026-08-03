<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * {@see AttributeSchema} for routable payloads — the `#[AsPublicPayload]` /
 * `#[AsProtectedPayload]` / `#[AsServicePayload]` family.
 *
 * Two of these keys are load-bearing enough that a missing value is a boot
 * failure rather than a default: `path`, because a payload with no path is not
 * a route at all, and `accessType`, because defaulting it either way would mean
 * guessing whether an endpoint is authenticated. Everything else has a safe
 * default — notably `transport`, which falls back to plain HTTP.
 *
 * @phpstan-type AttrMap array<string, mixed>
 */
final class PayloadAttributeSchema implements AttributeSchema
{
    public function subject(): string
    {
        return 'Request';
    }

    /**
     * @return list<string>
     */
    public function mergeableKeys(): array
    {
        return [
            'path',
            'methods',
            'name',
            'requirements',
            'defaults',
            'options',
            'tags',
            'accessType',
            'responseWith',
            'consumes',
            'produces',
            'transport',
            'sseGateModel',
            'renderProfile',
            'responsesByProfile',
        ];
    }

    /**
     * @param  AttrMap $attr
     * @return AttrMap
     */
    public function applyDefaults(array $attr, string $shortName, string $className): array
    {
        if ($attr['path'] === null) {
            throw new ConfigurationException("Request {$className} must define a path");
        }

        return [
            'path' => $attr['path'],
            'methods' => $attr['methods'] ?? ['GET'],
            'name' => $attr['name'] ?? $shortName,
            'requirements' => $attr['requirements'] ?? [],
            'defaults' => $attr['defaults'] ?? [],
            'options' => $attr['options'] ?? [],
            'tags' => $attr['tags'] ?? [],
            'accessType' => $attr['accessType']
                ?? throw new ConfigurationException("Request {$className} must declare an access attribute (#[AsPublicPayload], #[AsProtectedPayload], or #[AsServicePayload])."),
            'responseWith' => $attr['responseWith'],
            'consumes' => $attr['consumes'] ?? null,
            'produces' => $attr['produces'] ?? null,
            'transport' => $attr['transport'] ?? TransportType::Http,
            // SSE gate-model axis — passed through unchanged (null when unset).
            // The boot guard (assertSseGateCoherence) reads it off the resolved route.
            'sseGateModel' => $attr['sseGateModel'] ?? null,
            // Forwarded as-is from the source attribute. null when
            // unset (single-profile / no negotiation).
            'renderProfile' => $attr['renderProfile'] ?? null,
            'responsesByProfile' => $attr['responsesByProfile'] ?? null,
        ];
    }
}
