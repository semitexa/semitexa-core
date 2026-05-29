<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Http\PayloadMetadataReflector;
use Semitexa\Core\HttpResponse;

/**
 * Generic OPTIONS metadata handler (Multi-Modal API — Mode 4).
 *
 * A single, payload-agnostic handler that emits the canonical metadata
 * document (Phase 0 design §3.2) for ANY routable payload, rather than
 * per-payload hooks. It is wired by {@see \Semitexa\Core\Pipeline\RouteExecutor}
 * as the sole handler of an OPTIONS request to a payload route: that request
 * resolves to the SAME `AbstractPayloadRoute` as the endpoint's other methods,
 * so `$context->requestDto` is an instance of the target payload and the
 * upstream `AuthorizationListener` (phase AuthCheck) has already enforced the
 * endpoint's own access model. There is no auth bypass and no parallel route:
 * a protected endpoint's OPTIONS requires authentication exactly like its GET,
 * a public endpoint's OPTIONS is anonymous.
 *
 * The handler does no per-payload work — the document is a guaranteed-faithful
 * projection of the payload type produced by {@see PayloadMetadataReflector}.
 *
 * Registered as an `#[AsService]` so the container can resolve it by class
 * name when RouteExecutor injects it into the OPTIONS route's handler list.
 * It is a {@see PipelineListenerInterface} (not a TypedHandler) because the
 * target payload/resource types vary per endpoint; it reads the context
 * generically and writes the JSON response onto `$context->resourceDto`.
 */
#[AsService]
final class OptionsMetadataHandler implements PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void
    {
        /** @var class-string $payloadClass */
        $payloadClass = $context->requestDto::class;

        $context->resourceDto = HttpResponse::json(
            PayloadMetadataReflector::describe($payloadClass),
        );
    }
}
