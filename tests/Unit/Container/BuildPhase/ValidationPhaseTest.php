<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\BuildPhase;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\ValidationPhase;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Request;

final class RequestReadingService
{
    protected Request $request;
}

/**
 * #[InjectAsMutable] is filled per execution, on the clone of an
 * execution-scoped prototype. A worker-scoped service is built once and never
 * cloned, so a mutable Request on it booted fine and then failed at request
 * time with "must not be accessed before initialization".
 */
final class ValidationPhaseTest extends TestCase
{
    #[Test]
    public function mutable_execution_context_injection_into_a_worker_scoped_class_fails_boot(): void
    {
        try {
            (new ValidationPhase())->execute($this->context(executionScoped: false));
        } catch (InjectionException $e) {
            self::assertStringContainsString(RequestReadingService::class . '::$request', $e->getMessage());
            self::assertStringContainsString('#[ExecutionScoped]', $e->getMessage());

            return;
        }

        self::fail('A worker-scoped class can never receive a mutable injection; boot must say so.');
    }

    #[Test]
    public function mutable_execution_context_injection_into_an_execution_scoped_class_passes_boot(): void
    {
        (new ValidationPhase())->execute($this->context(executionScoped: true));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_unbound_optional_mutable_on_a_worker_scoped_class_passes_boot(): void
    {
        // `optional: true` with no binding means "stays uninitialized" on any
        // class; the worker-scope rule must not turn that promise into a boot error.
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->injections = [
            RequestReadingService::class => [
                'maybe' => ['kind' => 'mutable', 'type' => 'App\\NoSuchService', 'optional' => true],
            ],
        ];

        (new ValidationPhase())->execute($context);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_bound_optional_mutable_on_a_worker_scoped_class_still_fails_boot(): void
    {
        // Bound, it WOULD be filled on a clone — and a worker-scoped class is
        // never cloned, so `optional` does not excuse it.
        $this->expectException(InjectionException::class);
        $context = $this->context(executionScoped: false);
        $context->injections[RequestReadingService::class]['request']['optional'] = true;

        (new ValidationPhase())->execute($context);
    }

    private function context(bool $executionScoped): BuildContext
    {
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->injections = [
            RequestReadingService::class => [
                'request' => ['kind' => 'mutable', 'type' => Request::class, 'optional' => false],
            ],
        ];
        if ($executionScoped) {
            $context->executionScopedClasses[RequestReadingService::class] = true;
        }

        return $context;
    }
}
