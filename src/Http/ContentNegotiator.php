<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Request;

final class ContentNegotiator
{
    /**
     * Check if the request's Content-Type is accepted by this route.
     *
     * @param list<string>|null $consumes  MIME types from AsPayload::consumes (null = accept all)
     * @return true|string  true if accepted, or the unsupported Content-Type string
     */
    public static function checkConsumes(?array $consumes, Request $request): true|string
    {
        if ($consumes === null || $consumes === []) {
            return true;
        }

        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $ct = ContentType::parse($request->getHeader('Content-Type'));
        if ($ct === null) {
            return '(missing)';
        }

        if (in_array($ct->full, $consumes, true)) {
            return true;
        }

        return $ct->full;
    }

    /**
     * Negotiate response format from Accept header / _format query param.
     *
     * @param list<string>|null $produces  MIME types from AsResource::produces (null = no restrictions)
     * @return string Format key: 'json', 'html', 'xml', 'txt'
     * @throws NegotiationFailedException when produces is set but no match found (-> 406)
     */
    public static function negotiateResponseFormat(
        ?array $produces,
        Request $request,
        string $defaultFormat = 'json',
    ): string {
        $formatOverride = $request->query['_format'] ?? null;
        if ($formatOverride !== null && in_array($formatOverride, ['json', 'html', 'xml', 'txt'], true)) {
            if ($produces !== null && $produces !== []) {
                $overrideMime = self::formatKeyToMime($formatOverride);
                if (!in_array($overrideMime, $produces, true)) {
                    throw new NegotiationFailedException($produces, $overrideMime);
                }
            }
            return $formatOverride;
        }

        $acceptHeader = $request->getHeader('Accept');
        if ($acceptHeader === null || $acceptHeader === '' || $acceptHeader === '*/*') {
            if ($produces !== null && $produces !== []) {
                return ContentType::toFormatKey($produces[0]) ?? $defaultFormat;
            }
            return $defaultFormat;
        }

        $entries = self::parseAcceptHeader($acceptHeader);

        // q=0 is a refusal, not an absent entry: a wildcard alongside it must
        // not hand back the very type the client ruled out.
        $refused = [];
        foreach ($entries as $i => [$mime, $q]) {
            if ($q <= 0.0) {
                $refused[$mime] = true;
                unset($entries[$i]);
            }
        }
        $accepted = [];
        foreach ($entries as [$mime]) {
            $accepted[$mime] = true;
        }
        $isRefused = static function (string $produce) use ($refused, $accepted): bool {
            if (isset($refused[$produce])) {
                return true;
            }
            // A refused range (`text/*;q=0`) covers the type unless the client
            // named it exactly with a non-zero q, the more specific entry.
            [$type] = explode('/', $produce, 2);

            return isset($refused[$type . '/*']) && !isset($accepted[$produce]);
        };

        if ($produces === null || $produces === []) {
            foreach ($entries as [$mime, $q]) {
                $key = ContentType::toFormatKey($mime);
                if ($key !== null) {
                    return $key;
                }
            }
            return $defaultFormat;
        }

        foreach ($entries as [$mime, $q]) {
            if ($mime === '*/*') {
                foreach ($produces as $produce) {
                    if (!$isRefused($produce)) {
                        return ContentType::toFormatKey($produce) ?? $defaultFormat;
                    }
                }
                continue;
            }
            if (str_ends_with($mime, '/*')) {
                [$type] = explode('/', $mime, 2);
                foreach ($produces as $produce) {
                    if (str_starts_with($produce, $type . '/') && !$isRefused($produce)) {
                        return ContentType::toFormatKey($produce) ?? $defaultFormat;
                    }
                }
                continue;
            }
            if (in_array($mime, $produces, true) && !$isRefused($mime)) {
                return ContentType::toFormatKey($mime) ?? $defaultFormat;
            }
        }

        throw new NegotiationFailedException($produces, $acceptHeader);
    }

    /**
     * Parse Accept header into sorted [(mime, q)] pairs, refusals (q=0) included.
     *
     * @return list<array{0: string, 1: float}>
     */
    private static function parseAcceptHeader(string $header): array
    {
        $entries = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $segments = explode(';', $part);
            $mime = strtolower(trim($segments[0]));
            $q = 1.0;

            for ($i = 1, $n = count($segments); $i < $n; $i++) {
                $kv = explode('=', trim($segments[$i]), 2);
                if (count($kv) === 2 && strtolower(trim($kv[0])) === 'q') {
                    $q = max(0.0, min(1.0, (float) trim($kv[1])));
                }
            }

            $entries[] = [$mime, $q];
        }

        usort($entries, static fn($a, $b) => $b[1] <=> $a[1]);

        return $entries;
    }

    private static function formatKeyToMime(string $format): string
    {
        return match ($format) {
            'json' => 'application/json',
            'html' => 'text/html',
            'xml'  => 'application/xml',
            'txt'  => 'text/plain',
            default => 'application/json',
        };
    }
}
