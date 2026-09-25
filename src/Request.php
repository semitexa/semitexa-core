<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Http\RequestTarget;
use Semitexa\Core\Http\UploadedFile;

/**
 * HTTP Request representation
 *
 * @phpstan-type Headers array<string, string>
 * @phpstan-type QueryArray array<array-key, string|array<mixed>>
 * @phpstan-type PostArray array<array-key, string|array<mixed>>
 * @phpstan-type ServerArray array<string, mixed>
 * @phpstan-type CookieArray array<string, string>
 * @phpstan-type FilesArray array<string, UploadedFile|list<UploadedFile>>
 */
readonly class Request
{
    /**
     * @param Headers     $headers
     * @param QueryArray  $query
     * @param PostArray   $post
     * @param ServerArray $server
     * @param CookieArray $cookies
     * @param FilesArray  $files
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers,
        public array $query,
        public array $post,
        public array $server,
        public array $cookies,
        public ?string $content = null,
        public array $files = [],
        /**
         * Per-request strict hydration flag. When true, PayloadHydrator rejects
         * values that cannot be meaningfully coerced (throws TypeMismatchException)
         * instead of silently casting. This is an INTERNAL flag set by the test
         * transport (semitexa-testing InProcessTransport) — it is NOT derived from
         * any request header, so untrusted clients cannot toggle validation
         * behavior over the wire. Production requests always carry false.
         */
        public bool $strictHydration = false,
    ) {}

    /**
     * Create Request using Factory (recommended)
     */
    public static function create(mixed $source = null): self
    {
        return RequestFactory::create($source);
    }


    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return RequestTarget::path($this->uri) ?? '/';
    }

    /**
     * The path the client actually asked for, before anything rewrote it.
     *
     * `getPath()` answers what the ROUTER works on, and the two part company
     * the moment something ahead of the handler rebases the request — today
     * that is the locale layer stripping a URL prefix, so `/ka/gallery` routes
     * as `/gallery`. That rewrite is right for matching a pattern and wrong
     * for anything that tells the visitor where they are: `/gallery` under
     * `LOCALE_URL_PREFIX=true` IS the default language, so handing it back as
     * the current address turns the next reload into a different language.
     *
     * Read from `$server['request_uri']`, which `withPath()` copies verbatim
     * for exactly this reason. Falls back to the routed path when a request
     * carries no server entry — a hand-built one in a test, a replayed one —
     * where the two are the same by construction anyway.
     */
    public function getServedPath(): string
    {
        $servedUri = $this->server['request_uri'] ?? $this->server['REQUEST_URI'] ?? null;

        // RequestTarget::path() is null for an empty or pathless target, which
        // falls back to the routed path exactly as the old guards did.
        return (is_string($servedUri) ? RequestTarget::path($servedUri) : null) ?? $this->getPath();
    }

    /**
     * The query string of this request, however it arrived.
     *
     * Under Swoole the uri is built from `request_uri`, which carries the path
     * and nothing else — the parameters come separately, in `get`. So parsing
     * the uri found nothing and this returned '' for every real request on a
     * Swoole server, which is most of them.
     *
     * That was invisible until something built a URL out of it. Both locale
     * redirects did: `/en/gallery?sort=price_asc` answered with a Location of
     * `/gallery`, quietly dropping the filter the visitor had applied, and a
     * bookmark with a month in it lost the month. Falling back to the parsed
     * parameters keeps the promise the method's name makes.
     */
    public function getQueryString(): string
    {
        $fromUri = parse_url($this->uri, PHP_URL_QUERY) ?: '';

        if ($fromUri !== '') {
            return $fromUri;
        }

        return $this->query === [] ? '' : http_build_query($this->query);
    }

    /**
     * The same request addressed by another path, query string preserved.
     *
     * Used when something ahead of the handler rewrites the path the router
     * works on — a locale prefix being stripped, say. Path parameters are
     * hydrated by re-matching the route pattern against the request's own
     * path, so a rewritten route path that is not carried on the request
     * leaves every parameter null.
     *
     * $server is copied verbatim: REQUEST_URI still holds what the client
     * actually asked for, which is what a language switcher or an access log
     * needs to see.
     */
    public function withPath(string $path): self
    {
        if ($path === $this->getPath()) {
            return $this;
        }

        $queryString = $this->getQueryString();

        return new self(
            method: $this->method,
            uri: $queryString !== '' ? $path . '?' . $queryString : $path,
            headers: $this->headers,
            query: $this->query,
            post: $this->post,
            server: $this->server,
            cookies: $this->cookies,
            content: $this->content,
            files: $this->files,
            strictHydration: $this->strictHydration,
        );
    }

    public function getHeader(string $name): ?string
    {
        // Try exact match first
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }

        // Try case-insensitive match
        $nameLower = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return $value;
            }
        }

        return null;
    }

    public function getHost(): string
    {
        $hostHeader = trim($this->getHeader('Host') ?? '');
        if ($hostHeader === '') {
            return '';
        }

        $hostParts = explode(',', $hostHeader);
        $host = trim($hostParts[0]);
        if ($host === '') {
            return '';
        }

        if (preg_match('/[\s@\/\\\\?#]/', $host) === 1) {
            return '';
        }

        $parsedHost = parse_url('http://' . $host, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            return strtolower($parsedHost);
        }

        return '';
    }

    public function getScheme(): string
    {
        $schemeHeader = trim($this->getHeader('X-Forwarded-Proto') ?? '');
        if ($schemeHeader !== '' && $this->isTrustedForwardedRequest()) {
            $schemeParts = array_values(array_filter(array_map(
                static fn (string $value): string => strtolower(trim($value)),
                explode(',', $schemeHeader)
            )));
            $forwardedScheme = $schemeParts[0] ?? '';
            if ($forwardedScheme === 'http' || $forwardedScheme === 'https') {
                return $forwardedScheme;
            }
        }

        $https = strtolower($this->getServer('https'));
        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        return 'http';
    }

    /**
     * The scheme a proxy claimed and this request refused to believe, if any.
     *
     * Refusing an untrusted X-Forwarded-Proto is correct — a peer that may not
     * speak for the client must not choose the scheme. Refusing it SILENTLY is
     * not, and that is the shape of the defect this exists to make audible:
     * core#102 recorded that the containerised topology the project's own
     * compose files ship puts the reverse proxy off loopback, so the header is
     * dropped and the session cookie loses Secure. The knob (TRUSTED_PROXIES)
     * was added; nothing tells an operator it is needed, and semitexa.com was
     * still serving Secure-less cookies over HTTPS on 2026-09-18.
     *
     * Returns the refused value ('https' or 'http') so a caller can say which
     * decision it is about to make differently, or null when there is nothing
     * to report — no header, or a peer that is trusted and was believed.
     */
    public function refusedForwardedProto(): ?string
    {
        $header = trim($this->getHeader('X-Forwarded-Proto') ?? '');
        if ($header === '' || $this->isTrustedForwardedRequest()) {
            return null;
        }

        $first = strtolower(trim(explode(',', $header)[0]));

        return $first === 'http' || $first === 'https' ? $first : null;
    }

    public function getOrigin(): string
    {
        $host = $this->getHost();
        if ($host === '') {
            return '';
        }

        return $this->getScheme() . '://' . $host;
    }

    /**
     * Whether the peer that handed us this request may speak for the client.
     *
     * Loopback is always trusted — a proxy on the same host. Anything else is
     * trusted only when the operator lists it in TRUSTED_PROXIES (comma-
     * separated IPs and/or CIDR blocks, e.g. `172.18.0.0/16, 10.0.0.5`): in
     * the containerised topology the compose files themselves ship, the
     * reverse proxy is a sibling container on a bridge network, so its
     * X-Forwarded-Proto used to be silently dropped and every URL built from
     * the request came out http:// on an https-only site — and the session
     * cookie lost its Secure flag the same way (#102). The default stays
     * loopback-only: trusting private ranges implicitly would let any
     * container on the bridge spoof the scheme.
     */
    private function isTrustedForwardedRequest(): bool
    {
        $remoteAddr = strtolower(trim($this->getServer('remote_addr')));

        if ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || $remoteAddr === 'localhost') {
            return true;
        }

        foreach (explode(',', (string) Environment::getEnvValue('TRUSTED_PROXIES', '')) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && self::ipMatchesEntry($remoteAddr, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** One TRUSTED_PROXIES entry — a bare IP or a CIDR block, IPv4 or IPv6. */
    /**
     * Could this TRUSTED_PROXIES entry ever match anything?
     *
     * The shape check on its own, so a diagnostic can ask the question without
     * inventing a second parser: `not-an-ip` and `172.18.0.0/99` are silently
     * inert here — they match no peer, ever — and a doctor check that only
     * counted the entries reported such a list as healthy while the app went on
     * dropping X-Forwarded-Proto.
     *
     * Shape only. Whether the entry names the RIGHT network is a question
     * nothing but the deployment can answer.
     */
    public static function isUsableTrustedProxyEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return @inet_pton($entry) !== false;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || !ctype_digit(trim($bits))) {
            return false;
        }

        $bits = (int) trim($bits);

        return $bits >= 0 && $bits <= strlen($subnetBin) * 8;
    }

    private static function ipMatchesEntry(string $ip, string $entry): bool
    {
        // One reader of what an entry may look like, shared with the doctor
        // check that reports on it.
        if (!self::isUsableTrustedProxyEntry($entry)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (!str_contains($entry, '/')) {
            $entryBin = @inet_pton($entry);

            return $entryBin !== false && $entryBin === $ipBin;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin) || !ctype_digit(trim($bits))) {
            return false;
        }
        $bits = (int) trim($bits);
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return ((ord($ipBin[$fullBytes]) ^ ord($subnetBin[$fullBytes])) & $mask) === 0;
    }

    public function getQuery(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function getPost(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function getServer(string $key, string $default = ''): string
    {
        $normalizedKey = strtolower($key);

        if (isset($this->server[$normalizedKey])) {
            return $this->normalizeServerValue($this->server[$normalizedKey], $default);
        }

        if (isset($this->server[$key])) {
            return $this->normalizeServerValue($this->server[$key], $default);
        }

        foreach ($this->server as $serverKey => $value) {
            if (strtolower((string) $serverKey) === $normalizedKey) {
                return $this->normalizeServerValue($value, $default);
            }
        }

        return $default;
    }

    private function normalizeServerValue(mixed $value, string $default): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    public function getCookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    public function isPut(): bool
    {
        return $this->isMethod('PUT');
    }

    public function isDelete(): bool
    {
        return $this->isMethod('DELETE');
    }

    public function isAjax(): bool
    {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }

    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type');
        return $contentType !== null && str_contains(strtolower($contentType), 'application/json');
    }

    public function isXml(): bool
    {
        $ct = $this->getHeader('Content-Type');
        if ($ct === null) return false;
        $lower = strtolower($ct);
        return str_contains($lower, 'application/xml') || str_contains($lower, 'text/xml');
    }

    /**
     * Get parsed JSON body as array (object → assoc, array → list).
     *
     * @return array<int|string, mixed>|null
     */
    public function getJsonBody(): ?array
    {
        if (!$this->isJson() || !$this->content) {
            return null;
        }

        $data = json_decode($this->content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function hasFile(string $field): bool
    {
        return array_key_exists($field, $this->files);
    }

    /**
     * Returns the first uploaded file at $field, or null when no file was sent.
     * Use {@see getFiles} when the field is a multi-file input.
     */
    public function getFile(string $field): ?UploadedFile
    {
        $entry = $this->files[$field] ?? null;
        if ($entry instanceof UploadedFile) {
            return $entry;
        }
        if (is_array($entry) && $entry !== []) {
            $first = $entry[0] ?? null;
            return $first instanceof UploadedFile ? $first : null;
        }
        return null;
    }

    /**
     * Returns every uploaded file at $field as a normalized list. Single-file
     * fields collapse into a one-element list.
     *
     * @return list<UploadedFile>
     */
    public function getFiles(string $field): array
    {
        $entry = $this->files[$field] ?? null;
        if ($entry instanceof UploadedFile) {
            return [$entry];
        }
        if (is_array($entry)) {
            $list = [];
            foreach ($entry as $candidate) {
                if ($candidate instanceof UploadedFile) {
                    $list[] = $candidate;
                }
            }
            return $list;
        }
        return [];
    }
}
