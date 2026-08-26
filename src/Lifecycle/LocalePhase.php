<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\HttpResponse;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\Application\Service\LocaleBootstrapper;

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

        // The EFFECTIVE (per-tenant) pack — the redirect target and the
        // context-store default/prefix must match the pack the resolution
        // above validated against, not the global base.
        $config = $this->localeBootstrapper->getEffectiveConfig();

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

        // A person who told us their language, on a URL that does not carry one.
        //
        // With URL prefixes on, a path without a prefix means the default
        // language — deliberately, because that is what stops one address from
        // serving three different pages. The cost is that the stored preference
        // an application writes into the `locale` cookie stops being consulted
        // at all: a signed-in visitor who chose Ukrainian gets English back the
        // moment they follow a bookmark, a link from an email, or a redirect
        // written before they signed in.
        //
        // Sending them to their own URL keeps both promises: one address still
        // means one language, and the person still gets theirs. Only for a
        // request that actually carries the cookie, so a crawler — which sends
        // none — keeps seeing exactly what it saw before, and only for GET and
        // HEAD, so a form post is never turned into a redirect that drops it.
        //
        // Found (2026-08-26) as an application bug: a client had Ukrainian
        // selected and the whole cabinet came back in English after prefixes
        // went live.
        //
        // Restricted to requests that ask for a page. This phase runs before
        // routing, so it cannot know what the URL serves — and a locale prefix
        // in front of an image, a feed or a JSON endpoint is a 404, not a
        // translation. `Accept: text/html` is what separates a person
        // navigating from the browser fetching everything else on their behalf.
        if ($resolution !== null && !$resolution->hadPathPrefix && $config->urlPrefixEnabled) {
            $target = self::preferredLocaleTarget(
                $request->getPath(),
                $request->getQueryString(),
                $request->getMethod(),
                $request->getHeader('Accept'),
                $request->getCookie('locale'),
                $config->defaultLocale,
                $config->supportedLocales,
            );

            if ($target !== null) {
                // Found, never Moved Permanently: this answer depends on who is
                // asking. A permanent redirect would be cached by the browser
                // and by any shared proxy, and the next visitor — or the same
                // one after signing out — would be dragged into a language
                // nobody chose. Vary says the same thing to the caches.
                $context->setEarlyResponse(new HttpResponse('', HttpStatus::Found->value, [
                    'Location' => $target,
                    'Vary' => 'Cookie, Accept',
                ]));

                return;
            }
        }

        // Store stripped path for routing
        if ($resolution !== null && $resolution->strippedPath !== null && $config->urlPrefixEnabled) {
            $context->setLocaleStrippedPath($resolution->strippedPath);
        }
    }

    /**
     * Where a prefix-less page request belongs, given who is asking. Null when
     * it belongs exactly where it is.
     *
     * Every guard here is one way of being sure the request is a person opening
     * a page in a language they chose, rather than anything else that happens to
     * be a GET.
     *
     * @param list<string> $supportedLocales
     *
     * @internal Split out of execute() so the decision can be read and tested
     *           without standing up a request, a container and a locale pack.
     */
    public static function preferredLocaleTarget(
        string $path,
        string $queryString,
        string $method,
        ?string $accept,
        string $cookieLocale,
        string $defaultLocale,
        array $supportedLocales,
    ): ?string {
        // A form post is never turned into a redirect: the body would be
        // dropped on the way and the write would silently not happen.
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        // This phase runs before routing, so it cannot know what a URL serves.
        // A locale prefix in front of an image, a calendar feed or a JSON
        // endpoint is a 404, not a translation — and those are most of the GETs
        // a browser makes. Asking for text/html is what a person navigating
        // does and what fetching a picture does not.
        if (!str_contains(strtolower($accept ?? ''), 'text/html')) {
            return null;
        }

        $preferred = strtolower(trim($cookieLocale));

        // No cookie is the crawler's case, and the answer for it must not
        // change: the bare path stays the default language.
        if ($preferred === '' || $preferred === $defaultLocale) {
            return null;
        }

        if (!in_array($preferred, $supportedLocales, true)) {
            return null;
        }

        // A path that already opens with a supported locale is where it belongs.
        // The caller only asks about prefix-less paths, so this is belt and
        // braces — but the failure it prevents is a browser bouncing between two
        // URLs forever, which is worth being certain about here rather than
        // trusting every future caller to have checked.
        $first = explode('/', ltrim($path, '/'), 2)[0];

        if (in_array(strtolower($first), $supportedLocales, true)) {
            return null;
        }

        return '/' . $preferred . $path . ($queryString !== '' ? '?' . $queryString : '');
    }
}
