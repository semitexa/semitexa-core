<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Queue\Message\QueuedEventListenerMessage;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\QueueWorker;
use Semitexa\Core\Support\PayloadSerializer;
use Semitexa\Core\Tests\Support\StaticState;
use Semitexa\Core\Tests\Unit\Queue\Fixtures\ConstructionCounter;
use Semitexa\Core\Tests\Unit\Queue\Fixtures\RecordingQueueTransport;
use Semitexa\Core\Tests\Unit\Queue\Fixtures\RecordingQueueTransportFactory;
use Semitexa\Core\Tests\Unit\Queue\Fixtures\SetterVisibilityDto;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A queue message names the classes the worker builds and runs, and whoever
 * can write to the broker chooses them
 * (tk-queue-worker-pre-validation-hydration-and-handle-bypass, CWE-502/CWE-470).
 * The worker built the request, the response or the event first, through
 * set*() methods of any visibility, and then ran any container service with
 * a handle(): every synchronous HTTP handler, or a service that is no
 * listener at all. It now runs only a handler registered as Queued for the
 * classes it names, or a listener registered as Queued for the event, and
 * builds nothing of any other message.
 */
final class QueueWorkerRefusesForeignMessagesTest extends TestCase
{
    private const TRANSPORT = 'refuse-test-spy';

    /** @var list<array{class: class-string, values: array<string, mixed>}> */
    private array $staticState = [];

    private string $stats = '';

    protected function setUp(): void
    {
        $this->staticState = [StaticState::snapshot(QueueTransportRegistry::class)];
        QueueTransportRegistry::reset();
        QueueTransportRegistry::register(self::TRANSPORT, new RecordingQueueTransportFactory());
        RecordingQueueTransport::$published = [];
        ConstructionCounter::$built = 0;
        SetterVisibilityDto::$shared = null;
        $this->stats = sys_get_temp_dir() . '/semitexa-refuse-stats-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        array_map([StaticState::class, 'restore'], $this->staticState);
        RecordingQueueTransport::$published = [];
        @unlink($this->stats);
    }

    #[Test]
    public function a_payload_reaches_only_public_instance_setters(): void
    {
        $dto = PayloadSerializer::hydrate(new SetterVisibilityDto(), ['name' => 'n', 'secret' => 's', 'inner' => 'i', 'shared' => 'g']);

        self::assertInstanceOf(SetterVisibilityDto::class, $dto);
        self::assertSame(['name=n'], $dto->calls, 'neither the private nor the protected setter ran');
        self::assertNull(SetterVisibilityDto::$shared, 'the static setter did not run');
    }

    #[Test]
    public function only_a_queued_handler_runs_and_only_for_the_classes_it_is_registered_for(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(\ArrayObject::class, \SplObjectStorage::class, ['class' => 'App\\Jobs\\QueuedJob', 'execution' => 'queued', 'transport' => null, 'queue' => null, 'priority' => 0]);
        $registry->register(\ArrayObject::class, \SplObjectStorage::class, ['class' => 'App\\Http\\SyncPage', 'execution' => 'sync', 'transport' => null, 'queue' => null, 'priority' => 0]);
        $refusal = static fn (string $handler, string $request, string $response, ?HandlerRegistry $in = null): ?string => self::refusal(
            'refusalOfHandlerMessage',
            new QueuedHandlerMessage($handler, $request, $response, [], []),
            func_num_args() === 4 ? $in : $registry,
        );

        self::assertNull($refusal('App\\Jobs\\QueuedJob', \ArrayObject::class, \SplObjectStorage::class));
        self::assertSame('handler App\\Http\\SyncPage does not run queued', $refusal('App\\Http\\SyncPage', \ArrayObject::class, \SplObjectStorage::class));
        self::assertSame('handler App\\Other\\Service is not a discovered payload handler', $refusal('App\\Other\\Service', \ArrayObject::class, \SplObjectStorage::class));
        self::assertSame(
            'handler App\\Jobs\\QueuedJob is not registered for ' . ConstructionCounter::class . ' and ' . \SplObjectStorage::class,
            $refusal('App\\Jobs\\QueuedJob', ConstructionCounter::class, \SplObjectStorage::class),
        );
        self::assertSame(
            'handler App\\Jobs\\QueuedJob: request App\\Nope or response ' . \SplObjectStorage::class . ' not found',
            $refusal('App\\Jobs\\QueuedJob', 'App\\Nope', \SplObjectStorage::class),
        );
        self::assertSame(
            'handler App\\Jobs\\QueuedJob cannot be checked: no handler registry in this boot',
            $refusal('App\\Jobs\\QueuedJob', \ArrayObject::class, \SplObjectStorage::class, null),
        );

        // Registered for an abstract payload: refused, not retried until it lands in the dead letter anyway.
        $registry->register(\SplHeap::class, \SplObjectStorage::class, ['class' => 'App\\Jobs\\HeapJob', 'execution' => 'queued', 'transport' => null, 'queue' => null, 'priority' => 0]);
        self::assertSame(
            'handler App\\Jobs\\HeapJob: ' . \SplHeap::class . ' is not a class the worker can build',
            $refusal('App\\Jobs\\HeapJob', \SplHeap::class, \SplObjectStorage::class),
        );
    }

    #[Test]
    public function only_a_queued_listener_of_the_event_named_runs(): void
    {
        $listeners = [
            ['class' => 'App\\Listen\\Queued', 'event' => 'App\\Ev', 'execution' => 'queued', 'transport' => null, 'queue' => null, 'priority' => 0],
            ['class' => 'App\\Listen\\Sync', 'event' => 'App\\Ev', 'execution' => 'sync', 'transport' => null, 'queue' => null, 'priority' => 0],
        ];
        $refusal = static fn (string $listener): ?string => self::refusal('refusalOfListenerMessage', new QueuedEventListenerMessage($listener, 'App\\Ev', []), $listeners);

        self::assertNull($refusal('App\\Listen\\Queued'));
        self::assertSame('listener App\\Listen\\Sync is not a queued listener of App\\Ev', $refusal('App\\Listen\\Sync'));
        self::assertSame('listener App\\Other\\Service is not a queued listener of App\\Ev', $refusal('App\\Other\\Service'));
    }

    #[Test]
    public function a_handler_message_naming_a_sync_handler_builds_nothing_and_is_dead_lettered(): void
    {
        $handler = self::aDiscoveredSyncHandler();

        $this->worker()->processPayload((new QueuedHandlerMessage($handler, ConstructionCounter::class, ConstructionCounter::class, ['planted' => 1], []))->toJson());

        self::assertSame(0, ConstructionCounter::$built, 'neither the request nor the response was built');
        self::assertSame(["handler {$handler} does not run queued"], $this->deadLetterErrors());
    }

    #[Test]
    public function an_event_message_naming_a_service_that_is_no_queued_listener_builds_nothing(): void
    {
        $service = self::aDiscoveredSyncHandler();

        $this->worker()->processPayload((new QueuedEventListenerMessage($service, ConstructionCounter::class, ['planted' => 1]))->toJson());

        self::assertSame(0, ConstructionCounter::$built, 'the event was not built');
        self::assertSame(["listener {$service} is not a queued listener of " . ConstructionCounter::class], $this->deadLetterErrors());
    }

    /** A payload handler of this boot that runs in the request: a container service with a handle(). */
    private static function aDiscoveredSyncHandler(): string
    {
        $registry = ContainerFactory::get()->get(HandlerRegistry::class);
        self::assertInstanceOf(HandlerRegistry::class, $registry);
        foreach ($registry->getHandlerClassNames() as $class) {
            if (($registry->getHandlerByClass($class)['execution'] ?? null) === 'sync' && ContainerFactory::get()->has($class)) {
                return $class;
            }
        }
        self::fail('no sync payload handler is discovered: nothing to aim a message at');
    }

    private function worker(): QueueWorker
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        foreach ([
            'currentTransport' => self::TRANSPORT,
            'currentQueue' => 'jobs',
            'messageProblem' => null,
            'messageProblemIsError' => false,
            'output' => new BufferedOutput(),
            'statsResolved' => true,
            'statsFile' => $this->stats,
        ] as $field => $value) {
            (new ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
        }

        return $worker;
    }

    /** @return list<string> the errors of what reached jobs.failed */
    private function deadLetterErrors(): array
    {
        $errors = [];
        foreach (RecordingQueueTransport::$published as [$queue, $payload]) {
            self::assertSame('jobs.failed', $queue);
            $errors[] = (string) (json_decode($payload, true)['error'] ?? '');
        }

        return $errors;
    }

    private static function refusal(string $method, object $message, mixed $registryOrListeners): ?string
    {
        $answer = (new ReflectionMethod(QueueWorker::class, $method))->invoke(null, $message, $registryOrListeners);
        self::assertTrue($answer === null || is_string($answer));

        return $answer;
    }
}
