<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Opt-in contract for payload-level cross-field validation.
 *
 * Setter-time validation (via the *ValidationTrait family) runs as each value
 * is hydrated. That is enough for per-field rules — required, format, range,
 * enum membership, individual file-name safety. Anything that depends on
 * *combinations* of fields (for example "if file_content is provided then
 * file_name and file_mime are required", or "the discount must not exceed
 * the subtotal") needs a hook that fires after every setter has run.
 *
 * Payloads that need that hook implement this interface. RouteExecutor calls
 * {@see validate} once after PayloadHydrator has finished, before the route's
 * handlers run, and converts a non-empty error map into the same 422
 * `{ errors: { field: [messages] } }` envelope that setter-time
 * ValidationException produces. Payloads that do not implement the
 * interface keep the existing fast-path with zero overhead.
 *
 * Returning the empty array signals "no cross-field issues" — that is the
 * preferred way to say "valid"; throwing ValidationException from validate()
 * works too and ends up in the same 422 envelope.
 */
interface ValidatablePayloadInterface
{
    /**
     * Run cross-field validation. Returns a map of field name → list of error
     * messages. The empty map means the payload is valid.
     *
     * @return array<string, list<string>>
     */
    public function validate(): array;
}
