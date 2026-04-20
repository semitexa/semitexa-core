<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Tenant\TenancyBootstrapperInterface;

/**
 * @internal Resolves the tenant for the current request. May produce an early response (e.g. redirect).
 */
final class TenancyPhase
{
    public function __construct(
        private readonly ?TenancyBootstrapperInterface $tenancy,
    ) {}

    public function execute(RequestLifecycleContext $context): void
    {
        if ($this->tenancy === null || !$this->tenancy->isEnabled()) {
            return;
        }

        $response = $this->tenancy->resolve($context->request);
        if ($response !== null) {
            $context->setEarlyResponse($response);
        }
    }
}
