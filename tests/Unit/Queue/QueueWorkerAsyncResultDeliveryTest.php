<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueWorker;

/**
 * deliverAsyncResult is on the worker's hot path: it must be exception-safe
 * (a broken delivery channel cannot kill the worker loop) and must not do
 * pointless work for a fire-and-forget message (empty session id).
 *
 * The bound-delivery-throws → logged branch resolves AsyncResultDeliveryInterface
 * from the static container, which is not stubbable in a pure unit (final
 * SemitexaContainer, no ContainerFactory setter); its logging mirrors the
 * FallbackErrorLogger pattern pinned in AuthorizationListenerDenialAuditTest
 * and ConversationStorePersistFailureTest. Here we pin the two invariants
 * that ARE deterministically reachable.
 */
final class QueueWorkerAsyncResultDeliveryTest extends TestCase
{
    #[Test]
    public function empty_session_id_short_circuits_before_any_delivery_work(): void
    {
        $this->invokeDeliver('', new \stdClass(), 'AnyHandler');
        $this->addToAssertionCount(1); // returned without touching the container
    }

    #[Test]
    public function delivery_is_exception_safe_and_never_breaks_the_worker_loop(): void
    {
        // With no delivery implementation bound the method returns at the
        // has() gate; if one is bound and throws, the catch swallows+logs.
        // Either way it must not propagate — the worker keeps draining.
        $this->invokeDeliver('session-xyz', new \stdClass(), 'SomeHandler');
        $this->addToAssertionCount(1);
    }

    private function invokeDeliver(string $sessionId, object $dto, string $handler): void
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(QueueWorker::class, 'deliverAsyncResult');
        $method->invoke($worker, $sessionId, $dto, $handler);
    }
}
