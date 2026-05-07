<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Modules\AuthDemo\Domain\Model\AuthDemoUser;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Application;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Lifecycle\TestStateResetRegistry;
use Semitexa\Core\Pipeline\HandlerReflectionCache;
use Semitexa\Core\Request;
use Semitexa\Modules\AuthDemo\Application\Service\AuthDemoCapabilityStore;
use Semitexa\Modules\AuthDemo\Application\Service\AuthDemoPermissionStore;
use Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoEventStore;
use Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoServiceCapabilityStore;
use Semitexa\Rbac\Application\Service\RbacDecisionCache;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * Lifecycle taxonomy tests.
 *
 * These tests pin the runtime contract that distinguishes per-REQUEST state
 * (must reset every Application::handleRequest / QueueWorker::processPayload)
 * from per-WORKER state (must SURVIVE across requests within a worker) and
 * test-only state (resettable on demand by tests, never by the framework).
 *
 * Companion document: packages/semitexa-docs/docs/en/runtime/state-lifecycle.md
 *
 * The tests run in CLI mode — no Swoole coroutine — which is exactly when
 * the static-fallback paths would leak if the framework's per-request reset
 * hook is wired wrong. In production Swoole HTTP mode coroutine context
 * auto-cleans, so a leak in a static fallback is invisible until you run
 * a queue worker or a console command.
 */
final class StateLifecycleTest extends TestCase
{
    private Application $app;
    private WebhookReplayStoreInterface $replay;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->replay = ContainerFactory::get()->get(WebhookReplayStoreInterface::class);

        // Force every store under test to self-register with its registry
        // (PerRequestStateRegistry or TestStateResetRegistry) before the
        // first assertion. The lazy ensureRegistered() pattern means a
        // store that is never written to is never registered — that's a
        // correctness property, not a bug, but the lifecycle assertions
        // need a known-registered baseline.
        AuthContextStore::clearFallback();
        AuthContextStore::setUser(null);
        RbacDecisionCache::clear();
        RbacDecisionCache::set('lifecycle-warm', $this->emptyGrants());
        CurrentRequestStore::set($this->makeRequest());
        AuthDemoPermissionStore::setForUser('lifecycle-warm', []);
        AuthDemoCapabilityStore::setForUser('lifecycle-warm', []);
        WebhookDemoServiceCapabilityStore::setForService('lifecycle-warm', []);
        WebhookDemoEventStore::clear();
        // Seed the event store so its registration callback is wired.
        WebhookDemoEventStore::record(new \Semitexa\Modules\WebhookDemo\Domain\Model\WebhookDemoEvent('warm', 'warm.v1', []));
        $this->replay->markSeen('lifecycle-warm');

        // Now wipe everything to start from a deterministic empty state.
        TestStateResetRegistry::resetAllForTesting();
        AuthContextStore::clearFallback();
        AuthContextStore::clear();
        RbacDecisionCache::clear();
        CurrentRequestStore::clear();
    }

    protected function tearDown(): void
    {
        TestStateResetRegistry::resetAllForTesting();
        AuthContextStore::clearFallback();
        AuthContextStore::clear();
        RbacDecisionCache::clear();
        CurrentRequestStore::clear();
        $this->app->requestScopedContainer->reset();
    }

    // ------------------------------------------------------------------
    //  Per-request state — MUST reset after each Application::handleRequest
    // ------------------------------------------------------------------

    #[Test]
    public function current_request_store_resets_after_application_handle_request(): void
    {
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));
        self::assertNull(CurrentRequestStore::get(), 'CurrentRequestStore must reset after handleRequest');
    }

    #[Test]
    public function rbac_decision_cache_resets_after_application_handle_request(): void
    {
        RbacDecisionCache::set('user-pre-dispatch', $this->emptyGrants());
        self::assertNotNull(RbacDecisionCache::get('user-pre-dispatch'));

        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertNull(RbacDecisionCache::get('user-pre-dispatch'), 'RbacDecisionCache must reset after handleRequest');
    }

    #[Test]
    public function auth_context_store_fallback_resets_after_application_handle_request(): void
    {
        // CLI/queue-worker leak path — coroutine context auto-cleans in
        // production Swoole, but the static fallback persists unless the
        // PerRequestStateRegistry wiring fires.
        AuthContextStore::setUser(new AuthDemoUser('lifecycle-test-user'));
        self::assertNotNull(AuthContextStore::getUser(), 'precondition: user is set');

        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertNull(
            AuthContextStore::getUser(),
            'AuthContextStore static fallback must reset after handleRequest',
        );
    }

    #[Test]
    public function per_request_reset_runs_in_finally_so_it_fires_on_4xx_and_5xx(): void
    {
        // /__semitexa/error/500 returns 500 by design. Per-request reset must
        // still happen — the framework wraps reset in a finally block.
        AuthContextStore::setUser(new AuthDemoUser('lifecycle-test-user'));
        RbacDecisionCache::set('error-path-user', $this->emptyGrants());

        $response = $this->app->handleRequest($this->makeRequest('/__semitexa/error/500'));

        self::assertSame(500, $response->getStatusCode(), 'precondition: route returns 500');
        self::assertNull(AuthContextStore::getUser(), 'AuthContextStore reset must run on 5xx');
        self::assertNull(RbacDecisionCache::get('error-path-user'), 'RbacDecisionCache reset must run on 5xx');
    }

    #[Test]
    public function per_request_state_registry_lists_only_per_request_stores(): void
    {
        // Prove that the per-request registry contains the request-scoped
        // store entries — not the per-worker or test-only ones.
        $names = PerRequestStateRegistry::registeredNames();

        self::assertContains('rbac_decision_cache', $names);
        self::assertContains('current_http_request_store', $names);
        self::assertContains('auth_context_store', $names);
    }

    #[Test]
    public function per_request_state_registry_excludes_replay_and_demo_stores(): void
    {
        // Per-worker and test-only stores must NOT register with the
        // per-request registry — that would clear them every request and
        // break the side-effect-survival contract.
        $names = PerRequestStateRegistry::registeredNames();

        self::assertNotContains('in_memory_webhook_replay_store', $names);
        self::assertNotContains('webhook_demo_event_store', $names);
        self::assertNotContains('auth_demo_permission_store', $names);
        self::assertNotContains('auth_demo_capability_store', $names);
        self::assertNotContains('webhook_demo_service_capability_store', $names);
    }

    #[Test]
    public function per_request_reset_callback_throwing_does_not_break_other_callbacks(): void
    {
        $sentinelCleared = false;
        PerRequestStateRegistry::register('lifecycle_test_throwing_callback', static function (): void {
            throw new \RuntimeException('intentional');
        });
        PerRequestStateRegistry::register('lifecycle_test_sentinel', static function () use (&$sentinelCleared): void {
            $sentinelCleared = true;
        });

        try {
            // resetAll must swallow the throw and still run the sentinel.
            PerRequestStateRegistry::resetAll();
        } finally {
            // Cleanup so other tests don't see these.
            PerRequestStateRegistry::register('lifecycle_test_throwing_callback', static fn (): null => null);
            PerRequestStateRegistry::register('lifecycle_test_sentinel', static fn (): null => null);
        }

        self::assertTrue($sentinelCleared, 'sentinel callback must run even after a sibling throws');
    }

    // ------------------------------------------------------------------
    //  Per-worker state — MUST survive across requests within a worker
    // ------------------------------------------------------------------

    #[Test]
    public function replay_store_does_not_reset_after_application_handle_request(): void
    {
        $this->replay->markSeen('lifecycle-replay-key');
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertTrue(
            $this->replay->seen('lifecycle-replay-key'),
            'replay store must survive a request — that is the whole point of replay protection',
        );
    }

    #[Test]
    public function replay_store_blocks_a_second_request_after_the_first_completes(): void
    {
        $this->replay->markSeen('lifecycle-second-request-key');
        // Run an unrelated request — must not affect the replay set.
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertTrue(
            $this->replay->seen('lifecycle-second-request-key'),
            'second-request replay protection broken: store cleared between requests',
        );
    }

    #[Test]
    public function webhook_demo_event_store_does_not_reset_after_application_handle_request(): void
    {
        WebhookDemoEventStore::record(new \Semitexa\Modules\WebhookDemo\Domain\Model\WebhookDemoEvent('lifecycle-event', 'lifecycle.event.v1', []));
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertSame(1, WebhookDemoEventStore::count(), 'side-effect store must survive a request');
    }

    // ------------------------------------------------------------------
    //  Metadata caches — never cleared per request
    // ------------------------------------------------------------------

    #[Test]
    public function payload_access_policy_metadata_cache_persists_across_requests(): void
    {
        // The resolver caches per-class access metadata. Resolving twice for
        // the same payload must be a hot lookup, not a re-discovery — and
        // certainly not affected by a request lifecycle in between.
        $resolver = new PayloadAccessPolicyResolver();
        $first = $resolver->accessType(new \Semitexa\Modules\AuthDemo\Application\Payload\Request\ProtectedPingPayload());

        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        $second = $resolver->accessType(new \Semitexa\Modules\AuthDemo\Application\Payload\Request\ProtectedPingPayload());
        self::assertSame($first, $second, 'PayloadAccessPolicyResolver cache must not be cleared per request');
    }

    #[Test]
    public function handler_reflection_cache_persists_across_requests(): void
    {
        // HandlerReflectionCache is keyed by handler class. A second
        // dispatch reuses the same warmed entry — proven indirectly by
        // running two dispatches and asserting nothing throws.
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        // The cache survives the request by virtue of HandlerReflectionCache
        // never being registered with PerRequestStateRegistry. Spot-check:
        // its reset() exists for tests but is not wired to any lifecycle.
        self::assertTrue(method_exists(HandlerReflectionCache::class, 'reset'));
        self::assertNotContains('handler_reflection_cache', PerRequestStateRegistry::registeredNames());
    }

    // ------------------------------------------------------------------
    //  TestStateResetRegistry — opt-in, never auto-fired by the framework
    // ------------------------------------------------------------------

    #[Test]
    public function test_state_reset_registry_lists_demo_and_replay_stores(): void
    {
        $names = TestStateResetRegistry::registeredNames();

        self::assertContains('auth_demo_permission_store', $names);
        self::assertContains('auth_demo_capability_store', $names);
        self::assertContains('webhook_demo_event_store', $names);
        self::assertContains('webhook_demo_service_capability_store', $names);
        self::assertContains('in_memory_webhook_replay_store', $names);
    }

    #[Test]
    public function test_state_reset_registry_clears_all_registered_demo_stores(): void
    {
        AuthDemoPermissionStore::setForUser('user-A', ['perm.x']);
        AuthDemoCapabilityStore::setForUser('user-A', []);
        WebhookDemoServiceCapabilityStore::setForService('service-A', []);
        WebhookDemoEventStore::record(new \Semitexa\Modules\WebhookDemo\Domain\Model\WebhookDemoEvent('e', 'e.v1', []));
        $this->replay->markSeen('replay-A');

        TestStateResetRegistry::resetAllForTesting();

        self::assertSame([], AuthDemoPermissionStore::getForUser('user-A'));
        self::assertSame(0, WebhookDemoEventStore::count());
        self::assertFalse($this->replay->seen('replay-A'));
    }

    #[Test]
    public function test_state_reset_registry_is_NOT_called_by_application_handle_request(): void
    {
        // The framework MUST NOT invoke the test registry from the
        // request lifecycle. If it did, replay protection and any
        // in-memory side-effect store would be wiped between requests.
        $this->replay->markSeen('test-registry-not-called-key');
        WebhookDemoEventStore::record(new \Semitexa\Modules\WebhookDemo\Domain\Model\WebhookDemoEvent('untouched', 'untouched.v1', []));

        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        self::assertTrue($this->replay->seen('test-registry-not-called-key'));
        self::assertSame(1, WebhookDemoEventStore::count());
    }

    #[Test]
    public function test_state_reset_registry_swallows_callback_exception_and_continues(): void
    {
        $sentinelCleared = false;
        TestStateResetRegistry::register('lifecycle_test_throwing_callback', static function (): void {
            throw new \RuntimeException('intentional');
        });
        TestStateResetRegistry::register('lifecycle_test_sentinel', static function () use (&$sentinelCleared): void {
            $sentinelCleared = true;
        });

        try {
            TestStateResetRegistry::resetAllForTesting();
        } finally {
            // Replace with no-ops so subsequent tests don't see this.
            TestStateResetRegistry::register('lifecycle_test_throwing_callback', static fn (): null => null);
            TestStateResetRegistry::register('lifecycle_test_sentinel', static fn (): null => null);
        }

        self::assertTrue($sentinelCleared, 'sentinel must run even when sibling callback throws');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function emptyGrants(): \Semitexa\Authorization\Domain\Model\SubjectGrantSet
    {
        return new \Semitexa\Authorization\Domain\Model\SubjectGrantSet(
            new \Semitexa\Authorization\Domain\Model\CapabilityGrantSet([]),
            new \Semitexa\Authorization\Domain\Model\PermissionGrantSet([]),
        );
    }

    private function makeRequest(string $path = '/__semitexa/error/404'): Request
    {
        return new Request(
            method: 'GET',
            uri: $path,
            headers: ['Accept' => 'application/json'],
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path],
            cookies: [],
            content: null,
        );
    }
}
