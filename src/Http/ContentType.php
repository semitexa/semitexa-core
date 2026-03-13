<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

readonly class ContentType
{
    public function __construct(
        public string $type,
        public string $subtype,
        public string $full,
        public array  $params,
    ) {}

    /**
     * Parse a Content-Type or single Accept entry.
     * Returns null for missing/malformed values.
     */
    public static function parse(?string $header): ?self
    {
        if ($header === null || $header === '') {
            return null;
        }

        $segments = explode(';', $header);
        $mediaType = strtolower(trim($segments[0]));

        if (!str_contains($mediaType, '/')) {
            return null;
        }

        [$type, $subtype] = explode('/', $mediaType, 2);

        $params = [];
        for ($i = 1, $count = count($segments); $i < $count; $i++) {
            $kv = explode('=', trim($segments[$i]), 2);
            if (count($kv) === 2) {
                $params[trim($kv[0])] = trim($kv[1], ' "');
            }
        }

        return new self($type, $subtype, $mediaType, $params);
    }

    /** Resolve a MIME string to a framework format key. */
    public static function toFormatKey(string $mime): ?string
    {
        return match (strtolower($mime)) {
            'application/json', 'application/ld+json' => 'json',
            'application/xml', 'text/xml'             => 'xml',
            'text/html'                               => 'html',
            'text/plain'                              => 'txt',
            'application/x-www-form-urlencoded',
            'multipart/form-data'                     => 'form',
            default                                   => null,
        };
    }

    public function formatKey(): ?string
    {
        return self::toFormatKey($this->full);
    }
}
