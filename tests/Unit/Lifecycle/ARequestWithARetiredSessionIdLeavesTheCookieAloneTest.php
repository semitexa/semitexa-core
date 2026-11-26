<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;

/**
 * A request sent with the session cookie from before a sign-in, answered
 * after it, does not sign the visitor out (tk-session-regenerate-race-signs-out).
 *
 * regenerate() destroyed the old id at once. A poll or a stream reconnecting
 * that left the browser before the sign-in's response arrived then presented
 * an id the store did not know, and was answered with an expiring cookie (a
 * fresh guest cookie before 2026.11.25.1338). Reaching the browser after the
 * sign-in's response, that dropped the new session cookie.
 *
 * The old id is now marked as retired for a few seconds, and a request that
 * presents a marked id is sent no session cookie. The marker is not a way
 * into the new session: the tests below read the stored row and what the old
 * id opens.
 *
 * And the request that opened the session before the sign-in (or sign-out)
 * saved, and saves after it, no longer writes the old session back over the
 * marker (tk-session-inflight-request-rewrites-a-retired-session).
 */
final class ARequestWithARetiredSessionIdLeavesTheCookieAloneTest extends TestCase
{
    private const BEFORE_SIGN_IN = '0123456789abcdef0123456789abcdef';

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
    public function a_request_with_the_old_cookie_right_after_the_sign_in_is_sent_no_cookie(): void
    {
        $table = $this->table();
        [$newId, $newToken] = $this->signIn($table);
        $newRow = $table->get($newId);

        [$phase] = $this->phase($table);
        $late = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN, ['XSRF-TOKEN' => 'the-token-from-before']));
        $phase->execute($late);
        $response = $phase->finalize($late, new HttpResponse('{"new":0}', 200, ['Content-Type' => 'application/json']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['Content-Type' => 'application/json'], $response->getHeaders(), 'the answer as the handler made it');
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders(), 'nothing that could replace the cookie the sign-in set');
        self::assertSame($newRow, $table->get($newId), 'the new session is as the sign-in left it');
        self::assertSame(2, $table->count(), 'the new session and the marker: the late request stored nothing');

        // And the browser's next request, with the new cookie, is still signed in.
        [$phase, $scope] = $this->phase($table);
        $next = new RequestLifecycleContext($this->request($newId));
        $phase->execute($next);
        self::assertSame(7, $this->session($scope)->get('user_id'));
        self::assertSame($newToken, $this->session($scope)->getPayload(CsrfToken::class)->getValue());
        // verify:accept-test-change an ordinary request that already carries the current session cookie no longer re-sends it before half its lifetime has passed (tk-session-late-response-restores-a-retired-cookie)
        self::assertSame([], $this->cookies($phase->finalize($next, new HttpResponse('ok'))));
    }

    /** @return iterable<string, array{\Closure(Session): mixed}> */
    public static function uses(): iterable
    {
        yield 'a write' => [static fn (Session $s) => $s->set('cart', 3)];
        yield 'a flash' => [static fn (Session $s) => $s->flash('notice', 'saved')];
        yield 'the id read' => [static fn (Session $s) => $s->getId()];
        yield 'the CSRF field rendered' => [static fn (Session $s) => CsrfField::token()];
    }

    /**
     * The write case. A session cookie for what this request wrote would be a
     * guest's, and would replace the signed-in one just the same. So none is
     * sent, and with no cookie to find it by, the session is not stored.
     *
     * @param \Closure(Session): mixed $use
     */
    #[Test]
    #[DataProvider('uses')]
    public function a_request_with_the_old_cookie_that_uses_its_session_is_sent_no_cookie_either(\Closure $use): void
    {
        $table = $this->table();
        [$newId] = $this->signIn($table);

        [$phase, $scope] = $this->phase($table);
        $late = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $phase->execute($late);
        $use($this->session($scope));
        $response = $phase->finalize($late, new HttpResponse('ok', 200, ['Content-Type' => 'text/plain']));

        self::assertSame(['Content-Type' => 'text/plain'], $response->getHeaders());
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
        self::assertSame(2, $table->count(), 'no row for a session no cookie leads to');
        self::assertTrue($table->exists($newId));
    }

    /** A second sign-in racing the first cannot say "signed in" and send no cookie: it is told to retry. */
    #[Test]
    public function a_sign_in_on_the_old_cookie_is_told_to_retry_and_sent_no_cookie(): void
    {
        $table = $this->table();
        [$newId] = $this->signIn($table);

        [$phase, $scope] = $this->phase($table);
        $late = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $phase->execute($late);
        $session = $this->session($scope);
        $session->regenerate();
        $session->set('user_id', 8);
        $response = $phase->finalize($late, HttpResponse::redirect('/dashboard'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaders()['Retry-After'] ?? null);
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
        self::assertSame(2, $table->count());
        self::assertTrue($table->exists($newId));
        self::assertTrue($table->exists(self::BEFORE_SIGN_IN), 'the marker is left as it was');
    }

    /** After the window the id is one the store does not know, and the browser is told to drop it, as before. */
    #[Test]
    public function after_the_window_the_old_cookie_is_expired_as_any_unknown_one(): void
    {
        $table = $this->table();
        [$newId] = $this->signIn($table);
        $marker = $table->get(self::BEFORE_SIGN_IN);
        self::assertIsArray($marker, 'the retired id is marked');
        self::assertLessThanOrEqual(time() + Session::RETIRED_ID_GRACE_SECONDS, $marker['expires_at']);
        self::assertGreaterThan(time(), $marker['expires_at']);
        // The window has passed: the same row, a second past its expiry.
        $table->set(self::BEFORE_SIGN_IN, ['data' => $marker['data'], 'expires_at' => time() - 1]);

        [$phase, $scope] = $this->phase($table);
        $late = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN, ['XSRF-TOKEN' => 'the-token-from-before']));
        $phase->execute($late);
        self::assertFalse($this->session($scope)->isOnRetiredId());
        $lines = $this->cookieLines($phase->finalize($late, new HttpResponse('ok')));

        self::assertCount(2, $lines);
        self::assertStringStartsWith('semitexa_session=; Expires=', $lines[0]);
        self::assertStringContainsString('Max-Age=0', $lines[0]);
        self::assertStringStartsWith('XSRF-TOKEN=; Expires=', $lines[1]);
        self::assertFalse($table->exists(self::BEFORE_SIGN_IN), 'the expired marker is gone with the read');
        self::assertTrue($table->exists($newId));
    }

    /**
     * The fixation guard: whoever knew the id from before the sign-in must not
     * get the signed-in session through it (CWE-384). The old id opens an empty
     * guest session under an id of its own.
     */
    #[Test]
    public function the_old_id_opens_an_empty_guest_session_not_the_new_one(): void
    {
        $table = $this->table();
        [$newId, $newToken] = $this->signIn($table);

        [$phase, $scope] = $this->phase($table);
        $phase->execute(new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN)));
        $session = $this->session($scope);

        self::assertTrue($session->isOnRetiredId());
        self::assertNull($session->get('user_id'));
        self::assertFalse($session->has('user_id'));
        self::assertNull($session->get('__retired__'), 'the marker is not session data');
        self::assertFalse($session->has('__retired__'));
        self::assertNull($session->get('seen_before_sign_in'), 'nor does it keep what the old session held');
        self::assertTrue(Session::isWellFormedId($session->getSessionIdForCookie()));
        self::assertNotSame($newId, $session->getSessionIdForCookie());
        self::assertNotSame(self::BEFORE_SIGN_IN, $session->getSessionIdForCookie());
        $token = $session->csrfTokenForCheck();
        self::assertNotSame('', $token);
        self::assertNotSame($newToken, $token, 'its CSRF token is not the signed-in session\'s');
        self::assertNotSame('before', $token);
    }

    /** The stored row itself: a mark with an expiry, and no way from it to the new session. */
    #[Test]
    public function the_marker_holds_no_data_and_no_reference_to_the_new_id(): void
    {
        $table = $this->table();
        [$newId, $newToken] = $this->signIn($table);

        $row = $table->get(self::BEFORE_SIGN_IN);
        self::assertIsArray($row);
        self::assertSame('{"__retired__":1}', $row['data'], 'the whole of it');
        self::assertIsString($row['data']);
        self::assertStringNotContainsString($newId, $row['data']);
        self::assertStringNotContainsString($newToken, $row['data']);
        self::assertStringNotContainsString('user_id', $row['data']);
        self::assertStringNotContainsString('before', $row['data'], 'not the old CSRF token either');
        self::assertSame([], array_diff(array_keys($row), ['data', 'expires_at']), 'and the row has no other column');
    }

    /** A first-visit sign-in retires an id no browser ever held: no marker, one row. */
    #[Test]
    public function an_id_the_store_never_held_is_not_marked(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request());
        $phase->execute($context);
        $this->session($scope)->regenerate();
        $newId = $this->cookies($phase->finalize($context, new HttpResponse('signed in')))['semitexa_session'] ?? '';

        self::assertSame(1, $table->count());
        self::assertTrue($table->exists($newId));
    }

    /**
     * The other interleaving (tk-session-inflight-request-rewrites-a-retired-session):
     * the request opened the session BEFORE the sign-in saved, and finalizes
     * after it. Its save() used to write the old session back over the marker,
     * with a full lifetime, and the phase re-sent the old cookie.
     */
    #[Test]
    public function a_request_in_flight_across_a_sign_in_does_not_put_the_old_session_back(): void
    {
        $table = $this->table();
        $this->store($table, '{"seen_before_sign_in":true,"__csrf__":{"value":"before"},"__flash__":[]}');
        [$pollPhase, $pollScope] = $this->phase($table);
        $poll = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $pollPhase->execute($poll);
        $pollSession = $this->session($pollScope);
        self::assertTrue($pollSession->get('seen_before_sign_in'), 'opened while the id was a session');

        [$newId] = $this->signIn($table);
        $newRow = $table->get($newId);
        $marker = $table->get(self::BEFORE_SIGN_IN);

        $pollSession->set('polled', true);
        $response = $pollPhase->finalize($poll, new HttpResponse('{"new":0}', 200, ['Content-Type' => 'application/json']));

        self::assertSame(['Content-Type' => 'application/json'], $response->getHeaders(), 'no Set-Cookie: the old id is not sent again');
        self::assertIsArray($marker);
        self::assertSame('{"__retired__":1}', $marker['data']);
        self::assertSame($marker, $table->get(self::BEFORE_SIGN_IN), 'the marker is as the sign-in left it, expiry included');
        self::assertSame($newRow, $table->get($newId));
        self::assertSame(2, $table->count());
    }

    /**
     * The same across a sign-out, where it mattered most: the signed-in
     * session came back under the old id, for anyone holding that cookie.
     */
    #[Test]
    public function a_request_in_flight_across_a_sign_out_does_not_bring_the_signed_in_session_back(): void
    {
        $table = $this->table();
        $this->store($table, '{"user_id":7,"__csrf__":{"value":"before"},"__flash__":[]}');
        [$pollPhase, $pollScope] = $this->phase($table);
        $poll = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $pollPhase->execute($poll);
        self::assertSame(7, $this->session($pollScope)->get('user_id'));

        [$outPhase, $outScope] = $this->phase($table);
        $out = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $outPhase->execute($out);
        $this->session($outScope)->clear();
        $this->session($outScope)->regenerate();
        $guestId = $this->cookies($outPhase->finalize($out, new HttpResponse('signed out')))['semitexa_session'] ?? '';
        self::assertTrue(Session::isWellFormedId($guestId));

        $response = $pollPhase->finalize($poll, new HttpResponse('ok', 200, ['Content-Type' => 'text/plain']));

        self::assertSame(['Content-Type' => 'text/plain'], $response->getHeaders());
        self::assertSame('{"__retired__":1}', $table->get(self::BEFORE_SIGN_IN, 'data'));
        self::assertLessThanOrEqual(time() + Session::RETIRED_ID_GRACE_SECONDS, $table->get(self::BEFORE_SIGN_IN, 'expires_at'));
        self::assertSame(2, $table->count(), 'the marker and the guest session of the sign-out: the signed-in one is nowhere');
        $guest = $table->get($guestId, 'data');
        self::assertIsString($guest);
        self::assertStringContainsString('"__flash__":[]', $guest);
        self::assertStringNotContainsString('user_id', $guest);

        // Whoever still holds the old cookie is a guest.
        [$phase, $scope] = $this->phase($table);
        $phase->execute(new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN)));
        self::assertNull($this->session($scope)->get('user_id'));
        self::assertTrue($this->session($scope)->isOnRetiredId());
    }

    /** Two sign-ins on one session: the one that saves second stores nothing and is told to retry. */
    #[Test]
    public function a_sign_in_in_flight_across_another_is_told_to_retry_and_stores_nothing(): void
    {
        $table = $this->table();
        $this->store($table, '{"__csrf__":{"value":"before"},"__flash__":[]}');
        [$secondPhase, $secondScope] = $this->phase($table);
        $second = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $secondPhase->execute($second);

        [$newId] = $this->signIn($table);

        $session = $this->session($secondScope);
        $session->regenerate();
        $session->set('user_id', 8);
        $response = $secondPhase->finalize($second, HttpResponse::redirect('/dashboard'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaders()['Retry-After'] ?? null);
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders());
        self::assertSame(2, $table->count(), 'the first sign-in\'s session and the marker');
        self::assertTrue($table->exists($newId));
        self::assertFalse($table->exists($session->getSessionIdForCookie()));
    }

    /**
     * A full table. The new id's write makes room by deleting the rows that
     * expire soonest, and the marker, written first, was the soonest of all:
     * the sign-in deleted its own marker (15 of 20 runs on a table this size).
     * Twenty sign-ins, each on a table filled to the last row.
     */
    #[Test]
    public function a_sign_in_on_a_full_table_keeps_its_marker(): void
    {
        for ($run = 0; $run < 20; $run++) {
            $table = new \Swoole\Table(16);
            $table->column('data', \Swoole\Table::TYPE_STRING, 1024);
            $table->column('expires_at', \Swoole\Table::TYPE_INT);
            $table->create();
            // Saved by the page with the sign-in form a moment ago: no row expires later.
            $table->set(self::BEFORE_SIGN_IN, ['data' => '{"__csrf__":{"value":"before"},"__flash__":[]}', 'expires_at' => time() + 7200]);
            for ($i = 0, $refused = 0; $refused < 50; $i++) {
                $filled = @$table->set(md5("filler-{$run}-{$i}"), ['data' => '{"__flash__":[]}', 'expires_at' => time() + 3600 + $i]);
                $refused = $filled ? 0 : $refused + 1;
            }
            $rows = $table->count();
            self::assertGreaterThanOrEqual(16, $rows);

            [$phase, $scope] = $this->phase($table);
            $context = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
            $phase->execute($context);
            $this->session($scope)->regenerate();
            $this->session($scope)->set('user_id', 7);
            $newId = $this->cookies($phase->finalize($context, new HttpResponse('signed in')))['semitexa_session'] ?? '';

            self::assertTrue($table->exists($newId), "run {$run}: the new session is stored");
            self::assertSame('{"__retired__":1}', $table->get(self::BEFORE_SIGN_IN, 'data'), "run {$run}: and the old id is still marked");
        }
    }

    /**
     * tk-session-late-response-restores-a-retired-cookie: request A saves
     * before sign-in B retires its id, and A's response reaches the browser
     * after B's. A sets no Set-Cookie, so the browser keeps B's new cookie.
     */
    #[Test]
    public function a_response_that_saved_before_a_sign_in_and_arrives_after_it_leaves_the_new_cookie_in_the_browser(): void
    {
        $table = $this->table();
        $this->store($table, '{"seen_before_sign_in":true,"__csrf__":{"value":"before"},"__flash__":[]}');

        [$pollPhase, $pollScope] = $this->phase($table);
        $poll = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $pollPhase->execute($poll);
        $this->session($pollScope)->set('polled', true);
        $pollResponse = $pollPhase->finalize($poll, new HttpResponse('{"new":0}', 200, ['Content-Type' => 'application/json']));

        [$signInPhase, $signInScope] = $this->phase($table);
        $signIn = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $signInPhase->execute($signIn);
        $signInSession = $this->session($signInScope);
        $signInSession->regenerate();
        $signInSession->set('user_id', 7);
        $signInResponse = $signInPhase->finalize($signIn, HttpResponse::redirect('/dashboard'));

        $signInCookies = $this->cookies($signInResponse);
        $newId = $signInCookies['semitexa_session'] ?? '';
        $newToken = $signInCookies['XSRF-TOKEN'] ?? '';
        self::assertTrue(Session::isWellFormedId($newId));
        self::assertNotSame(self::BEFORE_SIGN_IN, $newId);
        self::assertNotSame('', $newToken);

        $jar = ['semitexa_session' => self::BEFORE_SIGN_IN, 'XSRF-TOKEN' => 'before'];
        foreach ([$signInResponse, $pollResponse] as $delivered) {
            foreach ($this->cookies($delivered) as $name => $value) {
                $jar[$name] = $value;
            }
        }

        self::assertSame(['Content-Type' => 'application/json'], $pollResponse->getHeaders());
        self::assertSame([], $this->cookies($pollResponse));
        self::assertSame($newId, $jar['semitexa_session']);
        self::assertSame($newToken, $jar['XSRF-TOKEN']);

        [$nextPhase, $nextScope] = $this->phase($table);
        $nextPhase->execute(new RequestLifecycleContext($this->request($jar['semitexa_session'])));
        self::assertSame(7, $this->session($nextScope)->get('user_id'));
    }

    #[Test]
    public function the_session_cookie_is_refreshed_only_once_past_half_its_lifetime(): void
    {
        $table = $this->table();
        [$newId, $newToken] = $this->signIn($table);

        // At exactly half the default 3600 s lifetime, the cookie is still fresh: not re-sent.
        $row = $table->get($newId);
        self::assertIsArray($row);
        $decoded = json_decode((string) $row['data'], true);
        self::assertIsArray($decoded);
        $decoded['__cookie_at__'] = time() - 1800;
        $table->set($newId, ['data' => json_encode($decoded, JSON_THROW_ON_ERROR), 'expires_at' => $row['expires_at']]);

        [$halfPhase] = $this->phase($table);
        $half = new RequestLifecycleContext($this->request($newId, ['XSRF-TOKEN' => $newToken]));
        $halfPhase->execute($half);
        self::assertSame([], $this->cookies($halfPhase->finalize($half, new HttpResponse('ok'))));

        // One second past half the lifetime, the cookie is refreshed once, then stays quiet again.
        $decoded['__cookie_at__'] = time() - 1801;
        $table->set($newId, ['data' => json_encode($decoded, JSON_THROW_ON_ERROR), 'expires_at' => $row['expires_at']]);

        [$duePhase] = $this->phase($table);
        $due = new RequestLifecycleContext($this->request($newId, ['XSRF-TOKEN' => $newToken]));
        $duePhase->execute($due);
        self::assertSame(
            ['semitexa_session' => $newId, 'XSRF-TOKEN' => $newToken],
            $this->cookies($duePhase->finalize($due, new HttpResponse('ok'))),
        );

        [$afterPhase] = $this->phase($table);
        $after = new RequestLifecycleContext($this->request($newId, ['XSRF-TOKEN' => $newToken]));
        $afterPhase->execute($after);
        self::assertSame([], $this->cookies($afterPhase->finalize($after, new HttpResponse('ok'))));
    }

    #[Test]
    public function a_request_that_rotates_the_csrf_token_or_carries_a_stale_xsrf_cookie_still_sends_the_cookies(): void
    {
        $table = $this->table();
        [$newId, $newToken] = $this->signIn($table);

        // Stale XSRF-TOKEN cookie presented by the client triggers re-sync.
        [$stalePhase] = $this->phase($table);
        $stale = new RequestLifecycleContext($this->request($newId, ['XSRF-TOKEN' => 'stale-token']));
        $stalePhase->execute($stale);
        self::assertSame(
            ['semitexa_session' => $newId, 'XSRF-TOKEN' => $newToken],
            $this->cookies($stalePhase->finalize($stale, new HttpResponse('ok'))),
        );

        // Server-side CsrfToken change triggers re-send even with a previously matching cookie.
        [$rotatedPhase, $rotatedScope] = $this->phase($table);
        $rotated = new RequestLifecycleContext($this->request($newId, ['XSRF-TOKEN' => $newToken]));
        $rotatedPhase->execute($rotated);
        $freshToken = CsrfToken::generate();
        $this->session($rotatedScope)->setPayload($freshToken);
        self::assertSame(
            ['semitexa_session' => $newId, 'XSRF-TOKEN' => $freshToken->getValue()],
            $this->cookies($rotatedPhase->finalize($rotated, new HttpResponse('ok'))),
        );
    }

    private function store(\Swoole\Table $table, string $data): void
    {
        $table->set(self::BEFORE_SIGN_IN, ['data' => $data, 'expires_at' => time() + 600]);
    }

    /**
     * A sign-in on a session the store holds: user 7 under a new id.
     *
     * @return array{string, string} the new session id and its CSRF token
     */
    private function signIn(\Swoole\Table $table): array
    {
        if (!$table->exists(self::BEFORE_SIGN_IN)) {
            $this->store($table, '{"seen_before_sign_in":true,"__csrf__":{"value":"before"},"__flash__":[]}');
        }
        [$phase, $scope] = $this->phase($table);
        $context = new RequestLifecycleContext($this->request(self::BEFORE_SIGN_IN));
        $phase->execute($context);
        $session = $this->session($scope);
        $session->regenerate();
        $session->set('user_id', 7);
        $cookies = $this->cookies($phase->finalize($context, new HttpResponse('signed in')));

        $newId = $cookies['semitexa_session'] ?? '';
        self::assertTrue(Session::isWellFormedId($newId));
        self::assertNotSame(self::BEFORE_SIGN_IN, $newId);
        self::assertNotSame('', $cookies['XSRF-TOKEN'] ?? '');

        return [$newId, $cookies['XSRF-TOKEN']];
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

    /** @return list<string> the Set-Cookie lines of the response */
    private function cookieLines(HttpResponse $response): array
    {
        return array_values(array_filter((array) ($response->getHeaders()['Set-Cookie'] ?? []), 'is_string'));
    }

    /** @return array<string, string> the cookies the response sets, by name */
    private function cookies(HttpResponse $response): array
    {
        $cookies = [];
        foreach ($this->cookieLines($response) as $line) {
            if (preg_match('/^([^=]+)=([^;]*)/', $line, $m) === 1) {
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

    /** @param array<string, string> $otherCookies */
    private function request(string $sessionCookie = '', array $otherCookies = []): Request
    {
        return new Request('GET', '/', [], [], [], ['REQUEST_URI' => '/'], ($sessionCookie === '' ? [] : ['semitexa_session' => $sessionCookie]) + $otherCookies);
    }
}
