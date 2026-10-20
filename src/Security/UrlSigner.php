<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Request;

/**
 * Links that prove they were made by this application: an unsubscribe link,
 * a download handed out for a day, a confirmation sent by mail.
 *
 * The signature covers the path and every query parameter, so changing any of
 * them breaks it; `expires` (unix seconds) is one of the parameters. The host
 * is not covered: the same link verifies behind any proxy or domain the
 * application answers on. Parameter order and encoding do not matter: both
 * sides compare the query as PHP parses it.
 */
#[AsService]
final class UrlSigner
{
    public const SIGNATURE = 'signature';
    public const EXPIRES = 'expires';
    private const PURPOSE = 'url';

    /** @var list<string>|null */
    private ?array $secrets = null;

    /** @param list<string> $previous */
    public static function withSecrets(string $current, array $previous = []): self
    {
        $signer = new self();
        $signer->secrets = [$current, ...$previous];

        return $signer;
    }

    /**
     * Sign a path (`/unsubscribe?list=news`) or an absolute URL. With a TTL the
     * link stops verifying that many seconds from now.
     */
    public function sign(string $url, ?int $ttlSeconds = null, ?int $now = null): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException("Cannot sign '{$url}': not a URL.");
        }

        // The link keeps the query as written; only expires and signature are
        // replaced. Rebuilding it from parse_str() would rename `a.b` to `a_b`.
        $pairs = array_values(array_filter(
            explode('&', $parts['query'] ?? ''),
            static fn (string $pair): bool => $pair !== ''
                && !in_array(urldecode(explode('=', $pair, 2)[0]), [self::SIGNATURE, self::EXPIRES], true),
        ));
        if ($ttlSeconds !== null) {
            if ($ttlSeconds < 1) {
                throw new \InvalidArgumentException('A signed URL needs a TTL of at least one second.');
            }
            $pairs[] = self::EXPIRES . '=' . (($now ?? time()) + $ttlSeconds);
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        parse_str(implode('&', $pairs), $query);
        $pairs[] = self::SIGNATURE . '=' . $this->signature($this->secrets()[0], $path, $query);

        $prefix = isset($parts['host'])
            ? ($parts['scheme'] ?? 'https') . '://' . (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '') . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';

        return $prefix . $path . '?' . implode('&', $pairs) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public function check(string $path, string $queryString, ?int $now = null): SignatureCheck
    {
        parse_str($queryString, $query);
        $given = $query[self::SIGNATURE] ?? null;
        unset($query[self::SIGNATURE]);
        if (!is_string($given) || $given === '') {
            return SignatureCheck::Invalid;
        }

        $path = $path === '' ? '/' : $path;
        $signed = false;
        foreach ($this->secrets() as $secret) {
            if (hash_equals($this->signature($secret, $path, $query), $given)) {
                $signed = true;
                break;
            }
        }
        if (!$signed) {
            return SignatureCheck::Invalid;
        }

        $expires = $query[self::EXPIRES] ?? null;
        if ($expires !== null && (!is_string($expires) || !ctype_digit($expires) || (int) $expires <= ($now ?? time()))) {
            return SignatureCheck::Expired;
        }

        return SignatureCheck::Valid;
    }

    /** The path the visitor asked for (locale prefix included) and its query. */
    public function checkRequest(Request $request, ?int $now = null): SignatureCheck
    {
        return $this->check($request->getServedPath(), $request->getQueryString(), $now);
    }

    /** @param array<array-key, mixed> $query */
    private function signature(string $secret, string $path, array $query): string
    {
        // Decoded, so `/files/a%20b` and `/files/a b` are one path whichever
        // form the server hands over — except an encoded slash, which the
        // router keeps apart from a real one: `/files/a%2Fb` must not verify
        // as `/files/a/b`, another route.
        $canonicalPath = rawurldecode((string) preg_replace('/%2F/i', '%252F', $path));
        $mac = hash_hmac('sha256', $canonicalPath . '?' . self::canonicalQuery($query), AppSecret::derive($secret, self::PURPOSE), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** @param array<array-key, mixed> $query */
    private static function canonicalQuery(array $query): string
    {
        $sort = static function (array &$value) use (&$sort): void {
            ksort($value, SORT_STRING);
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return non-empty-list<string> */
    private function secrets(): array
    {
        return $this->secrets ?? [AppSecret::current(), ...AppSecret::previous()];
    }
}
