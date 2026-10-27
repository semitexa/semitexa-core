<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Queue\QueueDispatcher;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\QueueWorker;
use Symfony\Component\Console\Output\BufferedOutput;
use Semitexa\Core\Queue\InProcessAsyncHandler;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Queue\QueuedOrigin;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * A Queued handler's reply renders in the worker, and a signed UI context
 * minted there was bound to no session: any session could present it. The
 * queuing request's contribution now travels in the message.
 */
final class QueuedOriginTest extends TestCase
{
    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $state;

    protected function setUp(): void
    {
        $this->state = StaticState::snapshot(QueuedOrigin::class);
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->state);
    }

    #[Test]
    public function what_a_store_exports_is_readable_inside_the_job_only(): void
    {
        $bound = ['sb' => 'digest', 'tn' => 'acme'];
        QueuedOrigin::exporter('test.binding', static fn (): array => $bound);
        QueuedOrigin::exporter('test.nothing', static fn (): ?array => null);
        // The first exporter named stays: every request names it again.
        QueuedOrigin::exporter('test.binding', static fn (): array => ['sb' => 'other', 'tn' => 'x']);

        $origin = QueuedOrigin::capture();
        self::assertSame(['test.binding' => $bound], $origin);

        self::assertNull(QueuedOrigin::get('test.binding'));
        $seen = QueuedOrigin::run($origin, static function (): ?array {
            $inner = QueuedOrigin::run([], static fn (): ?array => QueuedOrigin::get('test.binding'));
            self::assertNull($inner, 'a job queued without one sees none');

            return QueuedOrigin::get('test.binding');
        });
        self::assertSame($bound, $seen);
        self::assertNull(QueuedOrigin::get('test.binding'), 'put back after the job');
    }

    #[Test]
    public function the_message_carries_it_through_a_retry_and_an_old_message_has_none(): void
    {
        $message = new QueuedHandlerMessage('App\\H', 'App\\Req', 'App\\Res', [], [], origin: ['test.binding' => ['sb' => 'd', 'tn' => 't']]);
        $message->attempts++;

        self::assertSame(['test.binding' => ['sb' => 'd', 'tn' => 't']], QueuedHandlerMessage::fromJson($message->toJson())->origin);
        self::assertSame([], QueuedHandlerMessage::fromJson('{"type":"handler","handler":"App\\\\H","requestClass":"App\\\\Req","responseClass":"App\\\\Res"}')->origin);
    }

    #[Test]
    public function enqueue_writes_it_and_the_worker_runs_the_handler_inside_it(): void
    {
        $registry = StaticState::snapshot(QueueTransportRegistry::class);
        QueueTransportRegistry::reset();
        QueueTransportRegistry::register('origin-spy', new class implements QueueTransportFactoryInterface {
            public function create(): QueueTransportInterface
            {
                return new OriginSpyTransport();
            }
        });
        OriginSpyTransport::$published = [];
        try {
            QueuedOrigin::exporter('test.binding', static fn (): array => ['sb' => 'digest', 'tn' => 'acme']);
            QueueDispatcher::enqueue(['class' => OriginSeeingHandler::class, 'transport' => 'origin-spy', 'queue' => 'q'], new \stdClass(), new \stdClass());
            $this->runInWorker(OriginSpyTransport::$published[0] ?? '');
        } finally {
            StaticState::restore($registry);
        }
        self::assertCount(1, OriginSpyTransport::$published);
        $payload = OriginSpyTransport::$published[0];
        self::assertSame(['test.binding' => ['sb' => 'digest', 'tn' => 'acme']], json_decode($payload, true)['origin'] ?? null);

        self::assertSame(['sb' => 'digest', 'tn' => 'acme'], OriginSeeingHandler::$seen, $this->workerOutput);
        self::assertNull(QueuedOrigin::get('test.binding'), 'and not after it');
    }

    private string $workerOutput = '';

    private function runInWorker(string $payload): void
    {
        // The worker resolves the handler from the worker container; a fresh
        // one holds it here (the booted one is sealed).
        OriginSeeingHandler::$seen = null;
        $slot = new \ReflectionProperty(ContainerFactory::class, 'container');
        $booted = $slot->getValue();
        $container = new SemitexaContainer();
        $container->set(OriginSeeingHandler::class, new OriginSeeingHandler());
        $slot->setValue(null, $container);
        try {
            $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
            $output = new BufferedOutput();
            foreach (['output' => $output, 'currentTransport' => 'origin-spy', 'currentQueue' => 'q', 'messageProblem' => null, 'messageProblemIsError' => false] as $field => $value) {
                (new \ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
            }
            (new \ReflectionProperty(QueueWorker::class, 'statsResolved'))->setValue($worker, true);
            $stats = (string) tempnam(sys_get_temp_dir(), 'queued-origin');
            (new \ReflectionProperty(QueueWorker::class, 'statsFile'))->setValue($worker, $stats);
            (new \ReflectionMethod(QueueWorker::class, 'processHandlerPayload'))->invoke($worker, $payload);
        } finally {
            $slot->setValue(null, $booted);
            @unlink($stats ?? '');
        }

        $this->workerOutput = $output->fetch();
    }

    #[Test]
    public function an_exporter_that_fails_does_not_stop_the_job_from_being_queued(): void
    {
        QueuedOrigin::exporter('test.broken', static fn (): array => throw new \RuntimeException('no secret'));
        QueuedOrigin::exporter('test.binding', static fn (): array => ['tn' => 'acme']);

        self::assertSame(['test.binding' => ['tn' => 'acme']], QueuedOrigin::capture());
    }

    #[Test]
    public function a_queued_handler_run_in_process_sees_what_a_worker_would(): void
    {
        QueuedOrigin::exporter('test.binding', static fn (): array => ['sb' => 'digest', 'tn' => 'acme']);
        $handler = new class {
            /** @var array<array-key, mixed>|null */
            public ?array $seen = null;

            public function handle(object $payload, object $resource): object
            {
                $this->seen = QueuedOrigin::get('test.binding');

                return $resource;
            }
        };

        // Outside a request the run is inline (AfterResponse).
        self::assertTrue(InProcessAsyncHandler::schedule($handler::class, static fn (): object => $handler, new \stdClass(), new \stdClass(), '', HandlerExecution::Queued));
        self::assertSame(['sb' => 'digest', 'tn' => 'acme'], $handler->seen);
    }
}

final class OriginSpyTransport implements QueueTransportInterface
{
    /** @var list<string> */
    public static array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = $payload;
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class OriginSeeingHandler
{
    /** @var array<array-key, mixed>|null */
    public static ?array $seen = null;

    public function handle(object $payload, object $resource): object
    {
        self::$seen = QueuedOrigin::get('test.binding');

        return $resource;
    }
}
