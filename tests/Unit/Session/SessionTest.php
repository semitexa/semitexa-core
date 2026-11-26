<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;

final class SessionTest extends TestCase
{
    #[Test]
    public function regenerate_rotates_the_csrf_token_so_a_pre_login_token_is_no_longer_valid(): void
    {
        $handler = new InMemorySessionHandler();
        $oldToken = CsrfToken::generate();
        $handler->store['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] = [
            '__csrf__' => ['value' => $oldToken->getValue()],
            'user_id' => 42,
        ];

        $session = new Session('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $handler, 'semitexa_session');
        $session->regenerate();

        /** @var CsrfToken $afterRegenerate */
        $afterRegenerate = $session->getPayload(CsrfToken::class);
        self::assertNotSame($oldToken->getValue(), $afterRegenerate->getValue());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $afterRegenerate->getValue());

        $session->save();

        $newId = $session->getSessionIdForCookie();
        self::assertNotSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $newId);
        // verify:accept-test-change the old id is marked as retired for a few seconds instead of deleted at once (tk-session-regenerate-race-signs-out); what matters here still holds: neither the old token nor the old data is left under it
        self::assertSame(['__retired__' => 1], $handler->store['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        self::assertSame(['value' => $afterRegenerate->getValue()], $handler->store[$newId]['__csrf__']);
        self::assertSame(42, $handler->store[$newId]['user_id']);
    }

    #[Test]
    public function save_without_regenerate_keeps_the_csrf_token_stable(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] = ['__csrf__' => ['value' => 'stable-token']];

        $session = new Session('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $handler, 'semitexa_session');
        $session->save();

        self::assertSame(['value' => 'stable-token'], $handler->store['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']['__csrf__']);
    }

    #[Test]
    public function the_new_id_is_visible_as_soon_as_regenerate_returns(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['cccccccccccccccccccccccccccccccc'] = ['user_id' => 7];

        $session = new Session('cccccccccccccccccccccccccccccccc', $handler, 'semitexa_session');
        $session->regenerate();
        $during = $session->getId();
        $session->regenerate();

        self::assertNotSame('cccccccccccccccccccccccccccccccc', $during, 'whatever is bound later in this request carries the next id');
        self::assertArrayHasKey('cccccccccccccccccccccccccccccccc', $handler->store, 'the old data stays until save()');

        $session->save();

        self::assertSame($session->getId(), $session->getSessionIdForCookie());
        // verify:accept-test-change the original id is no longer destroyed at once: it is marked as retired for a few seconds (tk-session-regenerate-race-signs-out); its data is gone as before, and the id of the first regenerate still leaves nothing
        self::assertSame(['cccccccccccccccccccccccccccccccc', $session->getId()], array_keys($handler->store), 'the original id is marked, a second regenerate leaves nothing behind');
        self::assertSame(['__retired__' => 1], $handler->store['cccccccccccccccccccccccccccccccc'], 'a mark: no data, and not the id that replaced it');
        self::assertSame(7, $handler->store[$session->getId()]['user_id']);
    }

    /** The marker goes through write() with a lifetime, so every store expires it its own way: SETEX in Redis, expires_at in the table. */
    #[Test]
    public function the_retired_id_is_written_with_the_grace_lifetime_and_the_session_with_its_own(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['dddddddddddddddddddddddddddddddd'] = ['user_id' => 7, '__flash__' => []];

        $session = new Session('dddddddddddddddddddddddddddddddd', $handler, 'semitexa_session', 1800);
        $session->regenerate();
        $session->save();

        self::assertSame(10, Session::RETIRED_ID_GRACE_SECONDS);
        // The new id is written first, the marker after it.
        self::assertSame(
            [$session->getId() => 1800, 'dddddddddddddddddddddddddddddddd' => Session::RETIRED_ID_GRACE_SECONDS],
            $handler->lifetimes,
        );
    }

    /** A session that only looks like the marker is a session: the marker is that one key and nothing else. */
    #[Test]
    public function a_stored_session_with_a_key_named_like_the_marker_is_still_a_session(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'] = ['__retired__' => 1, '__flash__' => []];

        $session = Session::open('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $handler, 'semitexa_session');

        self::assertFalse($session->isOnRetiredId());
        self::assertSame('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $session->getSessionIdForCookie());
        self::assertTrue($session->isWorthStoring());
    }

    // verify:accept-test-change was a_marker_the_store_has_no_room_for_is_dropped_and_the_session_is_saved: the marker is now written after the new id and only over the row it retires, so it needs no room; what a full store refuses is the new id, and that case is this test
    #[Test]
    public function a_new_id_the_store_has_no_room_for_leaves_the_old_session_as_it_was(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['ffffffffffffffffffffffffffffffff'] = ['user_id' => 7, '__flash__' => []];
        $handler->full = true;

        $session = new Session('ffffffffffffffffffffffffffffffff', $handler, 'semitexa_session');
        $session->regenerate();
        try {
            $session->save();
            self::fail('the full store was not reported');
        } catch (\Semitexa\Core\Session\SessionStoreFullException) {
        }

        self::assertSame(['ffffffffffffffffffffffffffffffff' => ['user_id' => 7, '__flash__' => []]], $handler->store);
    }

    /**
     * tk-session-inflight-request-rewrites-a-retired-session, on a handler
     * without a one-step conditional write (the read-then-write path).
     *
     * @return iterable<string, array{\Closure(Session): void}>
     */
    public static function retirements(): iterable
    {
        yield 'a sign-out' => [static function (Session $s): void {
            $s->clear();
            $s->regenerate();
        }];
        yield 'a sign-in' => [static function (Session $s): void {
            $s->set('user_id', 8);
            $s->regenerate();
        }];
    }

    /** @param \Closure(Session): void $retire */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('retirements')]
    public function a_request_that_opened_the_session_before_it_was_retired_does_not_write_it_back(\Closure $retire): void
    {
        $id = '11111111111111111111111111111111';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['user_id' => 7, '__flash__' => []];

        $poll = Session::open($id, $handler, 'semitexa_session');
        $other = Session::open($id, $handler, 'semitexa_session');
        $retire($other);
        $other->save();
        self::assertFalse($other->isOnRetiredId());
        $afterTheOther = $handler->store;

        self::assertSame(7, $poll->get('user_id'), 'the request in flight keeps what it read');
        $poll->set('seen', true);
        $poll->save();

        self::assertTrue($poll->isOnRetiredId());
        self::assertSame($afterTheOther, $handler->store, 'it wrote nothing');
        self::assertSame(['__retired__' => 1], $handler->store[$id]);
        self::assertSame(Session::RETIRED_ID_GRACE_SECONDS, $handler->lifetimes[$id], 'the marker keeps its own lifetime');
        $late = Session::open($id, $handler, 'semitexa_session');
        self::assertNull($late->get('user_id'), 'the old id opens nothing');
        self::assertNotSame($id, $late->getSessionIdForCookie());

        $poll->save();
        self::assertSame($afterTheOther, $handler->store, 'nor does a second save()');
    }

    /** Two requests regenerate the same session: the second finds the id retired and leaves nothing of its own. */
    #[Test]
    public function the_second_of_two_requests_that_regenerate_one_session_stores_nothing(): void
    {
        $id = '22222222222222222222222222222222';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['user_id' => 7, '__flash__' => []];

        $second = Session::open($id, $handler, 'semitexa_session');
        $first = Session::open($id, $handler, 'semitexa_session');
        $first->clear();
        $first->regenerate();
        $first->save();
        $second->regenerate();
        $second->save();

        self::assertTrue($second->isOnRetiredId());
        self::assertSame([$id, $first->getSessionIdForCookie()], array_keys($handler->store));
        self::assertArrayNotHasKey('user_id', $handler->store[$first->getSessionIdForCookie()]);
    }

    /** A session that ran out while the request was on its way is not started again under the old id. */
    #[Test]
    public function a_session_that_is_gone_by_the_time_of_save_is_not_written_back(): void
    {
        $id = '33333333333333333333333333333333';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['user_id' => 7, '__flash__' => []];

        $session = Session::open($id, $handler, 'semitexa_session');
        $handler->destroy($id);
        $session->save();

        self::assertTrue($session->isOnRetiredId());
        self::assertSame([], $handler->store);
    }

    /**
     * A session opened on a retired id is sent no cookie, so save() called on
     * it (the phase does not) leaves no row under the fresh id nobody holds,
     * also when its handler signed someone in.
     */
    #[Test]
    public function a_session_opened_on_a_retired_id_saves_nothing(): void
    {
        $id = '55555555555555555555555555555555';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['__retired__' => 1];

        $session = Session::open($id, $handler, 'semitexa_session');
        self::assertTrue($session->isOnRetiredId());
        $session->set('cart', 3);
        $session->save();
        $session->set('user_id', 7);
        $session->regenerate();
        $session->save();

        self::assertSame([$id => ['__retired__' => 1]], $handler->store);
    }

    /** What every request of a signed-in visitor does: the held session is written, and its lifetime slides. */
    #[Test]
    public function a_session_the_store_still_holds_is_written_as_before(): void
    {
        $id = '44444444444444444444444444444444';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['user_id' => 7, '__flash__' => []];

        $session = Session::open($id, $handler, 'semitexa_session', 1800);
        $session->set('cart', 3);
        $session->save();

        self::assertFalse($session->isOnRetiredId());
        self::assertSame(['user_id' => 7, 'cart' => 3, '__flash__' => []], $handler->store[$id]);
        self::assertSame(1800, $handler->lifetimes[$id]);
    }

    #[Test]
    public function refresh_cookie_if_due_honours_the_exact_half_lifetime_boundary(): void
    {
        $id = '66666666666666666666666666666666';
        $handler = new InMemorySessionHandler();
        $handler->store[$id] = ['__csrf__' => ['value' => 'tok'], '__flash__' => []];

        $first = Session::open($id, $handler, 'semitexa_session', 3600);
        self::assertTrue($first->refreshCookieIfDue($id, 'tok', 1000));
        $first->save();
        self::assertSame(1000, $handler->read($id)['__cookie_at__'] ?? null);

        $rightAfter = Session::open($id, $handler, 'semitexa_session', 3600);
        self::assertFalse($rightAfter->refreshCookieIfDue($id, 'tok', 1001));

        $atHalf = Session::open($id, $handler, 'semitexa_session', 3600);
        self::assertFalse($atHalf->refreshCookieIfDue($id, 'tok', 2800));

        $pastHalf = Session::open($id, $handler, 'semitexa_session', 3600);
        self::assertTrue($pastHalf->refreshCookieIfDue($id, 'tok', 2801));
        $pastHalf->save();
        self::assertSame(2801, $handler->read($id)['__cookie_at__']);

        $afterRefresh = Session::open($id, $handler, 'semitexa_session', 3600);
        self::assertFalse($afterRefresh->refreshCookieIfDue($id, 'tok', 2802));
    }
}

final class InMemorySessionHandler implements SessionHandlerInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $store = [];

    /** @var array<string, int> the lifetime of the last write, by session id */
    public array $lifetimes = [];

    /** Whether a write of an id the store does not hold finds it full. */
    public bool $full = false;

    public function read(string $sessionId): array
    {
        return $this->store[$sessionId] ?? [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
        if ($this->full && !isset($this->store[$sessionId])) {
            throw new \Semitexa\Core\Session\SessionStoreFullException('full');
        }
        $this->store[$sessionId] = $data;
        $this->lifetimes[$sessionId] = $lifetimeSeconds;
    }

    public function destroy(string $sessionId): void
    {
        unset($this->store[$sessionId]);
    }
}
