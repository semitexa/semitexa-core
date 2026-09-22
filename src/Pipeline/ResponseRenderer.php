<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Http\ContentType;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Core\Http\Response\ResponseFormat;
use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Resource\RenderProfile;

final class ResponseRenderer
{
    /**
     * Render the resource DTO into a response based on route metadata and content negotiation.
     */
    public function render(object $resDto, ?object $reqDto, Request $request, DiscoveredRoute $route): object
    {
        // Redirect short-circuit: if the resource has a redirect URL, skip rendering
        if (method_exists($resDto, 'getRedirectUrl') && $resDto->getRedirectUrl() !== null) {
            $redirectUrl = $resDto->getRedirectUrl();
            if (is_string($redirectUrl)) {
                // Security: validate redirect URL to prevent open redirect attacks (VULN-006)
                $parsed = parse_url($redirectUrl);
                if ($parsed !== false && isset($parsed['host'])) {
                    // Only allow http/https schemes for absolute URLs
                    if (isset($parsed['scheme']) && !in_array($parsed['scheme'], ['http', 'https'], true)) {
                        $redirectUrl = '/';
                    } else {
                        // Normalize: strip port from request host before comparison
                        $requestHost = $request->getHost();
                        $redirectHost = strtolower($parsed['host']);
                        $requestHost = strtolower($requestHost);
                        // Allow same-host, localhost, and known OAuth provider domains
                        $allowedExternalHosts = [
                            'accounts.google.com',
                            'login.microsoftonline.com',
                            'github.com',
                            'login.live.com',
                            'appleid.apple.com',
                        ];
                        if ($redirectHost !== $requestHost
                            && $redirectHost !== 'localhost'
                            && $redirectHost !== '127.0.0.1'
                            && !self::isSiblingHost($redirectHost, $requestHost)
                            && !in_array($redirectHost, $allowedExternalHosts, true)) {
                            $redirectUrl = '/';
                        }
                    }
                } elseif ($parsed !== false && !isset($parsed['host'])) {
                    // Relative URL or scheme-relative — allowed
                } else {
                    // Unparseable or scheme without host (e.g. javascript:) — reject
                    $redirectUrl = '/';
                }
            } else {
                $redirectUrl = '';
            }
            $redirectUrl = self::inCurrentLocale($redirectUrl);
            $statusCode = method_exists($resDto, 'getStatusCode') ? $resDto->getStatusCode() : HttpStatus::Found->value;
            return HttpResponse::redirect(
                $redirectUrl,
                is_int($statusCode) ? $statusCode : HttpStatus::Found->value,
            );
        }

        $handle = method_exists($resDto, 'getRenderHandle') ? $resDto->getRenderHandle() : null;
        $context = method_exists($resDto, 'getRenderContext') ? $resDto->getRenderContext() : [];
        /** @var ResponseFormat|null $format */
        $format = method_exists($resDto, 'getRenderFormat') ? $resDto->getRenderFormat() : null;
        $handle = is_string($handle) && $handle !== '' ? $handle : null;
        /** @var array<string, mixed> $context */
        $context = is_array($context) ? $context : [];

        if ($handle) {
            $context = $this->withPageDocumentContext($context, $request, $route);
            if (method_exists($resDto, 'setRenderContext')) {
                $resDto->setRenderContext($context);
            }
        }

        // No render handle: render as JSON if context is set, otherwise return as-is
        if (!$handle) {
            if ($context !== []) {
                if ($format === null || $format === ResponseFormat::Layout) {
                    $format = ResponseFormat::Json;
                }
            } else {
                return DataProfileLabel::apply($resDto, $route);
            }
        }
        $rendererClass = method_exists($resDto, 'getRendererClass') ? $resDto->getRendererClass() : null;
        $rendererClass = is_string($rendererClass) && $rendererClass !== '' ? $rendererClass : null;

        // A page document is a projection OF A PAGE. A route that declares a
        // JSON render profile has already said what its JSON is — its own
        // response class renders it — so projecting it is not a second view of
        // the same thing, it is a substitution: buildMainDocument() fills
        // `content.data` from the RENDER CONTEXT, which a JSON resource does
        // not use, and renderJson() then overwrites the body the resource
        // produced.
        //
        // MEASURED 2026-09-18 on /playground/customers, the reference
        // multi-profile route in this workspace:
        //   Accept: application/ld+json  -> the real collection (Acme Corp, …)
        //   Accept: application/json     -> {"page":…,"content":{"data":[]}}
        // The same Accept the endpoint is documented with was the one that
        // could not return its own data. The gate never asked what the route
        // declared; it asked only what the client sent.
        $wantsPageDocumentJson = $handle !== null
            && !self::declaresJsonProfile($route)
            && $this->wantsPageDocumentJson($request);

        if ($wantsPageDocumentJson) {
            $format = ResponseFormat::Json;
        }

        // A route that renders its own JSON still has to be RENDERED as JSON.
        // Skipping the page projection without this left the response on the
        // layout path: the body was the handler's, correctly, and the label was
        // `text/html` — measured on /playground/customers, which answered a
        // perfectly good collection under an HTML content type. The negotiation
        // is the same question the page-document gate asks, so the two cannot
        // disagree about what "the client asked for JSON" means.
        if (!$wantsPageDocumentJson && $handle !== null && self::declaresJsonProfile($route)
            && $this->wantsPageDocumentJson($request)) {
            $format = ResponseFormat::Json;
        }

        // Negotiate format when produces is set on the route and we have a render handle
        $produces = $route->produces;
        if ($handle && !$wantsPageDocumentJson && $produces !== null && $produces !== []) {
            try {
                $defaultKey = $format !== null ? self::formatEnumToKey($format) : 'json';
                $negotiatedKey = ContentNegotiator::negotiateResponseFormat($produces, $request, $defaultKey);
                $format = self::keyToFormatEnum($negotiatedKey);
            } catch (NegotiationFailedException $e) {
                return HttpResponse::json([
                    'error' => 'Not Acceptable',
                    'message' => $e->getMessage(),
                    'available' => $e->produces,
                ], HttpStatus::NotAcceptable->value);
            }
        }

        // A body the resource produced under a DATA profile is not a page, and
        // the layout path would label it as one: renderLayout() sees content
        // and stamps text/html over it. MEASURED 2026-09-22 across every
        // parameterless GET route x four Accept headers: 21 JSON bodies on 7
        // routes went out as text/html — the grid feeds and /playground/customers
        // for any Accept but application/json, overwriting the application/json
        // and application/ld+json their own response classes had set.
        if ($format === null && $this->readContent($resDto) !== '' && DataProfileLabel::servedProfile($resDto, $route) !== null) {
            return DataProfileLabel::apply($resDto, $route);
        }

        if ($format === null) {
            $format = ResponseFormat::Layout;
        }

        return match ($format) {
            ResponseFormat::Json   => $this->renderJsonResponse($resDto, $request, $route, $handle, $context, $wantsPageDocumentJson),
            ResponseFormat::Layout => $this->renderLayout($resDto, $reqDto, $handle ?? '', $context, $rendererClass),
            ResponseFormat::Xml    => $this->renderXml($resDto, $context),
            ResponseFormat::Text   => $this->renderText($resDto, $context),
            ResponseFormat::Raw    => $resDto,
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderJsonResponse(
        object $resDto,
        Request $request,
        DiscoveredRoute $route,
        ?string $handle,
        array $context,
        bool $wantsPageDocumentJson,
    ): object {
        if ($handle && $wantsPageDocumentJson && class_exists(\Semitexa\Ssr\Application\Service\Page\PageDocumentProjector::class)) {
            $context = \Semitexa\Ssr\Application\Service\Page\PageDocumentProjector::project(
                $resDto,
                $request,
                $handle,
                $context,
                $route->toArray(),
            );
        }

        // The projected document IS the answer when it was asked for, so it
        // replaces whatever the resource held. Only an UNPROJECTED body is the
        // handler's own and must survive.
        return $this->renderJson($resDto, $context, keepExistingBody: !$wantsPageDocumentJson);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderJson(object $resDto, array $context, bool $keepExistingBody = true): object
    {
        // A BODY THE HANDLER ALREADY PRODUCED IS THE ANSWER, not a draft.
        // This encoded the render context over the top of it, so a handler that
        // did the work — built the collection, called setContent(), set its
        // Deprecation and Sunset headers — had the collection replaced by the
        // context, which for such a handler is empty. The headers survived and
        // the body did not, which is the worst shape of all: a 200 that looks
        // like a working API and carries nothing.
        //
        // Encoding the context is still what an ordinary JSON resource wants,
        // and that is unchanged: it reaches here with no content of its own.
        $existing = $keepExistingBody && method_exists($resDto, 'getContent') ? $resDto->getContent() : '';

        if (!is_string($existing) || $existing === '') {
            // `__page_document_html_iri` and its two neighbours are put on every
            // handle-bearing context for the page document and for the template
            // that renders <link rel="alternate">. PageDocumentProjector drops
            // every `__` key on its way out; a context encoded WITHOUT the
            // projector has to drop them too, or an API body carries the
            // renderer's own bookkeeping. Stripped here rather than never added,
            // so the HTML path keeps exactly the context it always had.
            $json = json_encode(self::withoutInternalKeys($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (method_exists($resDto, 'setContent')) {
                $resDto->setContent($json ?: '');
            }
        }

        // The type is still set here unconditionally, as it always was. A
        // handler that answers `application/ld+json` reaches the client through
        // a route with no render handle, which returns before this point — and
        // making the rule "keep a Content-Type the resource already carries"
        // instead preserved the DEFAULT text/html a bare resource is born with,
        // so /platform/calendar/events shipped JSON labelled as HTML. A default
        // is not a declaration, and this had no way to tell them apart.
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'application/json');
        }

        return $resDto;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderLayout(object $resDto, ?object $reqDto, string $handle, array $context, ?string $rendererClass): object
    {
        // Resources that render via Twig template inheritance (HtmlResponse subclasses with a
        // declared template or already-rendered content) bypass LayoutRenderer entirely.
        // LayoutRenderer is intended for slot-based layout composition without Twig inheritance.
        $existingContent = $this->readContent($resDto);

        if ($existingContent === '' && method_exists($resDto, 'getDeclaredTemplate')) {
            $declaredTemplate = $resDto->getDeclaredTemplate();
            if ($declaredTemplate !== null && $declaredTemplate !== '' && method_exists($resDto, 'renderTemplate')) {
                if (method_exists($resDto, 'setRenderContext')) {
                    $resDto->setRenderContext($context);
                }
                $resDto->renderTemplate($declaredTemplate);
                $existingContent = $this->readContent($resDto);
            }
        }

        if ($existingContent !== '') {
            if (method_exists($resDto, 'setHeader')) {
                $resDto->setHeader('Content-Type', 'text/html; charset=utf-8');
            }
            return $resDto;
        }

        $renderer = $rendererClass ?: 'Semitexa\\Ssr\\Application\\Service\\Layout\\LayoutRenderer';
        if (!class_exists($renderer)) {
            throw new \RuntimeException(
                'LayoutRenderer not found. For HTML pages install semitexa/ssr: composer require semitexa/ssr. Do not implement a custom Twig renderer in the project.'
            );
        }

        if (!isset($context['response'])) {
            $context = ['response' => $context] + $context;
        }
        if (!isset($context['request']) && isset($reqDto)) {
            $context['request'] = $reqDto;
        }
        if (method_exists($resDto, 'getLayoutFrame') && $resDto->getLayoutFrame() !== null) {
            $context['layout_frame'] = $resDto->getLayoutFrame();
        }
        $html = $renderer::renderHandle($handle, $context);
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($html);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return $resDto;
    }

    private function readContent(object $resDto): string
    {
        if (!method_exists($resDto, 'getContent')) {
            return '';
        }

        $content = $resDto->getContent();

        return is_string($content) ? $content : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderXml(object $resDto, array $context): object
    {
        $xml = self::arrayToXml($context, 'response');
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($xml);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'application/xml; charset=utf-8');
        }
        return $resDto;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderText(object $resDto, array $context): object
    {
        $text = $context['text'] ?? json_encode($context, JSON_PRETTY_PRINT);
        $text = is_string($text) ? $text : (json_encode($context, JSON_PRETTY_PRINT) ?: '');
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($text);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        return $resDto;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function arrayToXml(array $data, string $rootElement = 'root'): string
    {
        $xml = new \SimpleXMLElement("<{$rootElement}/>");
        self::arrayToXmlRecursive($data, $xml);
        $dom = dom_import_simplexml($xml)->ownerDocument;
        if (!$dom instanceof \DOMDocument) {
            return '';
        }
        $dom->formatOutput = true;
        return $dom->saveXML() ?: '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_int($key) ? 'item' : (string) $key;
            if (is_array($value)) {
                $child = $xml->addChild($key);
                self::arrayToXmlRecursive($value, $child);
            } else {
                $scalar = is_scalar($value) || $value === null
                    ? (string) ($value ?? '')
                    : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                $xml->addChild($key, htmlspecialchars($scalar ?: '', ENT_XML1));
            }
        }
    }

    private static function formatEnumToKey(ResponseFormat $format): string
    {
        return match ($format) {
            ResponseFormat::Json   => 'json',
            ResponseFormat::Layout => 'html',
            ResponseFormat::Xml    => 'xml',
            ResponseFormat::Text   => 'txt',
            ResponseFormat::Raw    => 'json',
        };
    }

    private static function keyToFormatEnum(string $key): ResponseFormat
    {
        return match ($key) {
            'json' => ResponseFormat::Json,
            'html' => ResponseFormat::Layout,
            'xml'  => ResponseFormat::Xml,
            'txt'  => ResponseFormat::Text,
            default => ResponseFormat::Json,
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */

    /**
     * A root-relative redirect target, addressed in the locale being served.
     *
     * Under URL-prefixed locales a redirect is the one internal link the
     * application does not write by hand — every href goes through a template
     * helper and every generated URL through RouteUrlBuilder, but
     * `setRedirect('/app')` is a bare string. Left alone it sends a Ukrainian
     * visitor to the English page after every form submission, which is how a
     * language quietly gets lost mid-session.
     *
     * Only root-relative paths are touched. Absolute and scheme-relative URLs
     * belong to another host (the validation above has already decided which
     * are allowed) and a path that already opens with a supported locale is
     * left as it is, so this can never prefix twice.
     */
    private static function inCurrentLocale(string $url): string
    {
        if (!class_exists(LocaleContextStore::class)) {
            return $url;
        }

        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return $url;
        }

        if (!LocaleContextStore::isUrlPrefixEnabled()) {
            return $url;
        }

        $locale = LocaleContextStore::getLocale();

        if ($locale === LocaleContextStore::getDefaultLocale()) {
            return $url;
        }

        $firstSegment = explode('/', ltrim(parse_url($url, PHP_URL_PATH) ?: '/', '/'), 2)[0];
        $supported = LocaleContextStore::getSupportedLocales();

        if ($supported !== [] && in_array($firstSegment, $supported, true)) {
            return $url;
        }

        return '/' . $locale . $url;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withPageDocumentContext(array $context, Request $request, DiscoveredRoute $route): array
    {
        $htmlQuery = $request->query;
        unset($htmlQuery['_format'], $htmlQuery['_slot'], $htmlQuery['_expand']);

        $jsonQuery = $request->query;
        unset($jsonQuery['_slot'], $jsonQuery['_expand']);
        $jsonQuery['_format'] = 'json';

        $path = $request->getPath();
        $context['__page_document_html_iri'] = $htmlQuery === [] ? $path : $path . '?' . http_build_query($htmlQuery);
        $context['__page_document_json_iri'] = $path . '?' . http_build_query($jsonQuery);
        /** @var array<string, mixed> $query */
        $query = $request->query;
        $context['__page_alternates'] = $this->buildPageAlternates($route, $path, $query);

        return $context;
    }

    /**
     * @param array<string,mixed> $query
     * @return list<array{type:string,href:string}>
     */
    private function buildPageAlternates(DiscoveredRoute $route, string $path, array $query): array
    {
        $produces = $route->produces;
        if ($produces === null || $produces === []) {
            return [];
        }

        $alternates = [];
        foreach ($produces as $mime) {
            if ($mime === '') {
                continue;
            }

            $normalizedMime = strtolower(trim($mime));
            $formatKey = ContentType::toFormatKey($normalizedMime);
            if ($formatKey === null || $formatKey === 'html') {
                continue;
            }

            $alternateQuery = $query;
            unset($alternateQuery['_slot'], $alternateQuery['_expand']);
            $alternateQuery['_format'] = $formatKey;
            $href = $path . '?' . http_build_query($alternateQuery);

            $alternates[$normalizedMime] = [
                'type' => $normalizedMime,
                'href' => $href,
            ];
        }

        return array_values($alternates);
    }

    /**
     * The context without the renderer's own bookkeeping.
     *
     * Same rule PageDocumentProjector::sanitizeContext() applies: a key that
     * starts with `__` belongs to the framework, not to the response.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function withoutInternalKeys(array $context): array
    {
        foreach (array_keys($context) as $key) {
            if (str_starts_with((string) $key, '__')) {
                unset($context[$key]);
            }
        }

        return $context;
    }

    /**
     * Does this route declare that JSON is one of ITS OWN representations?
     *
     * Only an explicit `renderProfile` counts, and only when it names Json.
     * A page route declares none, so nothing about page rendering moves — the
     * whole page-document path is exactly as it was for every route that did
     * not opt in. `responsesByProfile` alone is not the signal either: the
     * profiles list is what the route states it can BE, and the map is only how
     * each one is built.
     *
     * @param DiscoveredRoute $route
     */
    private static function declaresJsonProfile(DiscoveredRoute $route): bool
    {
        $declared = $route->renderProfile;

        if ($declared instanceof RenderProfile) {
            return $declared === RenderProfile::Json;
        }

        if (!is_array($declared)) {
            return false;
        }

        // Strict identity alone: the declared list is typed as RenderProfile[],
        // so the instanceof that used to guard this was always true, and a
        // stray non-enum value is !== RenderProfile::Json regardless.
        foreach ($declared as $profile) {
            if ($profile === RenderProfile::Json) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this client positively want the page as a JSON document?
     *
     * `?_format=json` is an explicit request and always wins. Otherwise the
     * question is a negotiation, and it is answered by the same negotiator that
     * decides the response format a few lines below — asking it with
     * `text/html` declared first, so JSON has to be *preferred*, not merely
     * mentioned.
     *
     * Called once per render; the answer is passed down rather than recomputed,
     * so the gate and the page-document projector cannot reach different
     * conclusions about the same request.
     *
     * It used to be `str_contains($accept, 'application/json')`, which has no
     * notion of quality values, so it read two very different headers as a
     * request for JSON:
     *
     *   Accept: text/html,application/json;q=0.9   → the client prefers HTML
     *   Accept: application/json;q=0               → the client REFUSES JSON
     *
     * Both were served page-document JSON, and because this gate also
     * short-circuits the negotiation block, the route's own `produces` list
     * never got a say. A missing Accept, an empty one and `*\/*` still mean
     * "not specifically JSON" exactly as before.
     */
    private function wantsPageDocumentJson(Request $request): bool
    {
        if ($request->getQuery('_format') === 'json') {
            return true;
        }

        try {
            return ContentNegotiator::negotiateResponseFormat(
                ['text/html', 'application/json'],
                $request,
                'html',
            ) === 'json';
        } catch (NegotiationFailedException) {
            // Nothing the client will accept is html or json — so it is not
            // asking for a page document either.
            return false;
        }
    }

    /**
     * Is this redirect target another host of the SAME site?
     *
     * An application can be split across hosts on purpose — a public site on
     * the apex and a cabinet on `account.`, say — and moving a visitor between
     * them is ordinary navigation, not an open redirect. The guard above had no
     * way to say that: its allow-list is a fixed set of OAuth providers, so an
     * application redirecting to its own sibling host had the target silently
     * replaced with '/', which looks like the redirect simply not working.
     *
     * The dot is the whole point. Matching on a bare suffix is the classic
     * version of this bug: `evil-example.com` ends with `example.com`, and an
     * attacker who can register that name gets exactly the open redirect this
     * check exists to prevent. Only a real label boundary counts, in either
     * direction — parent to child, child to parent.
     */
    private static function isSiblingHost(string $redirectHost, string $requestHost): bool
    {
        if ($redirectHost === '' || $requestHost === '') {
            return false;
        }

        // A single label ("localhost", "app") has no site to be a sibling of.
        if (!str_contains($redirectHost, '.') || !str_contains($requestHost, '.')) {
            return false;
        }

        return str_ends_with($requestHost, '.' . $redirectHost)
            || str_ends_with($redirectHost, '.' . $requestHost);
    }

}
