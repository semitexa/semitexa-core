<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\RouteContractAssemblerInterface;
use Semitexa\Core\Http\HttpStatus;
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
 * One Way Pattern — Phase 1: the document is now the FULL route contract
 * assembled by {@see RouteContractAssemblerInterface} — the legacy
 * PayloadMetadataReflector projection byte-for-byte (additive only: no key
 * is removed or renamed, so the grid runtime's existing OPTIONS negotiation
 * keeps working) plus the `input` block, contributed blocks (`collection`),
 * and the `output` block where the route's response resolves to registered
 * resource metadata.
 *
 * The contract is boot-time attribute data, so the response carries a
 * strong `ETag` derived from the document; a matching `If-None-Match`
 * short-circuits to `304 Not Modified` with an empty body.
 */
#[AsService]
final class OptionsMetadataHandler implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected RouteContractAssemblerInterface $assembler;

    public function handle(RequestPipelineContext $context): void
    {
        /** @var class-string $payloadClass */
        $payloadClass = $context->requestDto::class;

        $responseClass = $context->resolvedMetadata?->responseClass;
        if ($responseClass === '' || ($responseClass !== null && !class_exists($responseClass))) {
            $responseClass = null;
        }

        $contract = $this->assembler->assemble($payloadClass, $responseClass);
        $etag = $contract->etag();

        $ifNoneMatch = $context->request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag)) {
            $context->resourceDto = HttpResponse::text('', HttpStatus::NotModified->value)
                ->withHeaders(['ETag' => $etag]);

            return;
        }

        $context->resourceDto = HttpResponse::json($contract->toDocument())
            ->withHeaders(['ETag' => $etag]);
    }

    /**
     * RFC 9110 §13.1.2 weak comparison: `*` matches anything; otherwise the
     * header is a comma-separated list of entity tags, compared ignoring
     * any `W/` weakness prefix.
     */
    private static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
