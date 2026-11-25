<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfListener;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;

/**
 * A session the visitor did not bring and the request did not use is not
 * stored and sets no cookie (idea-anonymous-error-responses-mint-session-cookies).
 *
 * Until this, every response wrote a session row and sent `semitexa_session`
 * and `XSRF-TOKEN`: an anonymous 404, a 401 of a webhook route, a scanner's
 * every probe. On the Swoole table (4096 rows by default) a flood of those
 * evicted the sessions of signed-in visitors. Runs on the real table handler,
 * so "no row" is counted on the store that has a capacity.
 */
final class AnUnusedGuestSessionIsNotStoredTest extends TestCase
{
    private const KNOWN = '0123456789abcdef0123456789abcdef';

    /** As in SessionPhaseStrictSessionIdTest: the slots execute() writes outside a coroutine. */
    private const SLOTS = ['core.csrf_session', DefaultTenantContextStore::CONTEXT_KEY];

    private string|false $redisHostBefore = false;

    /** @var array<string, mixed> */
    private array $slotsBefore = [];

    protected function setUp(): void
    {
        $this->slotsBefore = [];
        foreach (self::SLOTS as $slot) {
            if (CoroutineLocal::has($slot)) {
                $this->slotsBefore[$slot] = CoroutineLocal::get($slot);
            }
        }
        $this->redisHostBefore = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
    }

    protected function tearDown(): void
    {
        $this->redisHostBefore === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST=' . $this->redisHostBefore);
        foreach (self::SLOTS as $slot) {
            if (array_key_exists($slot, $this->slotsBefore)) {
                CoroutineLocal::set($slot, $this->slotsBefore[$slot]);
            } else {
                CoroutineLocal::remove($slot);
            }
        }
    }

    #[Test]
    public function an_anonymous_request_that_touches_nothing_writes_no_row_and_sets_no_cookie(): void
    {
        $table = $this->table();
        [$phase] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $response = $phase->finalize($context, new HttpResponse('not found', 404));

        self::assertSame(0, $table->count(), 'an anonymous error page is not a session');
        self::assertSame([], $this->cookies($response));
    }

    /** Reads are what a guest request does on its way through (auth asks who is signed in). */
    #[Test]
    public function reading_what_an_empty_session_holds_is_not_a_use(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $session = $this->session($scope);
        $session->get('user_id');
        $session->has('user_id');
        $session->getFlash('notice');
        $session->getPayload(UnusedSessionProbe::class);
        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame(0, $table->count());
        self::assertSame([], $this->cookies($response));
    }

    /** An id the store does not know (expired, evicted, invented) is nothing the visitor brought. */
    #[Test]
    public function an_unknown_cookie_on_an_untouched_request_writes_no_row_either(): void
    {
        $table = $this->table();
        [$phase] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request(self::KNOWN));

        $phase->execute($context);
        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame(0, $table->count());
        self::assertSame([], $this->cookies($response));
    }

    #[Test]
    public function a_page_that_renders_the_csrf_field_gets_its_session_and_both_cookies(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $rendered = CsrfField::token();
        $response = $phase->finalize($context, new HttpResponse('<form>'));

        $cookies = $this->cookies($response);
        self::assertNotSame('', $rendered);
        self::assertSame($rendered, $cookies['XSRF-TOKEN'] ?? null, 'the cookie carries the token the form carries');
        self::assertSame($this->session($scope)->getSessionIdForCookie(), $cookies['semitexa_session'] ?? null);
        self::assertTrue($table->exists($cookies['semitexa_session']));
        self::assertSame(1, $table->count());

        // And the stored session holds that token: the form's POST is checked against it.
        [$next, $nextScope] = $this->phase($table);
        $next->execute(new RequestLifecycleContext($this->request($cookies['semitexa_session'])));
        self::assertSame($rendered, $this->session($nextScope)->getPayload(CsrfToken::class)->getValue());
    }

    /** @return iterable<string, array{\Closure(Session): mixed}> */
    public static function uses(): iterable
    {
        yield 'set()' => [static fn (Session $s) => $s->set('cart', 3)];
        yield 'remove()' => [static fn (Session $s) => $s->remove('cart')];
        yield 'forget()' => [static fn (Session $s) => $s->forget('cart')];
        yield 'clear()' => [static fn (Session $s) => $s->clear()];
        yield 'flash()' => [static fn (Session $s) => $s->flash('notice', 'saved')];
        yield 'setPayload()' => [static fn (Session $s) => $s->setPayload(new UnusedSessionProbe())];
        yield 'regenerate()' => [static fn (Session $s) => $s->regenerate()];
        yield 'getId(): something is bound to the id' => [static fn (Session $s) => $s->getId()];
        yield 'the CSRF token read from the session' => [static fn (Session $s) => $s->getPayload(CsrfToken::class)];
    }

    /** @param \Closure(Session): mixed $use */
    #[Test]
    #[DataProvider('uses')]
    public function a_request_that_uses_the_session_stores_it(\Closure $use): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $use($this->session($scope));
        $response = $phase->finalize($context, new HttpResponse('ok'));

        $cookies = $this->cookies($response);
        self::assertSame(1, $table->count());
        // Not the XSRF cookie: clear() drops the token with the rest, as before.
        self::assertSame($this->session($scope)->getSessionIdForCookie(), $cookies['semitexa_session'] ?? null);
    }

    #[Test]
    public function what_set_stored_is_there_on_the_next_request(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $first = new RequestLifecycleContext($this->request());
        $phase->execute($first);
        $this->session($scope)->set('cart', 3);
        $issued = $this->cookies($phase->finalize($first, new HttpResponse('ok')))['semitexa_session'] ?? '';

        [$phase, $scope] = $this->phase($table);
        $phase->execute(new RequestLifecycleContext($this->request($issued)));

        self::assertSame(3, $this->session($scope)->get('cart'));
    }

    /** The sliding lifetime: a session the store holds is written by every request, used or not. */
    #[Test]
    public function a_session_the_store_holds_is_saved_by_a_request_that_touches_nothing(): void
    {
        $table = $this->table();
        $soon = time() + 5;
        $table->set(self::KNOWN, ['data' => '{"user_id":7,"__flash__":[]}', 'expires_at' => $soon]);
        [$phase] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request(self::KNOWN));

        $phase->execute($context);
        $response = $phase->finalize($context, new HttpResponse('ok'));

        $row = $table->get(self::KNOWN);
        self::assertIsArray($row);
        self::assertGreaterThan($soon + 60, $row['expires_at'], 'the row was written again with a full lifetime');
        self::assertStringContainsString('"user_id":7', (string) $row['data']);
        $cookies = $this->cookies($response);
        self::assertSame(self::KNOWN, $cookies['semitexa_session'] ?? null, 'and the cookie is renewed with it');
        self::assertNotSame('', $cookies['XSRF-TOKEN'] ?? '');
    }

    #[Test]
    public function a_sign_in_still_rotates_the_id_and_the_token_and_stores_the_new_session(): void
    {
        $table = $this->table();
        $table->set(self::KNOWN, ['data' => '{"__csrf__":{"value":"before"},"__flash__":[]}', 'expires_at' => time() + 600]);
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request(self::KNOWN));

        $phase->execute($context);
        $session = $this->session($scope);
        $session->set('user_id', 7);
        $session->regenerate();
        $response = $phase->finalize($context, new HttpResponse('signed in'));

        $cookies = $this->cookies($response);
        $newId = $cookies['semitexa_session'] ?? '';
        self::assertTrue(Session::isWellFormedId($newId));
        self::assertNotSame(self::KNOWN, $newId);
        self::assertFalse($table->exists(self::KNOWN), 'the id from before the sign-in is gone');
        self::assertTrue($table->exists($newId));
        self::assertArrayHasKey('XSRF-TOKEN', $cookies);
        self::assertNotSame('', $cookies['XSRF-TOKEN']);
        self::assertNotSame('before', $cookies['XSRF-TOKEN'], 'the token from before the sign-in is not the one sent');
    }

    /** A sign-in on a first request: nothing held, and still stored. */
    #[Test]
    public function a_sign_in_without_a_session_before_it_is_stored(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $this->session($scope)->regenerate();
        $response = $phase->finalize($context, new HttpResponse('signed in'));

        self::assertSame(1, $table->count());
        self::assertTrue($table->exists($this->cookies($response)['semitexa_session'] ?? ''));
    }

    /**
     * The forgery this must not open: a POST carrying a session cookie and no
     * token is refused whether or not the store knows the cookie. The check
     * compares the token and sends it nowhere, so it is not a use: a refused
     * POST with a cookie the store does not know leaves no session behind
     * (review of 39b472b: it stored one per request).
     */
    #[Test]
    #[DataProvider('presentedCookies')]
    public function a_forged_post_that_carries_a_session_cookie_is_still_refused(string $cookie, bool $stored): void
    {
        $table = $this->table();
        if ($stored) {
            $table->set($cookie, ['data' => '{"__csrf__":{"value":"the-real-token"},"__flash__":[]}', 'expires_at' => time() + 600]);
        }
        [$phase, $scope] = $this->phase($table);
        $request = $this->request($cookie, 'POST');
        $context = new RequestLifecycleContext($request);
        $phase->execute($context);

        try {
            $this->csrfCheck($request, $this->session($scope));
            self::fail('a POST with a session cookie and no token went through');
        } catch (AccessDeniedException $e) {
            self::assertSame('CSRF validation failed.', $e->getMessage());
        }

        $cookies = $this->cookies($phase->finalize($context, new HttpResponse('forbidden', 403)));
        // verify:accept-test-change the refusal no longer stores a session for an unknown cookie (review: a row per forged POST); a stored one is still written, as on every request
        self::assertSame($stored ? 1 : 0, $table->count());
        self::assertSame($stored ? ['semitexa_session', 'XSRF-TOKEN'] : [], array_keys($cookies));
        if ($stored) {
            self::assertSame($cookie, $cookies['semitexa_session']);
            self::assertSame('the-real-token', $cookies['XSRF-TOKEN'], 'the stored session keeps its token');
        }
    }

    /** With a token too, as long as it is not the session's: what a script holding an old XSRF-TOKEN cookie sends. */
    #[Test]
    public function a_refused_post_with_a_wrong_token_stores_nothing(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $request = $this->request('x', 'POST', ['X-CSRF-Token' => 'not-the-token']);
        $context = new RequestLifecycleContext($request);
        $phase->execute($context);

        try {
            $this->csrfCheck($request, $this->session($scope));
            self::fail('a POST with a wrong token went through');
        } catch (AccessDeniedException $e) {
            self::assertSame('CSRF validation failed.', $e->getMessage());
        }
        $response = $phase->finalize($context, new HttpResponse('forbidden', 403));

        self::assertSame(0, $table->count());
        self::assertSame([], $this->cookies($response));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function presentedCookies(): iterable
    {
        yield 'a cookie the store holds' => [self::KNOWN, true];
        yield 'a cookie the store does not know' => [self::KNOWN, false];
    }

    #[Test]
    public function a_post_with_the_token_of_its_stored_session_passes(): void
    {
        $table = $this->table();
        $table->set(self::KNOWN, ['data' => '{"__csrf__":{"value":"the-real-token"},"__flash__":[]}', 'expires_at' => time() + 600]);
        [$phase, $scope] = $this->phase($table);
        $request = $this->request(self::KNOWN, 'POST', ['X-CSRF-Token' => 'the-real-token']);
        $phase->execute(new RequestLifecycleContext($request));

        $this->csrfCheck($request, $this->session($scope));

        $this->addToAssertionCount(1);
    }

    /** A machine route: no cookie, so no check, and now no session left behind either. */
    #[Test]
    public function a_post_without_a_session_cookie_is_still_skipped_and_leaves_no_session(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $request = $this->request('', 'POST');
        $context = new RequestLifecycleContext($request);
        $phase->execute($context);

        $this->csrfCheck($request, $this->session($scope));
        $response = $phase->finalize($context, new HttpResponse('unauthorized', 401));

        self::assertSame(0, $table->count());
        self::assertSame([], $this->cookies($response));
    }

    private function csrfCheck(Request $request, Session $session): void
    {
        $listener = new CsrfListener();
        (new \ReflectionProperty(CsrfListener::class, 'session'))->setValue($listener, $session);

        $listener->handle(new RequestPipelineContext(
            requestDto: new \stdClass(),
            route: new DiscoveredRoute(path: '/', methods: [$request->getMethod()], name: 'probe', requestClass: \stdClass::class, responseClass: null, handlers: [], type: 'http-request', transport: null, produces: null, consumes: null, module: 'core'),
            request: $request,
        ));
    }

    private function table(): \Swoole\Table
    {
        $table = new \Swoole\Table(64);
        $table->column('data', \Swoole\Table::TYPE_STRING, 1024);
        $table->column('expires_at', \Swoole\Table::TYPE_INT);
        $table->create();

        return $table;
    }

    private function session(RequestScopedContainer $scope): Session
    {
        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    /** @return array<string, string> the cookies the response sets, by name */
    private function cookies(HttpResponse $response): array
    {
        $cookies = [];
        foreach ((array) ($response->getHeaders()['Set-Cookie'] ?? []) as $line) {
            if (is_string($line) && preg_match('/^([^=]+)=([^;]*)/', $line, $m) === 1) {
                $cookies[$m[1]] = rawurldecode($m[2]);
            }
        }

        return $cookies;
    }

    /** @return array{SessionPhase, RequestScopedContainer} */
    private function phase(\Swoole\Table $table): array
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $scope = new RequestScopedContainer($container);
        $phase = new SessionPhase($container, $scope, new DefaultTenantContextStore(), null);
        (new \ReflectionProperty(SessionPhase::class, 'sessionHandler'))->setValue($phase, new SwooleTableSessionHandler($table));

        return [$phase, $scope];
    }

    /** @param array<string, string> $headers */
    private function request(string $sessionCookie = '', string $method = 'GET', array $headers = []): Request
    {
        return new Request($method, '/', $headers, [], [], ['REQUEST_URI' => '/'], $sessionCookie === '' ? [] : ['semitexa_session' => $sessionCookie]);
    }
}

#[\Semitexa\Core\Session\Attribute\SessionSegment('unused_session_probe')]
final class UnusedSessionProbe
{
    protected string $note = '';

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
    }
}
