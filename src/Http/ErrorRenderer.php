<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Request;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\HttpResponse;

class ErrorRenderer
{
    public static function render(\Throwable $e, ?Request $request = null, bool $debug = false): HttpResponse
    {
        $accept = $request?->getHeader('Accept') ?? '';
        $isHtml = str_contains($accept, 'text/html');
        $message = $debug ? $e->getMessage() : 'Internal Server Error';

        if ($isHtml) {
            $detail = $debug
                ? '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
                  . '<pre><code>' . htmlspecialchars($e->getTraceAsString()) . '</code></pre>'
                : '<p>An unexpected error occurred.</p>';
            $html = '<!doctype html><html><head><meta charset="utf-8"><title>Error</title>'
                . '<style>body{font-family:system-ui;padding:24px;color:#111;background:#fafafa}'
                . '.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px}'
                . 'code{white-space:pre-wrap;}</style></head><body>'
                . '<div class="card">'
                . '<h1>Internal Server Error</h1>'
                . $detail
                . '</div></body></html>';
            return new HttpResponse($html, HttpStatus::InternalServerError->value, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $payload = ['error' => 'Internal Server Error'];
        if ($debug) {
            $payload['message'] = $e->getMessage();
            $payload['trace'] = $e->getTraceAsString();
        }
        return HttpResponse::json($payload, HttpStatus::InternalServerError->value);
    }
}
