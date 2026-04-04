<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Error\ErrorPageContext;
use Semitexa\Core\Request;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\HttpResponse;

class ErrorRenderer
{
    public static function render(\Throwable $e, ?Request $request = null, bool $debug = false): HttpResponse
    {
        return self::renderStatus(
            new ErrorPageContext(
                statusCode: HttpStatus::InternalServerError->value,
                reasonPhrase: HttpStatus::InternalServerError->reason(),
                publicMessage: 'An unexpected error occurred.',
                requestPath: $request?->getPath() ?? '/',
                requestMethod: $request?->getMethod() ?? 'GET',
                requestId: $request?->getHeader('X-Request-ID') ?: null,
                debugEnabled: $debug,
                exceptionClass: get_debug_type($e),
                debugMessage: $debug ? $e->getMessage() : null,
                trace: $debug ? $e->getTraceAsString() : null,
                originalRouteName: null,
            ),
            $request,
        );
    }

    public static function renderStatus(ErrorPageContext $context, ?Request $request = null): HttpResponse
    {
        $accept = strtolower($request?->getHeader('Accept') ?? '');
        $isHtml = str_contains($accept, 'text/html')
            || str_contains($accept, 'application/xhtml+xml')
            || str_contains($accept, '*/*');

        if ($isHtml) {
            $title = htmlspecialchars($context->reasonPhrase, ENT_QUOTES | ENT_HTML5);
            $headline = htmlspecialchars($context->statusCode . ' ' . $context->reasonPhrase, ENT_QUOTES | ENT_HTML5);
            $detail = '<p>' . htmlspecialchars($context->publicMessage, ENT_QUOTES | ENT_HTML5) . '</p>';
            if ($context->debugEnabled && $context->debugMessage !== null) {
                $detail .= '<p>' . htmlspecialchars($context->debugMessage, ENT_QUOTES | ENT_HTML5) . '</p>';
            }
            if ($context->debugEnabled && $context->trace !== null) {
                $detail .= '<pre><code>' . htmlspecialchars($context->trace, ENT_QUOTES | ENT_HTML5) . '</code></pre>';
            }

            $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>'
                . '<style>body{font-family:system-ui;padding:24px;color:#111;background:#fafafa}'
                . '.card{max-width:720px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px}'
                . '.meta{color:#4b5563;font-size:14px;margin-top:12px}'
                . 'code{white-space:pre-wrap;}</style></head><body>'
                . '<div class="card">'
                . '<h1>' . $headline . '</h1>'
                . $detail
                . '<p class="meta">' . htmlspecialchars($context->requestMethod . ' ' . $context->requestPath, ENT_QUOTES | ENT_HTML5) . '</p>'
                . '</div></body></html>';

            return new HttpResponse($html, $context->statusCode, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $payload = [
            'error' => $context->reasonPhrase,
            'message' => $context->debugEnabled && $context->debugMessage !== null
                ? $context->debugMessage
                : $context->publicMessage,
        ];
        if ($context->debugEnabled && $context->trace !== null) {
            $payload['trace'] = $context->trace;
        }

        return HttpResponse::json($payload, $context->statusCode);
    }
}
