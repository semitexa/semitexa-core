<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Environment;
use Semitexa\Core\Exception\ServiceUnavailableException;
use Semitexa\Core\Http\SecureCookieMode;
use Semitexa\Core\Http\SecureCookieSetting;
use Semitexa\Core\Locale\DefaultLocaleContext;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Redis\RedisUnavailableException;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SessionStoreFullException;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Session\TransientSessionHandler;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;

/**
 * @internal Initializes session, cookies, and context interfaces; finalizes session after response.
 */
final class SessionPhase
{
    /**
     * Process-wide on purpose: this is a deployment fact, not request state,
     * so it must NOT be a CoroutineLocal. One warning per worker is the
     * whole design.
     */
    private static bool $refusedHttpsWarned = false;
    private static bool $unrecognisedSecureModeWarned = false;
    /** When this worker last said the session table is full; said once a minute, not per request. */
    private static int $storeFullLoggedAt = 0;
    private const STORE_FULL_LOG_INTERVAL = 60;

    private SessionHandlerInterface $sessionHandler;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly TenantContextStoreInterface $tenantContextStore,
        private readonly ?TenancyBootstrapperInterface $tenancy,
    ) {
        $this->sessionHandler = $this->createSessionHandler();
    }

    public function execute(RequestLifecycleContext $context): void
    {
        $request = $context->request;

        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $presentedId = $request->getCookie($cookieName, '');
        $sessionLifetime = (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600');
        try {
            // Strict: the cookie's id is kept only when it is well formed and
            // the store holds it; otherwise the session gets a fresh id.
            $session = Session::open($presentedId, $this->sessionHandler, $cookieName, $sessionLifetime);
        } catch (\Throwable $e) {
            // A coroutine its server cancelled (an SSE stream whose browser left)
            // is not a store that cannot be read: it stops here, unlogged.
            if (self::isCancellation($e)) {
                throw $e;
            }
            // The store is unreadable (Redis down). Serve the request as a guest
            // on a session that persists nothing, rather than failing the page.
            // Whether the store holds the id cannot be asked, and nothing of
            // this session is saved or sent back, so a well-formed id is kept.
            $this->logSessionStoreUnreadable($e, $request);
            $session = new Session(
                Session::isWellFormedId($presentedId) ? $presentedId : Session::generateId(),
                new TransientSessionHandler(),
                $cookieName,
                $sessionLifetime,
            );
        }

        // Ensure a CSRF token exists on this session. Generated once, then stable
        // for the session lifetime. The matching XSRF-TOKEN cookie is emitted in
        // finalize() so browser JS can copy it into the X-CSRF-Token header.
        // Not through getPayload()/setPayload(): seeding the token is not a use
        // of the session (Session::isWorthStoring()).
        $session->ensureCsrfToken();

        $this->requestScopedContainer->set(SessionInterface::class, $session);
        // {{ csrf_field() }} reads this request's token through the session.
        CsrfField::bind($session);
        $this->requestScopedContainer->set(CookieJarInterface::class, new CookieJar($request));
        $this->requestScopedContainer->set(Request::class, $request);

        $this->initContextInterfaces();
    }

    public function finalize(RequestLifecycleContext $context, HttpResponse $response): HttpResponse
    {
        $request = $context->request;

        if (!$this->requestScopedContainer->has(SessionInterface::class)
            || !$this->requestScopedContainer->has(CookieJarInterface::class)
        ) {
            return $response;
        }
        $session = $this->requestScopedContainer->get(SessionInterface::class);
        $cookieJar = $this->requestScopedContainer->get(CookieJarInterface::class);

        if (!$session instanceof Session || !$cookieJar instanceof CookieJarInterface) {
            return $response;
        }

        $cookieName = $session->getCookieName();
        $sessionPersisted = false;
        $sendCookies = false;

        // A transient session (the store was unreadable) is neither saved nor
        // re-issued — a new XSRF cookie would hand open tabs a token the real
        // session, back once the store recovers, does not hold — but every
        // other cookie this request set (locale, a logout clearing one) is
        // still emitted below.
        //
        // Nor is a session the visitor did not bring and the request did not
        // use (Session::isWorthStoring()): no row, and so no cookie, for an
        // anonymous error page or a machine route.
        //
        // Nor is the session of a request whose id a sign-in or a sign-out
        // retired (Session::isOnRetiredId()), before the request opened it
        // or, as save() finds out, while it ran. The request left the browser
        // before the new cookie arrived there, and may be answered after it.
        // Whatever cookie it were sent (its old id again, a fresh session's
        // when it wrote something, an expiry when it did not) would replace
        // the one the other request set. So it is sent none, and with no
        // cookie to find it by, what it wrote is not stored.
        if (!$session->isOnRetiredId() && $session->persists() && $session->isWorthStoring()) {
            $sendCookies = $session->refreshCookieIfDue(
                $request->getCookie($cookieName, ''),
                $request->getCookie('XSRF-TOKEN', ''),
            );
            try {
                $session->save();
                $sessionPersisted = !$session->isOnRetiredId();
            } catch (SessionStoreFullException $e) {
                // A full table is full for a second attempt too, and for every
                // request until rows expire: no retry, and one log line a minute.
                $this->logSessionStoreFull($e, $request);
            } catch (\Throwable $e) {
                $this->logSessionPersistenceFailure($e, $request);
                try {
                    $this->sessionHandler = $this->createSessionHandler();
                    $session->setHandler($this->sessionHandler);
                    $session->save();
                    $sessionPersisted = !$session->isOnRetiredId();
                } catch (\Throwable $retryError) {
                    $this->logSessionPersistenceFailure($retryError, $request);
                }
            }
        }

        $retiredId = $session->isOnRetiredId();

        if ($sessionPersisted && $sendCookies) {
            $baseOptions = $this->sessionCookieOptions($request);

            $cookieJar->set($cookieName, $session->getSessionIdForCookie(), ['httpOnly' => true] + $baseOptions);

            // XSRF-TOKEN cookie for the double-submit CSRF flow. Must NOT be
            // HttpOnly — browser JS has to read it to populate X-CSRF-Token.
            // The session-stored CsrfToken remains the authoritative value; the
            // cookie is only a transport so AJAX clients can echo it back.
            /** @var CsrfToken $csrf */
            $csrf = $session->getPayload(CsrfToken::class);
            if ($csrf->getValue() !== '') {
                $cookieJar->set('XSRF-TOKEN', $csrf->getValue(), ['httpOnly' => false] + $baseOptions);
            }
        }

        // The request presented a session cookie the store does not know
        // (expired, evicted, lost with a restart of the store, invented) and
        // did nothing that makes a session worth storing: tell the browser to
        // drop it, and the XSRF-TOKEN that came with it. Kept, every POST a
        // script sends from that browser carries a session cookie, so it is
        // CSRF-checked against a session that is new each time, and refused
        // until the cookie runs out; without the cookie it is a guest's first
        // request again. No row is written for this. Not during a store outage
        // (a transient session): the cookie may be good once the store is back.
        // Nor for an id retired a moment ago: the browser already holds, or is
        // about to receive, the cookie that replaced it.
        if (!$sessionPersisted && !$retiredId && $session->persists() && !$session->isWorthStoring()) {
            $expired = ['maxAge' => 0, 'expires' => time() - 3600] + $this->sessionCookieOptions($request);
            if ($request->getCookie($cookieName, '') !== '') {
                $cookieJar->set($cookieName, '', ['httpOnly' => true] + $expired);
                if ($request->getCookie('XSRF-TOKEN', '') !== '') {
                    $cookieJar->set('XSRF-TOKEN', '', ['httpOnly' => false] + $expired);
                }
            }
        }

        // A sign-in (or sign-out) regenerated the session and the new one was
        // not stored: the handler's "you are signed in" would be followed by a
        // next request that is not. Say it could not be done, retryably, in
        // place of that answer. Other requests keep their response: an
        // anonymous page is not worth failing because its session found no room.
        if (!$sessionPersisted && $session->persists() && $session->wasRegenerated()) {
            $response = $this->sessionNotStoredResponse($request);
        }

        $lines = $cookieJar->getSetCookieLines();
        if ($lines !== []) {
            /** @var array<int|string, string> $lines */
            $response = $response->withHeaders(['Set-Cookie' => $lines]);
        }

        return $response;
    }

    /**
     * The attributes the session cookie and the XSRF-TOKEN cookie share,
     * whether they are set or expired: a browser drops a cookie only when the
     * expiring one names the same path and domain.
     *
     * @return array{path: string, secure: bool, sameSite: 'lax', maxAge: int, domain?: string}
     */
    private function sessionCookieOptions(Request $request): array
    {
        // Optional parent-domain scope (e.g. ".example.com") so ONE session is
        // shared across sibling hosts of the same site — a public apex and an
        // authenticated subdomain, say. Unset (the default) keeps the cookie
        // host-only, so each host has its own isolated session. Applied to
        // both the session and the XSRF cookie so the CSRF double-submit
        // stays consistent across those hosts too.
        $cookieDomain = (string) (Environment::getEnvValue('SESSION_COOKIE_DOMAIN') ?? '');
        $options = [
            'path' => '/',
            'secure' => $this->cookieSecureFlag($request, $request->getScheme() === 'https'),
            'sameSite' => 'lax',
            'maxAge' => (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600'),
        ];
        if ($cookieDomain !== '') {
            $options['domain'] = $cookieDomain;
        }

        return $options;
    }

    /** The 503 of {@see ServiceUnavailableException}, built here because finalize() runs past the exception mapper. */
    private function sessionNotStoredResponse(Request $request): HttpResponse
    {
        $failure = new ServiceUnavailableException('The session could not be stored, try again in a moment.');
        $status = $failure->getStatusCode()->value;
        $response = str_contains(strtolower($request->getHeader('Accept') ?? ''), 'json')
            ? HttpResponse::json([
                'error' => $failure->getErrorCode(),
                'message' => $failure->getMessage(),
                'context' => $failure->getErrorContext(),
            ], $status)
            : HttpResponse::text($failure->getMessage(), $status);

        return $response->withHeaders($failure->headers());
    }

    private function logSessionStoreFull(SessionStoreFullException $e, Request $request): void
    {
        $now = time();
        if ($now - self::$storeFullLoggedAt < self::STORE_FULL_LOG_INTERVAL) {
            return;
        }
        self::$storeFullLoggedAt = $now;

        $this->logSessionPersistenceFailure($e, $request);
    }

    private function createSessionHandler(): SessionHandlerInterface
    {
        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost !== null && $redisHost !== '') {
            if ($this->container->has(RedisConnectionPool::class)) {
                $pool = $this->container->get(RedisConnectionPool::class);
                if ($pool instanceof RedisConnectionPool) {
                    return new RedisSessionHandler($pool);
                }
            }
            throw new \RuntimeException(
                'Redis is configured (REDIS_HOST is set) but no RedisConnectionPool '
                . 'was registered during container bootstrap. Ensure Redis is properly '
                . 'configured in the application setup.'
            );
        }
        return new SwooleTableSessionHandler();
    }

    /**
     * Whether the session and XSRF cookies get `Secure`.
     *
     * SESSION_COOKIE_SECURE overrides the scheme:
     *   auto (default) — follow the request scheme, as before;
     *   always         — an HTTPS-only deployment says so and stops depending
     *                    on scheme detection working;
     *   never          — plain HTTP on purpose, e.g. a local box behind a VPN.
     *
     * The warning is the point of this method. Dropping Secure because an
     * untrusted peer claimed https is a DOWNGRADE, and until now it happened
     * without a word: core#102 already recorded that the project's own
     * containerised topology puts the proxy off loopback, TRUSTED_PROXIES was
     * added as the remedy, it reached no shipped .env, and semitexa.com was
     * still serving Secure-less cookies over HTTPS months later. Nothing was
     * broken enough to notice, which is exactly why it needs to say something.
     */
    private function cookieSecureFlag(Request $request, bool $isHttps): bool
    {
        // One reader of this variable, shared with the doctor check that tells
        // an operator what it will do. Two lists of accepted spellings had
        // already drifted apart once.
        $setting = SecureCookieSetting::fromEnvironment();

        // A spelling nobody declared — `alway` — behaved as `auto`, which on a
        // site behind an untrusted proxy means no Secure flag at all. Silently
        // honouring a typo is the failure this setting exists to prevent, and a
        // typo is not a reason to refuse to boot, so it is said once and then
        // treated as auto.
        if (!$setting->recognised) {
            $this->warnAboutUnrecognisedSecureMode($setting->raw);
        }

        $secure = $setting->secureFor($isHttps);

        // ONLY IN AUTO. The warning says an untrusted proxy cost the Secure flag
        // and recommends TRUSTED_PROXIES — true when the scheme decides, and
        // false when the operator turned the flag off: secureFor() ignores the
        // detected scheme in Never mode, so trusting the proxy would change
        // nothing and the remedy would send a reader to fix the wrong thing.
        // The disabling values are reported where they belong, by
        // ForwardedProxyDoctorCheck, which fails them outright on an https
        // deployment.
        if ($setting->mode === SecureCookieMode::Auto
            && !$secure
            && $request->refusedForwardedProto() === 'https'
        ) {
            $this->warnAboutRefusedHttps($request);
        }

        return $secure;
    }

    /**
     * Once per worker, for the same reason as the proxy warning below: a value
     * in the environment is wrong for every request or none of them.
     */
    private function warnAboutUnrecognisedSecureMode(string $configured): void
    {
        if (self::$unrecognisedSecureModeWarned) {
            return;
        }
        self::$unrecognisedSecureModeWarned = true;

        $this->warn(
            sprintf(
                'SESSION_COOKIE_SECURE is set to "%s", which is not a value this framework defines. '
                . 'Falling back to auto, which decides from the request scheme — behind a proxy that '
                . 'is not trusted, that means the session and XSRF cookies go out WITHOUT Secure.',
                $configured,
            ),
            ['remedy' => 'Use one of: ' . SecureCookieSetting::documentedValues() . '.'],
        );
    }

    /**
     * Once per worker. A misconfigured proxy is true for every request, so one
     * line per boot says it; one line per request buries it.
     */
    private function warnAboutRefusedHttps(Request $request): void
    {
        if (self::$refusedHttpsWarned) {
            return;
        }
        self::$refusedHttpsWarned = true;

        $this->warn(
            'A proxy said X-Forwarded-Proto: https and was not trusted, so the session '
            . 'and XSRF cookies are being sent WITHOUT the Secure flag.',
            [
                'remote_addr' => $request->getServer('remote_addr'),
                'remedy' => 'Add that address to TRUSTED_PROXIES, or set SESSION_COOKIE_SECURE=always.',
            ],
        );
    }

    /**
     * A warning that survives having no logger.
     *
     * Both cookie warnings fire during boot-shaped work, where the container
     * may not carry a logger yet — and a warning about cookies going out
     * unprotected is the last thing that should disappear because the thing
     * meant to record it is missing.
     *
     * @param array<string, mixed> $context
     */
    private function warn(string $message, array $context): void
    {
        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $logger->warning($message, $context);

            return;
        }

        FallbackErrorLogger::log($message, $context);
    }

    /**
     * By exact type through the previous-chain, as semitexa-ssr's
     * SseSessionCoroutines::isCancellation() does. Degraded to a guest, a
     * cancelled request ran on and read as a broken session store (87 such
     * warnings in one consumer's E2E run, 2026-11-16).
     */
    private static function isCancellation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \Swoole\Coroutine\CanceledException) {
                return true;
            }
        }

        return false;
    }

    /**
     * The circuit breaker already said Redis is down, once, when it opened; a
     * call it refused is not news, so only a first-hand failure is logged here.
     */
    private function logSessionStoreUnreadable(\Throwable $e, Request $request): void
    {
        if ($e instanceof RedisUnavailableException) {
            return;
        }

        $this->warn('Session store unreadable; serving the request with a transient guest session', [
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function logSessionPersistenceFailure(\Throwable $e, Request $request): void
    {
        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $logger->error('Session persistence failed', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } else {
            FallbackErrorLogger::log('Session persistence failed', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize request-scoped context interfaces (Tenant, Auth, Locale).
     */
    private function initContextInterfaces(): void
    {
        if ($this->tenancy === null || !$this->tenancy->isEnabled()) {
            $this->tenantContextStore->clear();
        }

        $tenantContext = $this->resolveTenantContext();
        $this->requestScopedContainer->set(TenantContextInterface::class, $tenantContext);

        // AuthContextInterface is supplied by semitexa-auth through a SatisfiesServiceContract
        // binding. When the auth package is not installed, fall back to the Core-owned guest
        // context so downstream consumers always receive a non-null auth context.
        $authContext = $this->container->has(AuthContextInterface::class)
            ? $this->container->get(AuthContextInterface::class)
            : $this->resolveLegacyAuthContext();
        /** @var AuthContextInterface $authContext */
        $this->requestScopedContainer->set(AuthContextInterface::class, $authContext);

        $localeContext = DefaultLocaleContext::getInstance();
        $this->requestScopedContainer->set(LocaleContextInterface::class, $localeContext);
    }

    private function resolveTenantContext(): TenantContextInterface
    {
        $tenantContext = $this->tenantContextStore->tryGet();
        if ($tenantContext instanceof TenantContextInterface) {
            return $tenantContext;
        }

        if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
            $legacyStoreClass = 'Semitexa\\Tenancy\\Context\\CoroutineContextStore';
            try {
                $legacyTenantContext = $legacyStoreClass::get();
                if ($legacyTenantContext instanceof TenantContextInterface) {
                    $this->tenantContextStore->set($legacyTenantContext);

                    return $legacyTenantContext;
                }
            } catch (\Throwable) {
                // Mixed-version fallback: ignore missing legacy store and keep the new store value.
            }
        }

        return $this->tenantContextStore->get();
    }

    private function resolveLegacyAuthContext(): AuthContextInterface
    {
        if ($this->container->has(\Semitexa\Auth\Context\AuthManager::class)) {
            $legacyAuthContext = $this->container->get(\Semitexa\Auth\Context\AuthManager::class);
            if ($legacyAuthContext instanceof AuthContextInterface) {
                return $legacyAuthContext;
            }
        }

        return GuestAuthContext::getInstance();
    }
}
