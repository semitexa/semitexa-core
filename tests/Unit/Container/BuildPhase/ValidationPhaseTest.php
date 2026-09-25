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
