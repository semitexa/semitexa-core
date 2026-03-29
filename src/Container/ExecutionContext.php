<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;

/**
 * Execution-scoped context values supplied per execution (HTTP request, CLI command, queue job).
 * Replaces the old RequestContext + individual setTenantContext/setAuthContext/setLocaleContext calls.
 *
 * These values are resolved by #[InjectAsMutable] during clone-time injection.
 */
final class ExecutionContext
{
    public function __construct(
        public readonly ?Request $request = null,
        public readonly ?SessionInterface $session = null,
        public readonly ?CookieJarInterface $cookieJar = null,
        public readonly ?TenantContextInterface $tenantContext = null,
        public readonly ?AuthContextInterface $authContext = null,
        public readonly ?LocaleContextInterface $localeContext = null,
    ) {}
}
