<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Request;

final class ContentNegotiator
{
    /** The MIME a default format key stands for, so a refusal of it can be recognised. */
    private const DEFAULT_MIMES = [
        'json' => 'application/json',
        'html' => 'text/html',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
    ];

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

        // Sorted by q, highest first; equal q keep the order the client wrote.
        $entries = self::parseAcceptHeader($acceptHeader);

        // RFC 9110 §12.5.1: a representation's quality is the q of the MOST
        // SPECIFIC range that matches it — its exact type, then `type/*`, then
        // `*/*` — and q=0 there is a refusal. Reading the entries in q order
        // instead let a high `*/*` pick a type the client had ranked lower by
        // name (`text/html;q=0.1, */*` served html), and a `*/*;q=0` refused
        // nothing at all.
        $quality = static function (string $mime) use ($entries): ?array {
            [$type] = explode('/', $mime, 2);
            $best = null;
            foreach ($entries as $at => [$range, $q]) {
                $specificity = match (true) {
                    $range === $mime => 3,
                    $range === $type . '/*' => 2,
                    $range === '*/*' => 1,
                    default => 0,
                };
                if ($specificity > 0 && ($best === null || $specificity > $best['specificity'])) {
                    $best = ['specificity' => $specificity, 'q' => $q, 'at' => $at];
                }
            }

            return $best;
        };

        // The default is a type too, and a client can refuse it.
        $defaultMime = self::DEFAULT_MIMES[$defaultFormat] ?? null;
        $defaultMatch = $defaultMime !== null ? $quality($defaultMime) : null;
        $defaultRefused = $defaultMatch !== null && $defaultMatch['q'] <= 0.0;

        $unrestricted = $produces === null || $produces === [];
        $candidates = $unrestricted ? self::unrestrictedCandidates($defaultMime, $entries) : $produces;

        $winner = null;
        foreach ($candidates as $order => $mime) {
            $match = $quality($mime);
            if ($match === null || $match['q'] <= 0.0) {
                continue;
            }
            $key = ContentType::toFormatKey($mime) ?? ($defaultRefused ? null : $defaultFormat);
            if ($key === null) {
                continue;
            }
            // Highest quality; then the entry the client listed first; then the
            // route's own order.
            $rank = [$match['q'], -$match['at'], -$order];
            if ($winner === null || $rank > $winner['rank']) {
                $winner = ['rank' => $rank, 'key' => $key];
            }
        }

        if ($winner !== null) {
            return $winner['key'];
        }
        if ($unrestricted && $defaultMime === null) {
            // A default this negotiator cannot name as a type cannot be refused.
            return $defaultFormat;
        }

        throw new NegotiationFailedException($produces ?? [], $acceptHeader);
    }

    /**
     * What a route without `produces` can answer in: its default first, then
     * every known format the client named. The default leads so it wins a tie.
     *
     * @param  list<array{0: string, 1: float}> $entries
     * @return list<string>
     */
    private static function unrestrictedCandidates(?string $defaultMime, array $entries): array
    {
        $candidates = $defaultMime !== null ? [$defaultMime] : [];
        foreach ($entries as [$range]) {
            if (!str_contains($range, '*') && ContentType::toFormatKey($range) !== null && !in_array($range, $candidates, true)) {
                $candidates[] = $range;
            }
        }

        return $candidates;
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
