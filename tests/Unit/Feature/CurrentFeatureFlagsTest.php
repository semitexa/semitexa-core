<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Feature\CurrentFeatureFlags;
use Semitexa\Core\Feature\CurrentFeatureFlagsListener;
use Semitexa\Core\Feature\InMemoryFeatureFlags;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Support\CoroutineLocal;

final class CurrentFeatureFlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    #[Test]
    public function the_listener_hands_the_request_flags_to_templates_and_without_it_every_flag_is_off(): void
    {
        CoroutineLocal::resetCliStore();
        self::assertFalse(CurrentFeatureFlags::isEnabled('checkout.new-flow'), 'outside a request');
        self::assertNull(CurrentFeatureFlags::current());

        $listener = new CurrentFeatureFlagsListener();
        (new \ReflectionProperty($listener, 'flags'))->setValue($listener, new InMemoryFeatureFlags(['checkout.new-flow' => true]));
        $listener->handle(new RequestPipelineContext(
            requestDto: new \stdClass(),
            route: new DiscoveredRoute('/x', ['GET'], 'x', \stdClass::class, null, [], 'http_request', null, null, null, 'stub'),
            request: new Request('GET', '/x', [], [], [], [], []),
        ));

        self::assertTrue(CurrentFeatureFlags::isEnabled('checkout.new-flow'));
        self::assertNotNull(CurrentFeatureFlags::current());
        self::assertFalse(CurrentFeatureFlags::isEnabled('other'));
    }
}
