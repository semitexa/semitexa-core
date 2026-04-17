<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Http\ContentType;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResponseFormat;
use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Discovery\DiscoveredRoute;

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
                        $requestHost = $request->getHeader('Host') ?? '';
                        $requestHost = parse_url("http://{$requestHost}")['host'] ?? $requestHost;
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
                return $resDto;
            }
        }
        $rendererClass = method_exists($resDto, 'getRendererClass') ? $resDto->getRendererClass() : null;
        $rendererClass = is_string($rendererClass) && $rendererClass !== '' ? $rendererClass : null;

        if ($handle && $this->wantsPageDocumentJson($request)) {
            $format = ResponseFormat::Json;
        }

        // Negotiate format when produces is set on the route and we have a render handle
        $produces = $route->produces;
        if ($handle && !$this->wantsPageDocumentJson($request) && $produces !== null && $produces !== []) {
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

        if ($format === null) {
            $format = ResponseFormat::Layout;
        }

        return match ($format) {
            ResponseFormat::Json   => $this->renderJsonResponse($resDto, $request, $route, $handle, $context),
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
    ): object {
        if ($handle && $this->wantsPageDocumentJson($request) && class_exists(\Semitexa\Ssr\Page\PageDocumentProjector::class)) {
            $context = \Semitexa\Ssr\Page\PageDocumentProjector::project(
                $resDto,
                $request,
                $handle,
                $context,
                $route->toArray(),
            );
        }

        return $this->renderJson($resDto, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderJson(object $resDto, array $context): object
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($json ?: '');
        }
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

        $renderer = $rendererClass ?: 'Semitexa\\Ssr\\Layout\\LayoutRenderer';
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

    private function wantsPageDocumentJson(Request $request): bool
    {
        if ($request->getQuery('_format') === 'json') {
            return true;
        }

        return str_contains(strtolower($request->getHeader('Accept') ?? ''), 'application/json');
    }
}
