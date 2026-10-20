<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/** What came back. A 4xx or 5xx is a response, not an exception: the caller decides. */
final readonly class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers lower-cased names
     */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
        /** The header block as received (status line included), for callers that keep it. */
        public string $rawHeaders = '',
        /** How many times the request was sent, retries included. */
        public int $attempts = 1,
        public float $durationMs = 0.0,
    ) {}

    /** @param array<string, string> $headers */
    public static function make(int $status, string $body = '', array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)][] = $value;
        }

        return new self($status, $body, $normalized);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @throws \JsonException */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function withAttempts(int $attempts, float $durationMs): self
    {
        return new self($this->status, $this->body, $this->headers, $this->rawHeaders, $attempts, $durationMs);
    }
}
