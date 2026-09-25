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
        self::assertArrayNotHasKey('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $handler->store);
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
}

final class InMemorySessionHandler implements SessionHandlerInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $store = [];

    public function read(string $sessionId): array
    {
        return $this->store[$sessionId] ?? [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
        $this->store[$sessionId] = $data;
    }

    public function destroy(string $sessionId): void
    {
        unset($this->store[$sessionId]);
    }
}
