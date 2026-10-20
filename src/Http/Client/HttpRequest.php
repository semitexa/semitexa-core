<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/**
 * One outbound request. The method is upper-cased; header names keep their
 * case and a replacement matches any case. A CR, LF or NUL in a header — or a
 * colon in its name — is refused: it would write a header of its own.
 */
final readonly class HttpRequest
{
    public string $method;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
    ) {
        $this->method = strtoupper($method);
        if (preg_match('/^[A-Z]+$/', $this->method) !== 1) {
            throw new \InvalidArgumentException('An HTTP method is letters only.');
        }
        foreach ($headers as $name => $values) {
            self::assertHeader((string) $name, $values);
        }
    }

    public static function make(string $method, string $url): self
    {
        return new self($method, $url);
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

    /** @param list<string> $values */
    private static function assertHeader(string $name, array $values): void
    {
        if ($name === '' || preg_match('/[\x00-\x20:\x7f]/', $name) === 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a header name.', addcslashes($name, "\0..\37")));
        }
        foreach ($values as $value) {
            if (preg_match('/[\r\n\x00]/', (string) $value) === 1) {
                throw new \InvalidArgumentException(sprintf('The value of header "%s" holds a line break or NUL.', $name));
            }
        }
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
