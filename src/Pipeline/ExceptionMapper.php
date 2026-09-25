<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\PayloadValidationException;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

/**
 * Maps domain exceptions to content-negotiated HTTP error responses.
 * Stateless — safe across Swoole coroutines.
 *
 * This is the Core default implementation of ExceptionResponseMapperInterface.
 * Packages such as semitexa-api may override the binding to produce machine-facing
 * error envelopes for routes that carry the 'external_api' extension flag.
 */
#[SatisfiesServiceContract(of: ExceptionResponseMapperInterface::class)]
final class ExceptionMapper implements ExceptionResponseMapperInterface
{
    /** The formats an error body can be rendered in here, preferred first. */
    // text/plain last: mapDomainException() renders it, and an explicit
    // `_format=txt` or `Accept: text/plain` must still get it; last, so a
    // wildcard never picks it over json.
    private const ERROR_FORMATS = ['application/json', 'text/html', 'application/xml', 'text/plain'];

    private ?ErrorRouteDispatcher $errorRouteDispatcher = null;

    public function withErrorRouteDispatcher(ErrorRouteDispatcher $errorRouteDispatcher): static
    {
        $clone = clone $this;
        $clone->errorRouteDispatcher = $errorRouteDispatcher;

        return $clone;
    }

    /**
     * Convert a caught exception into an error Response.
     */
    public function map(\Throwable $e, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
    {
        // BEFORE the DomainException branch, because this IS one and the two
        // answer differently on purpose. Pipeline validation — hydration or
        // ValidatablePayloadInterface::validate() — has always replied with the
        // flat `{errors: {field: [message]}}` and 422, and eleven test files
        // pin that shape. A handler throwing ValidationException is stating a
        // domain rule instead, and keeps the `{error, message, context}`
        // envelope below.
        //
        // RouteExecutor used to write this body itself and return early, which
        // is why an #[ExternalApi] route never got its own envelope: the
        // mapper was never reached. Rendering it here keeps every route's error
        // shape decided in one place.
        if ($e instanceof PayloadValidationException) {
            return HttpResponse::json(
                ['errors' => $e->getErrors()],
                HttpStatus::UnprocessableEntity->value,
            );
        }

        if ($e instanceof DomainException) {
            return $this->mapDomainException($e, $request, $metadata);
        }

        return $this->mapUnknownException($e, $request, $metadata);
    }

    private function mapDomainException(DomainException $e, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
    {
        $status = $e->getStatusCode();
        $body = [
            'error' => $e->getErrorCode(),
            'message' => $e->getMessage(),
            'context' => $e->getErrorContext(),
        ];

        $format = $this->negotiateErrorFormat($request, $metadata->produces);

        if ($format === 'html' && $this->errorRouteDispatcher !== null) {
            $response = $this->errorRouteDispatcher->dispatchThrowable($e, $request, ['name' => $metadata->name]);
            if ($response !== null) {
                if ($e instanceof RateLimitException) {
                    $response = $response->withHeaders(['Retry-After' => (string) $e->getRetryAfter()]);
                }

                return $response;
            }
        }

        $response = match ($format) {
            'json' => HttpResponse::json($body, $status->value),
            'html' => $this->renderErrorHtml($status, $body),
            'xml'  => HttpResponse::text($this->arrayToXml($body, 'error'), $status->value)
                          ->withHeaders(['Content-Type' => 'application/xml; charset=utf-8']),
            default => HttpResponse::text($e->getMessage(), $status->value),
        };

        if ($e instanceof RateLimitException) {
            $response = $response->withHeaders(['Retry-After' => (string) $e->getRetryAfter()]);
        }

        return $response;
    }

    private function mapUnknownException(\Throwable $e, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
    {
        // Record the exception via the static logger bridge so silent 500s
        // are at least visible in app.log. The response body still hides
        // details (security: no leak in production); the log is the
        // operator-facing channel.
        \Semitexa\Core\Log\StaticLoggerBridge::error('pipeline', 'Unhandled exception during route execution', [
            'route' => $metadata->name,
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => self::summarizeTrace($e),
        ]);

        $format = $this->negotiateErrorFormat($request, $metadata->produces);

        if ($format === 'html' && $this->errorRouteDispatcher !== null) {
            $response = $this->errorRouteDispatcher->dispatchThrowable($e, $request, ['name' => $metadata->name]);
            if ($response !== null) {
                return $response;
            }
        }

        // In the negotiated format, as a domain error is: always-JSON handed a
        // client that sent `application/json;q=0, */*` the one type it refused.
        $message = 'An unexpected error occurred.';
        $body = ['error' => 'Internal Server Error', 'message' => $message];
        $status = HttpStatus::InternalServerError;

        return match ($format) {
            'html' => $this->renderErrorHtml($status, $body),
            'xml' => HttpResponse::text($this->arrayToXml($body, 'error'), $status->value)
                ->withHeaders(['Content-Type' => 'application/xml; charset=utf-8']),
            'txt' => HttpResponse::text($message, $status->value),
            default => HttpResponse::json($body, $status->value),
        };
    }

    private static function summarizeTrace(\Throwable $e): string
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 6) as $frame) {
            $location = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            $callable = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $frames[] = $location . ' ' . $callable;
        }
        return implode(' | ', $frames);
    }

    private function negotiateErrorFormat(Request $request, ?array $produces): string
    {
        try {
            // A route without `produces` still has a set of formats — the ones
            // this mapper renders — so a refusal among them is honoured instead
            // of falling back to the json default the client ruled out.
            return ContentNegotiator::negotiateResponseFormat(
                $produces !== null && $produces !== [] ? $produces : self::ERROR_FORMATS,
                $request,
                'json',
            );
        } catch (\Throwable) {
            // Everything this route or mapper can say was refused. An error
            // must still go out in something, and RFC 9110 lets it disregard
            // Accept rather than answer with nothing.
            return 'json';
        }
    }

    private function renderErrorHtml(HttpStatus $status, array $body): HttpResponse
    {
        $title = htmlspecialchars($status->reason(), ENT_QUOTES | ENT_HTML5);
        $message = htmlspecialchars($body['message'] ?? '', ENT_QUOTES | ENT_HTML5);
        $html = "<!DOCTYPE html><html><head><title>{$title}</title></head>"
            . "<body><h1>{$status->value} {$title}</h1><p>{$message}</p></body></html>";

        return HttpResponse::html($html, $status->value);
    }

    private function arrayToXml(array $data, string $rootElement): string
    {
        $xml = new \SimpleXMLElement("<{$rootElement}/>");
        $this->arrayToXmlRecursive($data, $xml);
        $node = dom_import_simplexml($xml);
        // @phpstan-ignore-next-line dom_import_simplexml() can return false at runtime.
        if ($node === false) {
            return '';
        }

        $dom = $node->ownerDocument;
        if (!$dom instanceof \DOMDocument) {
            return '';
        }
        $dom->formatOutput = true;

        return $dom->saveXML() ?: '';
    }

    private function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_int($key) ? 'item' : (string) $key;
            // Sanitize key to be a valid XML element name
            $key = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key) ?? '_';
            if (!preg_match('/^[a-zA-Z_]/', $key)) {
                $key = '_' . $key;
            }
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->arrayToXmlRecursive($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string) ($value ?? ''), ENT_XML1));
            }
        }
    }
}
