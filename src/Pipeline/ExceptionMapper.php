<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Request;
use Semitexa\Core\Response;

/**
 * Maps domain exceptions to content-negotiated HTTP error responses.
 * Stateless — safe across Swoole coroutines.
 */
final class ExceptionMapper
{
    /**
     * Convert a caught exception into an error Response.
     */
    public function map(\Throwable $e, Request $request, array $route): Response
    {
        if ($e instanceof DomainException) {
            return $this->mapDomainException($e, $request, $route);
        }

        return $this->mapUnknownException($e);
    }

    private function mapDomainException(DomainException $e, Request $request, array $route): Response
    {
        $status = $e->getStatusCode();
        $body = [
            'error' => $e->getErrorCode(),
            'message' => $e->getMessage(),
            'context' => $e->getErrorContext(),
        ];

        $format = $this->negotiateErrorFormat($request, $route);

        $response = match ($format) {
            'json' => Response::json($body, $status->value),
            'html' => $this->renderErrorHtml($status, $body),
            'xml'  => Response::text($this->arrayToXml($body, 'error'), $status->value)
                          ->withHeaders(['Content-Type' => 'application/xml; charset=utf-8']),
            default => Response::text($e->getMessage(), $status->value),
        };

        if ($e instanceof RateLimitException) {
            $response = $response->withHeaders(['Retry-After' => (string) $e->getRetryAfter()]);
        }

        return $response;
    }

    private function mapUnknownException(\Throwable $e): never
    {
        // Rethrow so Application/RouteExecutor can log and produce the negotiated response.
        throw $e;
    }

    private function negotiateErrorFormat(Request $request, array $route): string
    {
        try {
            return ContentNegotiator::negotiateResponseFormat(
                $route['produces'] ?? null,
                $request,
                'json',
            );
        } catch (\Throwable) {
            return 'json';
        }
    }

    private function renderErrorHtml(HttpStatus $status, array $body): Response
    {
        $title = htmlspecialchars($status->reason(), ENT_QUOTES | ENT_HTML5);
        $message = htmlspecialchars($body['message'] ?? '', ENT_QUOTES | ENT_HTML5);
        $html = "<!DOCTYPE html><html><head><title>{$title}</title></head>"
            . "<body><h1>{$status->value} {$title}</h1><p>{$message}</p></body></html>";

        return Response::html($html, $status->value);
    }

    private function arrayToXml(array $data, string $rootElement): string
    {
        $xml = new \SimpleXMLElement("<{$rootElement}/>");
        $this->arrayToXmlRecursive($data, $xml);
        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom->saveXML();
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
