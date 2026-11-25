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
        self::assertSame(
            ['dddddddddddddddddddddddddddddddd' => Session::RETIRED_ID_GRACE_SECONDS, $session->getId() => 1800],
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

        self::assertFalse($session->wasOpenedOnRetiredId());
        self::assertSame('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $session->getSessionIdForCookie());
        self::assertTrue($session->isWorthStoring());
    }

    /** A table with no room for the marker (the old row was evicted meanwhile) does not fail the sign-in. */
    #[Test]
    public function a_marker_the_store_has_no_room_for_is_dropped_and_the_session_is_saved(): void
    {
        $handler = new InMemorySessionHandler();
        $handler->store['ffffffffffffffffffffffffffffffff'] = ['user_id' => 7, '__flash__' => []];
        $handler->refuses = 'ffffffffffffffffffffffffffffffff';

        $session = new Session('ffffffffffffffffffffffffffffffff', $handler, 'semitexa_session');
        $session->regenerate();
        $session->save();

        self::assertSame([$session->getId()], array_keys($handler->store));
    }
}

final class InMemorySessionHandler implements SessionHandlerInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $store = [];

    /** @var array<string, int> the lifetime of the last write, by session id */
    public array $lifetimes = [];

    /** An id whose write finds the store full. */
    public ?string $refuses = null;

    public function read(string $sessionId): array
    {
        return $this->store[$sessionId] ?? [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
        if ($sessionId === $this->refuses) {
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
