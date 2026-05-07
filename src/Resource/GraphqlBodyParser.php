<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\Exception\InvalidGraphqlQueryException;
use Semitexa\Core\Resource\Exception\MalformedGraphqlRequestBodyException;
use Semitexa\Core\Resource\Exception\MissingGraphqlQueryException;
use Semitexa\Core\Resource\Exception\UnsupportedGraphqlRequestBodyException;

/**
 * Phase 5d: extract a GraphQL query string from a request body.
 *
 * **Transport only.** This class does not parse the GraphQL query — it
 * just lifts the query string out of either:
 *
 *   - `application/json` body of the form `{"query": "<gql>"}`
 *   - `application/graphql` body where the raw body IS the query
 *
 * Returns `null` (not an exception) for requests that have no body or
 * a body the caller should not interpret as a GraphQL query — the
 * handler then falls back to `?query=` / `?include=`.
 *
 * Pure: no DB, no ORM, no HTTP, no renderer / IriBuilder / Request
 * mutation. The Request is read-only here; we only call `getMethod()`,
 * `getHeader()`, `getJsonBody()`, and `getContent()`.
 */
#[AsService]
final class GraphqlBodyParser
{
    /**
     * Extract the GraphQL query string from the request body, or null if
     * the request is not a GraphQL POST with a body the bridge accepts.
     *
     * @throws MalformedGraphqlRequestBodyException
     * @throws MissingGraphqlQueryException
     * @throws InvalidGraphqlQueryException
     * @throws UnsupportedGraphqlRequestBodyException
     */
    public function extract(Request $request): ?string
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }

        $contentTypeHeader = (string) ($request->getHeader('Content-Type') ?? '');
        $contentType       = strtolower(trim(explode(';', $contentTypeHeader, 2)[0]));

        // No Content-Type, no body: nothing to do — caller falls back.
        if ($contentType === '') {
            return null;
        }

        if ($contentType === 'application/json') {
            return $this->fromJsonBody($request, $contentTypeHeader);
        }

        if ($contentType === 'application/graphql') {
            return $this->fromRawBody($request, $contentTypeHeader);
        }

        // POST with a body of an unrecognised Content-Type. The
        // dispatcher already gates on Accept, so we know this request
        // wants GraphQL; an unknown body Content-Type is a client error.
        throw new UnsupportedGraphqlRequestBodyException(
            reason: 'expected application/json or application/graphql',
            contentType: $contentTypeHeader,
        );
    }

    private function fromJsonBody(Request $request, string $contentTypeHeader): string
    {
        $raw = (string) ($request->getContent() ?? '');
        if (trim($raw) === '') {
            throw new MalformedGraphqlRequestBodyException(
                reason: 'empty JSON body',
                contentType: $contentTypeHeader,
            );
        }

        // Decode independently of getJsonBody() so we can distinguish
        // "malformed JSON" from "JSON with non-object root" from
        // "Content-Type not application/json" cleanly.
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MalformedGraphqlRequestBodyException(
                reason: 'json_decode failed: ' . json_last_error_msg(),
                contentType: $contentTypeHeader,
            );
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            // Reject scalars AND JSON-array roots. `array_is_list($x)`
            // distinguishes JSON arrays (`[1,2,3]` → PHP list) from JSON
            // objects (PHP assoc array). Empty `[]` is accepted by
            // `array_is_list` as a list, but `{}` (empty object) decodes
            // to `[]` too — `[] !== array_is_list($decoded)` lets the
            // empty-object case fall through to the missing-`query`
            // branch with a clearer error.
            throw new MalformedGraphqlRequestBodyException(
                reason: 'JSON body must decode to an object',
                contentType: $contentTypeHeader,
            );
        }

        if (!array_key_exists('query', $decoded)) {
            throw new MissingGraphqlQueryException(contentType: $contentTypeHeader);
        }

        // `variables` field — if present and non-empty, reject. Phase 5c
        // explicitly rejects the GraphQL variable feature in the parser;
        // accepting them here would mislead clients into thinking they're
        // honoured.
        if (array_key_exists('variables', $decoded) && $this->isNonEmpty($decoded['variables'])) {
            throw new UnsupportedGraphqlRequestBodyException(
                reason: '"variables" is not supported by the bounded GraphQL bridge',
                contentType: $contentTypeHeader,
            );
        }

        // `operationName` is silently ignored. The bounded bridge
        // accepts a single root field; the operation name has no
        // semantic effect since the parser already handles named
        // queries by skipping the name.

        $query = $decoded['query'];
        if ($query === null) {
            throw new InvalidGraphqlQueryException(
                reason: '"query" must not be null',
                actualType: 'null',
            );
        }
        if (!is_string($query)) {
            throw new InvalidGraphqlQueryException(
                reason: '"query" must be a string',
                actualType: gettype($query),
            );
        }
        if (trim($query) === '') {
            throw new InvalidGraphqlQueryException(reason: '"query" must not be empty');
        }

        return $query;
    }

    private function fromRawBody(Request $request, string $contentTypeHeader): string
    {
        $raw = (string) ($request->getContent() ?? '');
        if (trim($raw) === '') {
            throw new MalformedGraphqlRequestBodyException(
                reason: 'empty application/graphql body',
                contentType: $contentTypeHeader,
            );
        }
        return $raw;
    }

    private function isNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return true;
    }
}
