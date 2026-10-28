<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * Refuses a #[RequiresSignedUrl] route whose URL is unsigned, changed or
 * expired, or that carries a body the signature does not cover. After authorization (0), before rate limits (2), so a forged link
 * spends no one's quota. A re-run of a live region already passed.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 1)]
final class SignedUrlListener implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected UrlSigner $signer;

    public function handle(RequestPipelineContext $context): void
    {
        if ($context->reRun || !self::requiresSignature($context->requestDto)) {
            return;
        }

        // The signature covers the path and the query, nothing else. Body
        // fields and files win over query values when the payload is filled,
        // so a signed link replayed with `{"user":43}` would have run as user
        // 43 under a valid signature.
        $request = $context->request;
        if ($request->post !== [] || $request->files !== [] || trim((string) $request->content) !== '') {
            throw new InvalidSignatureException(SignatureCheck::Invalid);
        }

        $check = $this->signer->checkRequest($request);
        if ($check !== SignatureCheck::Valid) {
            throw new InvalidSignatureException($check);
        }
    }

    /** On the payload or a class it extends (should payload inheritance ever be allowed). */
    private static function requiresSignature(object $payload): bool
    {
        for ($class = new \ReflectionClass($payload); $class !== false; $class = $class->getParentClass()) {
            if ($class->getAttributes(RequiresSignedUrl::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
