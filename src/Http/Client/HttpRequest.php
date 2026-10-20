<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/** One outbound request. Header names keep their case; a replacement matches any case. */
final readonly class HttpRequest
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
    ) {}

    public static function make(string $method, string $url): self
    {
        return new self(strtoupper($method), $url);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = [$value];

        return new self($this->method, $this->url, $headers, $this->body);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $request = $this;
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function withBody(string $body): self
    {
        return new self($this->method, $this->url, $this->headers, $body);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $existing => $values) {
            if (strcasecmp($existing, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
