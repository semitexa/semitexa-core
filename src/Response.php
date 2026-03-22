<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Http\HttpStatus;

/**
 * HTTP Response representation
 */
readonly class Response implements ResourceInterface
{
    public function __construct(
        public string $content,
        public int $statusCode = HttpStatus::Ok->value,
        public array $headers = [],
        public bool $alreadySent = false,
    ) {}

    /** Use when the response was already sent (e.g. SSE stream); bootstrap will not call end(). */
    public static function alreadySent(): self
    {
        return new self('', HttpStatus::Ok->value, [], true);
    }

    public function isAlreadySent(): bool
    {
        return $this->alreadySent;
    }
    
    public static function json(array $data, int $statusCode = HttpStatus::Ok->value): self
    {
        $encoded = json_encode(
            $data,
            \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
        );
        return new self(
            content: $encoded,
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json']
        );
    }
    
    public static function text(string $content, int $statusCode = HttpStatus::Ok->value): self
    {
        return new self(
            content: $content,
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/plain']
        );
    }
    
    public static function html(string $content, int $statusCode = HttpStatus::Ok->value): self
    {
        return new self(
            content: $content,
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/html; charset=utf-8']
        );
    }
    
    public static function notFound(string $message = 'Not Found'): self
    {
        return self::json([
            'error' => 'Not Found',
            'message' => $message
        ], HttpStatus::NotFound->value);
    }
    
    public static function redirect(string $url, int $statusCode = HttpStatus::Found->value): self
    {
        return new self(
            content: '',
            statusCode: $statusCode,
            headers: ['Location' => $url]
        );
    }
    
    public function getContent(): string
    {
        return $this->content;
    }
    
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return a new response with additional headers. Values can be string or string[] (e.g. multiple Set-Cookie).
     */
    public function withHeaders(array $headersToAdd): self
    {
        $merged = $this->headers;
        foreach ($headersToAdd as $name => $value) {
            if (is_array($value)) {
                $merged[$name] = array_merge($merged[$name] ?? [], $value);
            } else {
                $merged[$name] = $value;
            }
        }
        return new self($this->content, $this->statusCode, $merged, $this->alreadySent);
    }
}
