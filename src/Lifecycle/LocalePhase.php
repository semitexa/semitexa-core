<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\HttpResponse;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\LocaleBootstrapper;

/**
 * @internal Resolves locale from request, sets LocaleContextInterface, may produce a redirect.
 */
final class LocalePhase
{
    public function __construct(
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ?LocaleBootstrapper $localeBootstrapper,
    ) {}

    public function execute(RequestLifecycleContext $context): void
    {
        if ($this->localeBootstrapper === null || !$this->localeBootstrapper->isEnabled()) {
            return;
        }

        $request = $context->request;

        $cookieJar = $this->requestScopedContainer->has(CookieJarInterface::class)
            ? $this->requestScopedContainer->get(CookieJarInterface::class)
            : null;
        if (!$cookieJar instanceof CookieJarInterface) {
            $cookieJar = null;
        }

        $resolution = $this->localeBootstrapper->resolve($request, $cookieJar);
        $this->requestScopedContainer->set(LocaleContextInterface::class, $this->localeBootstrapper->getLocaleContext());

        $config = $this->localeBootstrapper->getConfig();

        LocaleContextStore::setUrlPrefixEnabled($config->urlPrefixEnabled);
        LocaleContextStore::setDefaultLocale($config->defaultLocale);

        // 301 redirect: /{defaultLocale}/path -> /path (GET/HEAD only)
        if ($resolution !== null
            && $resolution->hadPathPrefix
            && $resolution->locale === $config->defaultLocale
            && $config->urlPrefixEnabled
            && $config->urlRedirectDefault
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            $target = $resolution->strippedPath ?: '/';
            $qs = $request->getQueryString();
            if ($qs !== '') {
                $target .= '?' . $qs;
            }
            $context->setEarlyResponse(new HttpResponse('', HttpStatus::MovedPermanently->value, ['Location' => $target]));
            return;
        }

        // Store stripped path for routing
        if ($resolution !== null && $resolution->strippedPath !== null && $config->urlPrefixEnabled) {
            $context->setLocaleStrippedPath($resolution->strippedPath);
        }
    }
}
