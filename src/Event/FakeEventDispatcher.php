<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

/**
 * An EventDispatcherInterface for tests: it records every event and runs no
 * listener, so a test can say what was dispatched without what listeners do.
 *
 *     $events = new FakeEventDispatcher();
 *     // hand $events to the code where it expects EventDispatcherInterface, run it, then:
 *     $events->assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->getOrderId() === 42);
 *     $events->assertNotDispatched(OrderRefunded::class);
 *
 * create() builds the event as the real dispatcher does. Post-dispatch hooks
 * are kept but never called. A failed assertion throws \LogicException.
 */
final class FakeEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @param array<string, mixed> $payload */
    public function create(string $eventClass, array $payload): object
    {
        return (new EventDispatcher())->create($eventClass, $payload);
    }

    public function dispatch(object $event): void
    {
        $this->dispatched[] = $event;
    }

    public function addPostDispatchHook(callable $hook): void
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $eventClass
     * @return list<T>
     */
    public function dispatchedOf(string $eventClass): array
    {
        return array_values(array_filter($this->dispatched, static fn (object $e): bool => $e instanceof $eventClass));
    }

    /** @return list<object> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /**
     * @param class-string $eventClass
     * @param (\Closure(object): bool)|null $matches
     */
    public function assertDispatched(string $eventClass, ?\Closure $matches = null): void
    {
        foreach ($this->dispatchedOf($eventClass) as $event) {
            if ($matches === null || $matches($event)) {
                return;
            }
        }

        throw new \LogicException(sprintf(
            '%s was not dispatched%s; dispatched: %s',
            $eventClass,
            $matches === null ? '' : ' with what the test expected',
            $this->summary(),
        ));
    }

    /** @param class-string $eventClass */
    public function assertDispatchedTimes(string $eventClass, int $times): void
    {
        $count = count($this->dispatchedOf($eventClass));
        if ($count !== $times) {
            throw new \LogicException(sprintf('%s was dispatched %d times, expected %d.', $eventClass, $count, $times));
        }
    }

    /** @param class-string $eventClass */
    public function assertNotDispatched(string $eventClass): void
    {
        if ($this->dispatchedOf($eventClass) !== []) {
            throw new \LogicException(sprintf('%s was dispatched; dispatched: %s', $eventClass, $this->summary()));
        }
    }

    public function assertNothingDispatched(): void
    {
        if ($this->dispatched !== []) {
            throw new \LogicException('Events were dispatched: ' . $this->summary());
        }
    }

    public function reset(): void
    {
        $this->dispatched = [];
    }

    private function summary(): string
    {
        return $this->dispatched === [] ? 'nothing' : implode(', ', array_map(static fn (object $e): string => $e::class, $this->dispatched));
    }
}
