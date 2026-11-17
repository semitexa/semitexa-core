<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionHandlerInterface;
use Swoole\Coroutine\CanceledException;

/**
 * tk-a-cancelled-request-is-served-as-a-guest: an SSE stream whose browser
 * left is cancelled with Coroutine::cancel($cid, true), and Swoole throws
 * CanceledException where the coroutine waits, during the session read too.
 * SessionPhase took it for a store that cannot be read: it logged "Session
 * store unreadable; serving the request with a transient guest session" (87
 * times in one consumer's E2E run) and ran the stopped request on as a guest.
 * A cancellation stops the request; a real read failure still degrades.
 */
final class CancelledSessionReadStopsTheRequestTest extends TestCase
{
    private string|false $redisHostBefore = false;

    protected function setUp(): void
    {
        $this->redisHostBefore = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
    }

    protected function tearDown(): void
    {
        $this->redisHostBefore === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST=' . $this->redisHostBefore);
    }

    #[Test]
    public function a_cancelled_read_stops_the_request_instead_of_serving_a_guest(): void
    {
        foreach ([
            'raw' => new CanceledException(),
            'wrapped' => new \RuntimeException('session read failed', 0, new CanceledException()),
        ] as $shape => $cancellation) {
            $phase = $this->phaseOverAStoreWhoseReadThrows($cancellation);

            $thrown = null;
            try {
                $phase->execute(new RequestLifecycleContext($this->request()));
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertSame($cancellation, $thrown, "the {$shape} cancellation goes on instead of becoming a transient guest session");
        }
    }

    private function phaseOverAStoreWhoseReadThrows(\Throwable $failure): SessionPhase
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
        $phase = new SessionPhase($container, new RequestScopedContainer($container), new \Semitexa\Core\Tenant\DefaultTenantContextStore(), null);
        $store = new class ($failure) implements SessionHandlerInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function read(string $sessionId): array
            {
                throw $this->failure;
            }

            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
            {
            }

            public function destroy(string $sessionId): void
            {
            }
        };
        (new \ReflectionProperty(SessionPhase::class, 'sessionHandler'))->setValue($phase, $store);

        return $phase;
    }

    private function request(): Request
    {
        return new Request('GET', '/__semitexa_kiss', [], [], [], ['REQUEST_URI' => '/__semitexa_kiss'], ['semitexa_session' => str_repeat('a', 32)]);
    }
}
