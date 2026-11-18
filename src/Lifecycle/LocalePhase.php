<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\SecureCookieSetting;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Locale\LocaleSwitch;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Request;
use Semitexa\Locale\Application\Service\Resolver\CookieLocaleResolver;
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

        // An explicit choice: the switcher's own link. Before both redirects
        // below, because it is the one request a stored preference must not
        // outrank; see LocaleSwitch for the trap this ends.
        if ($resolution !== null && $config->urlPrefixEnabled) {
            $chosen = $request->getQuery(LocaleSwitch::QUERY_PARAMETER);
            $target = LocaleSwitch::target(
                $resolution->hadPathPrefix ? ($resolution->strippedPath ?? '/') : $request->getPath(),
                $request->getQueryString(),
                $request->getMethod(),
                $request->getHeader('Accept'),
                $chosen,
                $config->defaultLocale,
                $config->supportedLocales,
            );

            if ($target !== null) {
                $context->setEarlyResponse($this->switchResponse($request, $cookieJar, $chosen, $target));

                return;
            }
        }

        // 301 redirect: /{defaultLocale}/path -> /path (GET/HEAD only)
        if ($resolution !== null
            && $resolution->hadPathPrefix
            && $resolution->locale === $config->defaultLocale
            && $config->urlPrefixEnabled
            && $config->urlRedirectDefault
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            // Collapsed to ONE leading slash: `/en//evil.example` strips to
            // `//evil.example`, a protocol-relative Location on another host, and
            // browsers read `\` as `/`, so `/en/\evil.example` is the same thing.
            // Browsers also DROP tab/newline/CR, so `/en/\t/evil.example` became
            // `//evil.example` after the collapse: a path with ASCII controls is
            // not redirected anywhere but `/`.
            $target = LocaleSwitch::onThisSite($resolution->strippedPath ?? '');
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
                $request->getCookie(CookieLocaleResolver::DEFAULT_COOKIE_NAME),
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
     * The answer to a switcher link: the stored preference brought in line
     * with the choice, and a redirect to the page without the marker.
     *
     * The cookie is only ever UPDATED. A visitor who carries none has no
     * stored preference to get in the way, and whether to keep one at all
     * stays the application's decision. It is written the way the application
     * is told to write it (`Path=/`, `SameSite=Lax`, a year, readable by
     * scripts so a client-side switcher can still replace it), on the
     * session's domain so the two cookies of one site cover the same hosts,
     * and a same-named cookie under the other Domain variant is expired.
     *
     * Not written for a HEAD (which changes nothing), nor for a navigation
     * another site started: that visitor still gets the redirect, and then
     * their own language as before. A link fetched ahead of a click is
     * refused instead, since a browser may replay a prefetched redirect on
     * the real click, and that one would arrive without the cookie change.
     */
    private function switchResponse(Request $request, ?CookieJarInterface $cookieJar, string $chosen, string $target): HttpResponse
    {
        // Never stored by a browser or a shared cache: the answer changes a
        // cookie, and the next visitor's click must reach this code too.
        $headers = ['Cache-Control' => 'no-store'];

        $stored = $request->getCookie(CookieLocaleResolver::DEFAULT_COOKIE_NAME);
        $changes = $cookieJar !== null
            && $stored !== ''
            && $stored !== $chosen
            && strtoupper($request->getMethod()) === 'GET'
            && !LocaleSwitch::startedElsewhere($request->getHeader('Sec-Fetch-Site'));

        if ($changes) {
            if (LocaleSwitch::isSpeculative(
                $request->getHeader('Sec-Purpose'),
                $request->getHeader('Purpose'),
                $request->getHeader('X-Purpose'),
                $request->getHeader('X-Moz'),
            )) {
                // 503 because a browser discards a failed prefetch and makes
                // the real request on the click; any redirect could be kept.
                return new HttpResponse('', HttpStatus::ServiceUnavailable->value, $headers);
            }

            $options = [
                'path' => '/',
                'maxAge' => 31536000,
                'sameSite' => 'lax',
                'secure' => SecureCookieSetting::fromEnvironment()->secureFor($request->getScheme() === 'https'),
            ];
            $domain = (string) (Environment::getEnvValue('SESSION_COOKIE_DOMAIN') ?? '');
            if ($domain !== '') {
                $options['domain'] = $domain;
            }

            // First, so a store that counts the two as one cookie ends with
            // the new value: the same-named cookie under the other Domain
            // variant, which the browser would otherwise keep sending first.
            foreach (LocaleSwitch::otherCookieDomains($request->getHost(), $domain) as $other) {
                $cookieJar->remove(CookieLocaleResolver::DEFAULT_COOKIE_NAME, '/', $other);
            }
            $cookieJar->set(CookieLocaleResolver::DEFAULT_COOKIE_NAME, $chosen, $options);
        }

        return new HttpResponse('', HttpStatus::Found->value, ['Location' => $target] + $headers);
    }

    /**
     * Where a prefix-less page request belongs, given who is asking. Null when
     * it belongs exactly where it is.
     *
     * Every guard here is one way of being sure the request is a person opening
     * a page in a language they chose, rather than anything else that happens to
     * be a GET.
     *
     * @param array<string> $supportedLocales
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
