<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

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

    #[InjectAsReadonly(optional: true)]
    protected TenantContextStoreInterface $tenants;

    public function handle(RequestPipelineContext $context): void
    {
        $required = $context->reRun ? null : self::requiredSignature($context->requestDto);
        if ($required === null) {
            return;
        }

        // The signature covers the path and the query, nothing else. Body
        // fields and files win over query values when the payload is filled,
        // so a signed link replayed with `{"user":43}` would have run as user
        // 43 under a valid signature.
        $request = $context->request;
        if (!in_array(strtoupper($request->getMethod()), array_map('strtoupper', $required->methods), true)) {
            throw new InvalidSignatureException(SignatureCheck::Invalid);
        }
        if ($request->post !== [] || $request->files !== [] || trim((string) $request->content) !== '') {
            throw new InvalidSignatureException(SignatureCheck::Invalid);
        }

        $check = $this->signer->checkRequest($request);
        if ($check !== SignatureCheck::Valid) {
            throw new InvalidSignatureException($check);
        }
        // Made for one site: refused on any other (the parameter is signed).
        $site = $request->getQuery(UrlSigner::TENANT);
        if ($site !== '') {
            $current = isset($this->tenants) ? TenantContextAccess::tenantIdOrDefault($this->tenants->tryGet()) : 'default';
            if ($site !== $current) {
                throw new InvalidSignatureException(SignatureCheck::Invalid);
            }
        }
    }

    /** On the payload or a class it extends (should payload inheritance ever be allowed). */
    private static function requiredSignature(object $payload): ?RequiresSignedUrl
    {
        for ($class = new \ReflectionClass($payload); $class !== false; $class = $class->getParentClass()) {
            $attribute = $class->getAttributes(RequiresSignedUrl::class)[0] ?? null;
            if ($attribute !== null) {
                return $attribute->newInstance();
            }
        }

        return null;
    }
}
