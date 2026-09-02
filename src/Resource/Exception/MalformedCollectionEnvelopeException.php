<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

/**
 * Thrown when a payload does not match the collection envelope this framework emits.
 *
 * Deliberately NOT a {@see \Semitexa\Core\Exception\DomainException}, unlike every other
 * exception in this namespace. Those describe a request a client got wrong and map to a
 * 4xx; this one describes a response that does not match its own producer's shape, which
 * is a defect on our side of the wire — usually a test asserting against an envelope the
 * handler never built, or a reader that has drifted from
 * {@see \Semitexa\Core\Resource\JsonResourceResponse}. Mapping it to HTTP 400 would blame
 * the caller for our bug.
 */
final class MalformedCollectionEnvelopeException extends \InvalidArgumentException
{
    public static function missingKey(string $key, string $context): self
    {
        return new self(sprintf('Collection envelope is missing "%s" in %s.', $key, $context));
    }

    public static function wrongType(string $key, string $expected, mixed $actual, string $context): self
    {
        return new self(sprintf(
            'Collection envelope key "%s" in %s must be %s, got %s.',
            $key,
            $context,
            $expected,
            get_debug_type($actual),
        ));
    }

    public static function notDecodable(string $reason): self
    {
        return new self('Collection envelope is not decodable JSON: ' . $reason . '.');
    }
}
