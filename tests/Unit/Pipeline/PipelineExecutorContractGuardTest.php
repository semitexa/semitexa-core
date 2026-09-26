<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;

/**
 * A route handler that was registered without running LintHandlersCommand
 * (a hand-edited registry, a module upgraded without re-linting) can name a
 * class that is neither a TypedHandlerInterface implementation nor
 * duck-typed with a handle(RequestPipelineContext) method (the dispatch
 * PipelineListenerInterface-style listeners rely on — see ReRunUnitTest's
 * fixtures, which are NOT declared against that interface). Before this fix,
 * PipelineExecutor::invokeListener() called `->handle()` on it unconditionally
 * — an unguarded fatal "Call to undefined method" instead of a diagnosable
 * PipelineException naming the offending class.
 */
final class PipelineExecutorContractGuardTest extends TestCase
{
    #[Test]
    public function a_handler_with_neither_contract_nor_a_handle_method_raises_a_pipeline_exception(): void
    {
        $requestScope = new ContractGuardArrayContainer([
            NeitherContractHandler::class => new NeitherContractHandler(),
        ]);
        $container = new ContractGuardArrayContainer([
            PipelineListenerRegistry::class => new ContractGuardEmptyListenerRegistry(),
        ]);

        $executor = new PipelineExecutor($requestScope, $container, null);

        $route = new DiscoveredRoute(
            path: '/contract-guard',
            methods: ['GET'],
            name: 'contract-guard',
            requestClass: ContractGuardPayload::class,
            responseClass: null,
            handlers: [['class' => NeitherContractHandler::class, 'execution' => 'sync']],
            type: 'http-request',
            transport: null,
            produces: null,
            consumes: null,
            module: 'core',
        );

        $context = new RequestPipelineContext(
            requestDto: new ContractGuardPayload(),
            route: $route,
            request: new Request('GET', '/contract-guard', [], [], [], [], []),
            resourceDto: null,
            authBootstrapper: null,
        );

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage(NeitherContractHandler::class);

        $executor->execute($context);
    }
}

final class ContractGuardArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new class("no binding for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

/** Duck-typed stand-in: PipelineExecutor only calls getListeners(). */
final class ContractGuardEmptyListenerRegistry
{
    /** @return list<array{class: class-string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return [];
    }
}

final class ContractGuardPayload
{
}

/** Implements neither TypedHandlerInterface nor PipelineListenerInterface. */
final class NeitherContractHandler
{
}
