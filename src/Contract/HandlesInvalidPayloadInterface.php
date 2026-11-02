<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * A payload handler that answers an HTML request whose payload failed
 * validation, instead of the JSON 422 every other one gets.
 *
 * Found by the delivery benchmark (bench-html-form-validation-422): a payload's
 * validate() errors were answered before any handler ran, always as JSON, so a
 * form could not be shown again with its errors and the values the visitor
 * typed. The benchmark's baseline gave up on ValidatablePayloadInterface and
 * validated by hand inside handle().
 *
 * When the request prefers HTML and the route's handler implements this, the
 * pipeline runs as for a valid request (every phase, authorization included)
 * and calls invalid() where it would call handle(): with the hydrated payload,
 * which still holds what was submitted, the errors by field, and the same
 * resource handle() would get. Other handlers of the route do not run; the
 * request was not valid. The response is 422 unless invalid() sets another
 * status. A JSON request, and a route whose handlers do not implement this,
 * keep the flat `{errors: {field: [message]}}` 422.
 *
 *     public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface
 *     {
 *         return $resource->withValues($payload->getName(), $payload->getEmail())->withErrors($errors);
 *     }
 *
 * @api
 */
interface HandlesInvalidPayloadInterface
{
    /**
     * @param array<string, list<string>> $errors field => messages, as validate() returned them
     */
    public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface;
}
