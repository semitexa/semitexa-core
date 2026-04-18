<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Request;
use Semitexa\Core\Support\CoroutineLocal;
use Swoole\Coroutine\Channel;

/**
 * F-001 regression coverage: execution context values must be coroutine-local.
 *
 * Before the fix, SemitexaContainer stored request-scoped values (Request, Session,
 * Auth, Tenant, Locale) as an instance property on a worker-global singleton, which
 * meant one coroutine could read another coroutine's values after a yield.
 */
final class SemitexaContainerExecutionContextIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    #[Test]
    public function set_execution_context_is_scoped_to_current_coroutine_in_cli_mode(): void
    {
        $container = $this->buildContainerWithExecutionScopedTarget();

        $requestA = $this->makeRequest('/a');
        $container->setExecutionContext(new ExecutionContext(request: $requestA));

        $instance = $container->get(CapturesRequestViaMutable::class);
        self::assertSame($requestA, $instance->capturedRequest);
    }

    #[Test]
    public function clear_execution_context_removes_only_current_coroutine_state(): void
    {
        $container = $this->buildContainerWithExecutionScopedTarget();

        $container->setExecutionContext(new ExecutionContext(request: $this->makeRequest('/a')));
        self::assertNotNull($container->captureExecutionContext()->request);

        $container->clearExecutionContext();
        self::assertNull($container->captureExecutionContext()->request);
    }

    #[Test]
    public function capture_and_run_with_execution_context_restores_previous_values(): void
    {
        $container = $this->buildContainerWithExecutionScopedTarget();

        $requestA = $this->makeRequest('/a');
        $container->setExecutionContext(new ExecutionContext(request: $requestA));
        $snapshot = $container->captureExecutionContext();

        $requestB = $this->makeRequest('/b');
        $container->runWithExecutionContext(
            new ExecutionContext(request: $requestB),
            function () use ($container, $requestB): void {
                $inner = $container->get(CapturesRequestViaMutable::class);
                self::assertSame($requestB, $inner->capturedRequest);
            }
        );

        $restored = $container->get(CapturesRequestViaMutable::class);
        self::assertSame($requestA, $restored->capturedRequest);

        // Snapshot is immutable — mutating the current context must not affect it.
        self::assertSame($requestA, $snapshot->request);
    }

    #[Test]
    public function concurrent_swoole_coroutines_observe_their_own_execution_context_across_a_yield(): void
    {
        if (!extension_loaded('swoole') || !function_exists('Co\\run') || !class_exists(Channel::class)) {
            self::markTestSkipped('Swoole coroutine runtime is required for this test.');
        }

        $container = $this->buildContainerWithExecutionScopedTarget();

        $observedA = null;
        $observedB = null;

        \Co\run(static function () use ($container, &$observedA, &$observedB): void {
            $requestA = new Request('GET', '/a', [], [], [], [], []);
            $requestB = new Request('GET', '/b', [], [], [], [], []);
            $latch = new Channel(2);
            $releaseA = new Channel(1);
            $releaseB = new Channel(1);

            \Swoole\Coroutine::create(function () use ($container, $requestA, $latch, $releaseA, &$observedA): void {
                $container->setExecutionContext(new ExecutionContext(request: $requestA));
                $latch->push(true);

                // Yield to let the sibling coroutine overwrite the legacy shared state,
                // then re-resolve. If isolation is broken, we see sibling's Request.
                $releaseA->pop(1.0);

                $instance = $container->get(CapturesRequestViaMutable::class);
                $observedA = $instance->capturedRequest;
            });

            \Swoole\Coroutine::create(function () use ($container, $requestB, $latch, $releaseB, &$observedB): void {
                $container->setExecutionContext(new ExecutionContext(request: $requestB));
                $latch->push(true);

                $releaseB->pop(1.0);

                $instance = $container->get(CapturesRequestViaMutable::class);
                $observedB = $instance->capturedRequest;
            });

            $latch->pop(1.0);
            $latch->pop(1.0);

            // Both coroutines have written their execution context. Release them to
            // re-resolve across a yield boundary — the point at which the original
            // bug surfaced.
            $releaseA->push(true);
            $releaseB->push(true);

            \Swoole\Coroutine::sleep(0.05);
        });

        self::assertNotNull($observedA, 'Coroutine A did not resolve a Request');
        self::assertNotNull($observedB, 'Coroutine B did not resolve a Request');
        self::assertSame('/a', $observedA->uri, 'Coroutine A observed a sibling coroutine context');
        self::assertSame('/b', $observedB->uri, 'Coroutine B observed a sibling coroutine context');
    }

    #[Test]
    public function child_coroutine_does_not_inherit_parent_execution_context_by_default(): void
    {
        if (!extension_loaded('swoole') || !function_exists('Co\\run') || !class_exists(Channel::class)) {
            self::markTestSkipped('Swoole coroutine runtime is required for this test.');
        }

        $container = $this->buildContainerWithExecutionScopedTarget();
        $childSawContext = null;

        \Co\run(static function () use ($container, &$childSawContext): void {
            $container->setExecutionContext(new ExecutionContext(
                request: new Request('GET', '/parent', [], [], [], [], []),
            ));

            $done = new Channel(1);
            \Swoole\Coroutine::create(function () use ($container, $done, &$childSawContext): void {
                $childSawContext = $container->captureExecutionContext()->request;
                $done->push(true);
            });
            $done->pop(1.0);
        });

        self::assertNull(
            $childSawContext,
            'Child coroutines must not implicitly inherit parent execution context. '
            . 'Use captureExecutionContext()/runWithExecutionContext() for explicit propagation.'
        );
    }

    #[Test]
    public function run_with_execution_context_can_replay_parent_snapshot_in_child_coroutine(): void
    {
        if (!extension_loaded('swoole') || !function_exists('Co\\run') || !class_exists(Channel::class)) {
            self::markTestSkipped('Swoole coroutine runtime is required for this test.');
        }

        $container = $this->buildContainerWithExecutionScopedTarget();
        $childObserved = null;

        \Co\run(static function () use ($container, &$childObserved): void {
            $parentRequest = new Request('GET', '/parent', [], [], [], [], []);
            $container->setExecutionContext(new ExecutionContext(request: $parentRequest));

            $snapshot = $container->captureExecutionContext();
            $done = new Channel(1);

            \Swoole\Coroutine::create(function () use ($container, $snapshot, $done, &$childObserved): void {
                $container->runWithExecutionContext($snapshot, function () use ($container, &$childObserved): void {
                    $instance = $container->get(CapturesRequestViaMutable::class);
                    $childObserved = $instance->capturedRequest;
                });
                $done->push(true);
            });
            $done->pop(1.0);
        });

        self::assertNotNull($childObserved);
        self::assertSame('/parent', $childObserved->uri);
    }

    private function buildContainerWithExecutionScopedTarget(): SemitexaContainer
    {
        $container = new SemitexaContainer();

        $prototype = (new \ReflectionClass(CapturesRequestViaMutable::class))->newInstanceWithoutConstructor();

        $this->replacePrivate($container, 'instanceStore', function (InstanceStore $store) use ($prototype): void {
            $store->prototypes[CapturesRequestViaMutable::class] = $prototype;
        });

        $this->replacePrivate($container, 'typeMap', function (TypeMap $map): void {
            $map->registeredClasses[CapturesRequestViaMutable::class] = true;
            $map->executionScoped[CapturesRequestViaMutable::class] = true;
        });

        $this->replacePrivate($container, 'injectionMap', function (InjectionMap $map): void {
            $map->injections[CapturesRequestViaMutable::class] = [
                'capturedRequest' => ['kind' => 'mutable', 'type' => Request::class],
            ];
        });

        return $container;
    }

    private function replacePrivate(SemitexaContainer $container, string $field, callable $mutator): void
    {
        $prop = new ReflectionProperty(SemitexaContainer::class, $field);
        $prop->setAccessible(true);
        $value = $prop->getValue($container);
        $mutator($value);
    }

    private function makeRequest(string $path): Request
    {
        return new Request('GET', $path, [], [], [], [], []);
    }
}

/**
 * Test fixture: an execution-scoped prototype with an InjectAsMutable-style
 * Request property. The container clones this per execution and fills
 * $capturedRequest from the coroutine-local execution context.
 *
 * @internal
 */
final class CapturesRequestViaMutable
{
    public ?Request $capturedRequest = null;
}
