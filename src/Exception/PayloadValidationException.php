<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

/**
 * Validation that failed in the PIPELINE, before any handler ran.
 *
 * The distinction from a plain {@see ValidationException} is where it was
 * raised, and that decides the response shape:
 *
 *  - A handler throwing ValidationException is stating a DOMAIN rule, and its
 *    answer is the domain envelope — `{error, message, context}` — pinned by
 *    ExceptionMapperEnvelopeTest.
 *  - Hydration and `ValidatablePayloadInterface::validate()` failing is the
 *    REQUEST being malformed, and its answer has always been the flat
 *    `{errors: {field: [message]}}` with 422, pinned by eleven test files
 *    including RuntimeValidationPipelineTest.
 *
 * RouteExecutor used to encode that difference by returning the flat body
 * directly from the hydrate/validate stage, which meant pipeline validation
 * never reached an ExceptionResponseMapperInterface at all — so an
 * #[ExternalApi] route, whose whole contract is a machine-facing envelope, got
 * the flat shape instead. The mapper knew how to answer; it was never asked.
 *
 * Carrying the distinction as a TYPE lets both mappers answer correctly: core
 * renders the flat body, and semitexa-api renders the API envelope because this
 * is still a DomainException.
 */
class PayloadValidationException extends ValidationException
{
}
