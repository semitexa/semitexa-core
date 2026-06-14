<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\ReRun;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\PreHydrationAuthGateInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Pipeline\ReRun\ReRunResult;
use Semitexa\Core\Pipeline\ReRun\RouteReRunner;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\ExecutionContextAwareContainerInterface;

/**
 * Track R · R2 — the core re-run unit, proven HEADLESS (no HTTP, no SSE loop, no
 * connect coordinator). The security model of Shape 1 lives here, so the
 * anti-poisoning invariant is proven both positively AND negatively.
 *
 * @see \Semitexa\Core\Pipeline\RouteExecutor::reExecute
 * @see \Semitexa\Core\Pipeline\ReRun\RouteReRunner
 */
final class ReRunUnitTest extends TestCase
{
    private string|false $redisHostBefore = false;

    protected function setUp(): void
    {
        // Force SessionPhase's no-Redis branch so the re-run's execution-context
        // establishment (Track R · R8c-2 / Gap A) uses the SwooleTable session
        // handler, which reads as an empty session when no table is set — i.e. a
        // headless establish with no Redis dependency in the unit.
        $this->redisHostBefore = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
    }

    protected function tearDown(): void
    {
        if ($this->redisHostBefore === false) {
            putenv('REDIS_HOST');
        } else {
            putenv('REDIS_HOST=' . $this->redisHostBefore);
        }
    }
    /**
     * Proof 1 — a cached DTO re-executed re-runs the chain and returns a FRESHLY
     * re-queried frame, not the stale cached value. Re-running again yields a new
     * value, proving the data resolver runs each tick.
     */
    public function testReRunProducesFreshFrame(): void
    {
        $counter = new ReRunCounter();
        $handler = new ReRunSpyHandler($counter);
        [$reRunner] = $this->makeReRunner(
            handler: $handler,
            sessionSubjects: ['sid-alice' => 'alice'],
        );

        $context = $this->makeContext(cookies: ['sid' => 'sid-alice']);

        $first = $reRunner->reRun($context);
        $second = $reRunner->reRun($context);

        $this->assertFalse($first->isTerminated());
        $this->assertFalse($second->isTerminated());
        $this->assertSame(2, $handler->calls, 'The data resolver must run on every re-run.');
        $this->assertSame(1, $this->frameValue($first), 'First frame carries the first fresh query.');
        $this->assertSame(2, $this->frameValue($second), 'Second frame is re-queried, not the stale cached value.');
    }

    /**
     * Proof 2 — auth-first TERMINATE. A subject whose authorization now DENIES (at
     * the pre-hydration gate) yields a TERMINATE result with NO data frame, and the
     * data resolver is NOT EVEN REACHED.
     */
    public function testAuthFirstTerminateAtGateNeverReachesDataResolver(): void
    {
        $counter = new ReRunCounter();
        $handler = new ReRunSpyHandler($counter);
        [$reRunner] = $this->makeReRunner(
            handler: $handler,
            sessionSubjects: ['sid-revoked' => null], // logged-out / revoked
        );

        $context = $this->makeContext(cookies: ['sid' => 'sid-revoked']);

        $result = $reRunner->reRun($context);

        $this->assertTrue($result->isTerminated(), 'A de-authorized subject must TERMINATE.');
        $this->assertNull($result->getFrame(), 'No data frame for a de-authorized subject.');
        $this->assertNotNull($result->getReason());
        $this->assertSame(0, $handler->calls, 'The data resolver must NOT be reached after a denial.');
    }

    /**
     * Proof 2b — the same TERMINATE guarantee when authorization is enforced in the
     * AuthCheck pipeline phase (the AuthorizationListener position), not the gate.
     * The handler runs in the later HandleRequest phase, so it is never reached.
     */
    public function testAuthCheckPhaseDenialTerminatesBeforeDataResolver(): void
    {
        $counter = new ReRunCounter();
        $handler = new ReRunSpyHandler($counter);
        [$reRunner] = $this->makeReRunner(
            handler: $handler,
            sessionSubjects: ['sid-alice' => 'alice'],     // gate ALLOWS
            authCheckListeners: [ReRunDenyAuthCheckListener::class], // phase DENIES
            extraBindings: [ReRunDenyAuthCheckListener::class => new ReRunDenyAuthCheckListener()],
        );

        $context = $this->makeContext(cookies: ['sid' => 'sid-alice']);

        $result = $reRunner->reRun($context);

        $this->assertTrue($result->isTerminated(), 'AuthCheck-phase denial must TERMINATE.');
        $this->assertNull($result->getFrame());
        $this->assertSame(0, $handler->calls, 'Handler runs after AuthCheck — never reached after a phase denial.');
    }

    /**
     * Proof 3 — anti-poisoning POSITIVE. Identity is read from the immutable block +
     * the live session; the re-run resolves the correct subject from the session
     * each run.
     */
    public function testAntiPoisoningPositiveIdentityFromSession(): void
    {
        $gate = new ReRunFakeAuthGate(['sid-alice' => 'alice']);
        [$reRunner] = $this->makeReRunner(
            handler: new ReRunSpyHandler(new ReRunCounter()),
            gate: $gate,
        );

        $context = $this->makeContext(cookies: ['sid' => 'sid-alice']);

        $result = $reRunner->reRun($context);

        $this->assertFalse($result->isTerminated());
        $this->assertSame('alice', $gate->lastResolvedSubject, 'Subject is resolved from the session, not the DTO.');
    }

    /**
     * Proof 4 — anti-poisoning NEGATIVE (the decisive one). Mutating the cached DTO
     * to impersonate a different / over-privileged subject leaves the re-run's
     * authorization UNAFFECTED: the verdict and resolved subject are identical with
     * and without the poison, and a poisoned DTO cannot grant access on a revoked
     * session.
     */
    public function testAntiPoisoningNegativePoisonedDtoCannotChangeVerdict(): void
    {
        // (a) Authorized session: poisoning the DTO to claim 'admin' does NOT change
        //     the resolved subject — it stays 'alice', and the frame is produced.
        $gate = new ReRunFakeAuthGate(['sid-alice' => 'alice']);
        [$reRunner] = $this->makeReRunner(
            handler: new ReRunSpyHandler(new ReRunCounter()),
            gate: $gate,
        );

        $cleanResult = $reRunner->reRun($this->makeContext(cookies: ['sid' => 'sid-alice']));
        $cleanSubject = $gate->lastResolvedSubject;

        $poisonedDto = new ReRunFakePayload();
        $poisonedDto->impersonateAs = 'admin';        // attacker mutates the cached DTO
        $poisonedDto->filter = 'all';
        $poisonedResult = $reRunner->reRun($this->makeContext(
            cookies: ['sid' => 'sid-alice'],
            dto: $poisonedDto,
        ));

        $this->assertFalse($cleanResult->isTerminated());
        $this->assertFalse($poisonedResult->isTerminated());
        $this->assertSame('alice', $cleanSubject);
        $this->assertSame(
            'alice',
            $gate->lastResolvedSubject,
            'A poisoned DTO claiming admin must NOT change who the re-run authorizes as.',
        );

        // (b) Revoked session: poisoning the DTO to claim 'admin' still TERMINATES —
        //     the DTO is never an identity source, so it cannot grant access.
        $revokedGate = new ReRunFakeAuthGate(['sid-revoked' => null]);
        $handler = new ReRunSpyHandler(new ReRunCounter());
        [$revokedRunner] = $this->makeReRunner(handler: $handler, gate: $revokedGate);

        $poisoned = new ReRunFakePayload();
        $poisoned->impersonateAs = 'admin';
        $terminated = $revokedRunner->reRun($this->makeContext(
            cookies: ['sid' => 'sid-revoked'],
            dto: $poisoned,
        ));

        $this->assertTrue($terminated->isTerminated(), 'A poisoned DTO cannot grant access on a revoked session.');
        $this->assertNull($revokedGate->lastResolvedSubject);
        $this->assertSame(0, $handler->calls);
    }

    /**
     * Proof 5 — the Strategy-C seam is present, callable, defaulted to null/empty,
     * and the Strategy-A (full-chain re-run) path runs today.
     */
    public function testStrategyCSeamIsPresentAndDefaultedToStrategyA(): void
    {
        $handler = new ReRunSpyHandler(new ReRunCounter());
        [$reRunner] = $this->makeReRunner(
            handler: $handler,
            sessionSubjects: ['sid-alice' => 'alice'],
        );

        $defaultContext = $this->makeContext(cookies: ['sid' => 'sid-alice']);
        $this->assertNull(
            $defaultContext->getBroadcastProperties(),
            'The Strategy-C hook defaults to null — Strategy A is the only path today.',
        );

        $result = $reRunner->reRun($defaultContext);
        $this->assertFalse($result->isTerminated());
        $this->assertSame(1, $handler->calls, 'Strategy A (full re-run) runs while Strategy C is unimplemented.');

        // The seam is genuinely a carrier: when populated it round-trips, ready for
        // a future in-memory pre-filter, without changing today's behaviour.
        $seamContext = $this->makeContext(
            cookies: ['sid' => 'sid-alice'],
            broadcastProperties: ['status' => 'open'],
        );
        $this->assertSame(['status' => 'open'], $seamContext->getBroadcastProperties());
        $this->assertFalse($reRunner->reRun($seamContext)->isTerminated());
    }

    /**
     * Context re-establishment — the immutable tenant context is re-installed into
     * the request-scoped store on each re-run (never read from the DTO).
     */
    public function testTenantContextReEstablishedFromImmutableBlock(): void
    {
        $store = new ReRunFakeTenantStore();
        $tenant = new ReRunFakeTenantContext();
        [$reRunner] = $this->makeReRunner(
            handler: new ReRunSpyHandler(new ReRunCounter()),
            sessionSubjects: ['sid-alice' => 'alice'],
            // Tenancy ENABLED models production: the re-run's execution-context
            // establishment (SessionPhase) then re-reads the immutable-block tenant
            // RouteReRunner primed into the store rather than clearing it (the
            // clear only happens for a genuinely tenancy-disabled app).
            extraBindings: [
                TenantContextStoreInterface::class    => $store,
                TenancyBootstrapperInterface::class   => new ReRunFakeEnabledTenancy(),
            ],
        );

        $context = $this->makeContext(cookies: ['sid' => 'sid-alice'], tenant: $tenant);
        $reRunner->reRun($context);

        $this->assertSame($tenant, $store->lastSet, 'Tenant context is re-established from the immutable block.');
    }

    /**
     * Track R · R8c-2 / Gap A regression — the re-run must ESTABLISH the request-scoped
     * execution context before the pipeline runs, so an execution-scoped pipeline
     * listener (the live regression was platform-ui's ResetPlatformUiSseSessionListener
     * on the AuthCheck phase) resolves instead of throwing
     * {@see \Semitexa\Core\Container\Exception\ExecutionContextNotReadyException}.
     *
     * This test closes BOTH blind spots that hid the bug from the original R2 suite:
     *
     *  1. It uses an execution-scope-AWARE container ({@see ReRunExecutionScopeAwareContainer})
     *     so {@see RequestScopedContainer}'s readiness guard actually fires in a unit —
     *     the old fakes were plain PSR containers, so the guard (which used to hard-bind
     *     to the concrete SemitexaContainer) never triggered.
     *  2. It registers a genuinely execution-scoped AuthCheck listener — the old suite
     *     only ever registered plain non-scoped listeners, so nothing demanded the
     *     execution context.
     *
     * Without the fix, resolving the listener throws before SessionPhase establishes the
     * context and the whole re-run aborts (no frame); with the fix, reExecute establishes
     * the context (after the auth gate, never from the cached DTO) and the listener
     * resolves and runs.
     */
    public function testReRunEstablishesExecutionContextSoExecutionScopedListenersResolve(): void
    {
        $listener = new ReRunExecScopedAuthCheckListener();
        $handler = new ReRunSpyHandler(new ReRunCounter());

        $bindings = [
            PreHydrationAuthGateInterface::class       => new ReRunFakeAuthGate(['sid-alice' => 'alice']),
            PipelineListenerRegistry::class            => new ReRunStubListenerRegistry([
                AuthCheck::class => [[
                    'class'    => ReRunExecScopedAuthCheckListener::class,
                    'phase'    => AuthCheck::class,
                    'priority' => -1000,
                ]],
            ]),
            AttributeDiscovery::class                  => new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry()),
            PayloadPartRegistry::class                 => new PayloadPartRegistry(),
            ReRunSpyHandler::class                     => $handler,
            ReRunExecScopedAuthCheckListener::class    => $listener,
            // Present so reExecute's establish-only SessionPhase can be built.
            TenantContextStoreInterface::class         => new DefaultTenantContextStore(),
        ];

        $inner = new ReRunExecutionScopeAwareContainer(
            $bindings,
            [ReRunExecScopedAuthCheckListener::class => true], // <- the listener is execution-scoped
        );
        $rsc = new RequestScopedContainer($inner);
        $reRunner = new RouteReRunner(new RouteExecutor($rsc, $inner), $inner);

        // Sanity: the context is NOT ready before the re-run (no trio set yet).
        $this->assertFalse($rsc->isExecutionContextReady());

        $result = $reRunner->reRun($this->makeContext(cookies: ['sid' => 'sid-alice']));

        // The fix established the execution context, so the execution-scoped listener
        // resolved and ran, and a fresh frame was produced (not aborted).
        $this->assertTrue($rsc->isExecutionContextReady(), 'Re-run must establish the request-scoped execution context.');
        $this->assertTrue($listener->ran, 'The execution-scoped AuthCheck listener must resolve and run after establishment.');
        $this->assertInstanceOf(HttpResponse::class, $result->getFrame(), 'A fresh frame is produced after establishment.');
    }

    // ---- harness ---------------------------------------------------------------

    private function frameValue(ReRunResult $result): ?int
    {
        $frame = $result->getFrame();
        self::assertInstanceOf(HttpResponse::class, $frame);
        /** @var array{value?: int} $decoded */
        $decoded = json_decode($frame->getContent(), true);

        return $decoded['value'] ?? null;
    }

    /**
     * @param array<string, object> $extraBindings
     * @param list<class-string>    $authCheckListeners
     * @return array{0: RouteReRunner, 1: RouteExecutor}
     */
    private function makeReRunner(
        ReRunSpyHandler $handler,
        ?array $sessionSubjects = null,
        ?ReRunFakeAuthGate $gate = null,
        array $authCheckListeners = [],
        array $extraBindings = [],
    ): array {
        $gate ??= new ReRunFakeAuthGate($sessionSubjects ?? ['sid-alice' => 'alice']);

        $listenerMeta = [];
        foreach ($authCheckListeners as $priority => $class) {
            $listenerMeta[] = ['class' => $class, 'phase' => AuthCheck::class, 'priority' => $priority];
        }

        $bindings = [
            PreHydrationAuthGateInterface::class => $gate,
            PipelineListenerRegistry::class      => new ReRunStubListenerRegistry([AuthCheck::class => $listenerMeta]),
            AttributeDiscovery::class            => new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry()),
            PayloadPartRegistry::class           => new PayloadPartRegistry(),
            ReRunSpyHandler::class               => $handler,
        ];
        foreach ($extraBindings as $id => $instance) {
            $bindings[$id] = $instance;
        }

        $inner = new ReRunArrayContainer($bindings);
        $rsc = new RequestScopedContainer($inner);
        $executor = new RouteExecutor($rsc, $inner);

        return [new RouteReRunner($executor, $inner), $executor];
    }

    /**
     * @param array<string, string>    $cookies
     * @param array<string, mixed>|null $broadcastProperties
     */
    private function makeContext(
        array $cookies,
        ?object $dto = null,
        ?TenantContextInterface $tenant = null,
        ?array $broadcastProperties = null,
    ): ReRunContext {
        return new ReRunContext(
            cachedDto: $dto ?? new ReRunFakePayload(),
            route: new DiscoveredRoute(
                path: '/leads',
                methods: ['GET'],
                name: 'leads.live',
                requestClass: ReRunFakePayload::class,
                responseClass: ReRunSpyResource::class,
                handlers: [['class' => ReRunSpyHandler::class]],
                type: 'http-request',
                transport: 'sse',
                produces: null,
                consumes: null,
                module: 'core',
            ),
            requestSnapshot: [
                'method'  => 'GET',
                'uri'     => '/leads',
                'cookies' => $cookies,
            ],
            sessionId: $cookies['sid'] ?? '',
            subjectRef: 'alice',
            tenantContext: $tenant,
            broadcastProperties: $broadcastProperties,
        );
    }
}

// --------------------------------------------------------------------------------
// Fixtures
// --------------------------------------------------------------------------------

/** A tiny PSR container backed by an instance map. */
final class ReRunArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $bindings */
    public function __construct(private array $bindings = []) {}

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->bindings[$id])) {
            throw new class ("No binding for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->bindings[$id];
    }
}

/** A tenancy bootstrapper that reports tenancy ENABLED (models production). */
final class ReRunFakeEnabledTenancy implements TenancyBootstrapperInterface
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function resolve(Request $request): ?HttpResponse
    {
        return null;
    }
}

/**
 * An execution-scoped AuthCheck-phase listener. Resolving it through a
 * {@see RequestScopedContainer} before the execution context is established throws
 * {@see \Semitexa\Core\Container\Exception\ExecutionContextNotReadyException} — it
 * models platform-ui's ResetPlatformUiSseSessionListener (the live Gap A regression)
 * without the platform-ui dependency.
 */
final class ReRunExecScopedAuthCheckListener
{
    public bool $ran = false;

    public function handle(RequestPipelineContext $context): void
    {
        $this->ran = true;
    }
}

/**
 * Like {@see ReRunArrayContainer} but execution-scope-AWARE: it implements
 * {@see ExecutionContextAwareContainerInterface} so {@see RequestScopedContainer}'s
 * readiness guard fires in a unit (a plain PSR fake could never trigger it — the
 * original blind spot that hid Track R · Gap A from the R2 suite).
 */
final class ReRunExecutionScopeAwareContainer implements ContainerInterface, ExecutionContextAwareContainerInterface
{
    private ?ExecutionContext $executionContext = null;

    /**
     * @param array<string, object> $bindings
     * @param array<string, bool>   $executionScoped
     */
    public function __construct(private array $bindings = [], private array $executionScoped = []) {}

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->bindings[$id])) {
            throw new class ("No binding for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->bindings[$id];
    }

    public function setExecutionContext(ExecutionContext $context): void
    {
        $this->executionContext = $context;
    }

    public function isExecutionScoped(string $id): bool
    {
        return $this->executionScoped[$id] ?? false;
    }
}

/**
 * Duck-typed stand-in for {@see PipelineListenerRegistry} (PipelineExecutor resolves
 * it through the container and only calls getListeners()).
 */
final class ReRunStubListenerRegistry
{
    /** @param array<string, list<array{class: string, phase: string, priority: int}>> $byPhase */
    public function __construct(private array $byPhase = []) {}

    /** @return list<array{class: string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return $this->byPhase[$phaseClass] ?? [];
    }
}

/**
 * Pre-hydration auth gate that resolves identity ONLY from the request session —
 * never from the payload. Denies (throws) when the session maps to no subject.
 */
final class ReRunFakeAuthGate implements PreHydrationAuthGateInterface
{
    public ?string $lastResolvedSubject = null;

    /** @param array<string, ?string> $sessionSubjects sid => subject (null = revoked/logged-out) */
    public function __construct(private array $sessionSubjects) {}

    public function gate(object $barePayload, Request $request, ?AuthBootstrapperInterface $authBootstrapper): void
    {
        // Identity is resolved from the live session reference, NEVER from the DTO.
        $sid = $request->getCookie('sid');
        $subject = $this->sessionSubjects[$sid] ?? null;
        $this->lastResolvedSubject = $subject;

        if ($subject === null) {
            throw new AccessDeniedException('Subject not authorized for session: ' . ($sid === '' ? '(none)' : $sid));
        }
    }
}

/** An AuthCheck-phase listener that always denies — models a revoked authorization. */
final class ReRunDenyAuthCheckListener
{
    public function handle(RequestPipelineContext $context): void
    {
        throw new AccessDeniedException('Denied during the AuthCheck phase.');
    }
}

/** The data resolver: counts invocations and re-queries a fresh value each run. */
final class ReRunSpyHandler
{
    public int $calls = 0;

    public function __construct(private ReRunCounter $counter) {}

    public function handle(RequestPipelineContext $context): void
    {
        $this->calls++;
        $resource = $context->resourceDto;
        if ($resource instanceof ReRunSpyResource) {
            $resource->value = $this->counter->next(); // a "fresh query" each tick
        }
    }
}

/** A monotonic data source standing in for a re-queried resource. */
final class ReRunCounter
{
    private int $value = 0;

    public function next(): int
    {
        return ++$this->value;
    }
}

/** The response DTO rendered into the frame body. */
final class ReRunSpyResource
{
    public ?int $value = null;

    public function toCoreResponse(): HttpResponse
    {
        return HttpResponse::json(['value' => $this->value]);
    }
}

/** The cached request DTO. Its mutable fields are NEVER an identity source. */
final class ReRunFakePayload
{
    public ?string $filter = null;
    public ?string $impersonateAs = null;
}

/** Records the last tenant context installed, to prove re-establishment. */
final class ReRunFakeTenantStore implements TenantContextStoreInterface
{
    public ?TenantContextInterface $lastSet = null;

    public function get(): TenantContextInterface
    {
        return $this->lastSet ?? new ReRunFakeTenantContext();
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->lastSet;
    }

    public function set(TenantContextInterface $context): void
    {
        $this->lastSet = $context;
    }

    public function clear(): void
    {
        $this->lastSet = null;
    }
}

/** Minimal tenant context used only as an identity-bearing immutable token. */
final class ReRunFakeTenantContext implements TenantContextInterface
{
    public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
    {
        return null;
    }

    public function hasLayer(TenantLayerInterface $layer): bool
    {
        return false;
    }
}
