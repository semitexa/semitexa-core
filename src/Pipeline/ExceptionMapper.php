<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Exception\DomainException;
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
        $format = $this->negotiateErrorFormat($request, $metadata->produces);

        if ($format === 'html' && $this->errorRouteDispatcher !== null) {
            $response = $this->errorRouteDispatcher->dispatchThrowable($e, $request, ['name' => $metadata->name]);
            if ($response !== null) {
                return $response;
            }
        }

        return HttpResponse::json([
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred.',
        ], HttpStatus::InternalServerError->value);
    }

    private function negotiateErrorFormat(Request $request, ?array $produces): string
    {
        try {
            return ContentNegotiator::negotiateResponseFormat($produces, $request, 'json');
        } catch (\Throwable) {
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
