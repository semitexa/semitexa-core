<?php

declare(strict_types=1);

namespace Semitexa\Core\Cookie;

use Semitexa\Core\Request;

/**
 * Cookie jar: reads from Request, collects Set-Cookie lines for Response.
 */
final class CookieJar implements CookieJarInterface
{
    /** @var array<string, string> */
    private array $requestCookies = [];

    /** @var list<string> */
    private array $setCookieLines = [];

    public function __construct(Request $request)
    {
        $this->requestCookies = $request->cookies;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $v = $this->requestCookies[$name] ?? null;
        return $v !== null && $v !== '' ? $v : $default;
    }

    public function has(string $name): bool
    {
        return isset($this->requestCookies[$name]) && $this->requestCookies[$name] !== '';
    }

    public function set(string $name, string $value, array $options = []): void
    {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];

        if (isset($options['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', (int) $options['expires']);
        }
        if (isset($options['maxAge'])) {
            $parts[] = 'Max-Age=' . (int) $options['maxAge'];
        }
        $parts[] = 'Path=' . ($options['path'] ?? '/');
        if (isset($options['domain'])) {
            $parts[] = 'Domain=' . $options['domain'];
        }
        if (!empty($options['secure'])) {
            $parts[] = 'Secure';
        }
        if (!empty($options['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }
        if (isset($options['sameSite'])) {
            $parts[] = 'SameSite=' . $options['sameSite'];
        }

        $this->setCookieLines[] = implode('; ', $parts);
        \Semitexa\Core\Debug\SessionDebugLog::log('CookieJar.set', [
            'cookie_name' => $name,
            'jar_size_after' => count($this->setCookieLines),
        ]);
    }

    public function remove(string $name, string $path = '/', ?string $domain = null): void
    {
        $options = [
            'path' => $path,
            'maxAge' => 0,
            'expires' => time() - 3600,
        ];
        if ($domain !== null) {
            $options['domain'] = $domain;
        }
        $this->set($name, '', $options);
    }

    public function getSetCookieLines(): array
    {
        return $this->setCookieLines;
    }
}
