<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * A package's generic handler bound to an abstract payload base (semitexa/crud's
 * CollectionFeedHandler → CollectionFeed) serves every route that extends it —
 * and a project with no such route yet must still boot. A CONCRETE payload a
 * handler names without a route is still a configuration error.
 */
final class AbstractPayloadHandlerBootTest extends TestCase
{
    #[Test]
    public function a_handler_bound_to_an_abstract_payload_needs_no_route(): void
    {
        $this->assertBoot(AbstractPayloadFixture::class);
        $this->assertBoot(PayloadContractFixture::class);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_handler_bound_to_a_concrete_payload_without_a_route_still_fails(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->assertBoot(ConcretePayloadFixture::class);
    }

    private function assertBoot(string $payloadClass): void
    {
        $handlers = new HandlerRegistry();
        $handlers->register($payloadClass, \stdClass::class, ['class' => 'H', 'payload' => $payloadClass, 'resource' => \stdClass::class, 'execution' => 'sync', 'transport' => null, 'queue' => null, 'priority' => 0]);

        $discovery = (new \ReflectionClass(AttributeDiscovery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($discovery, 'handlerRegistry'))->setValue($discovery, $handlers);
        (new \ReflectionProperty($discovery, 'httpRequests'))->setValue($discovery, []);
        (new \ReflectionMethod($discovery, 'assertPayloadsHaveDiscoveredRoutes'))->invoke($discovery);
    }
}

abstract class AbstractPayloadFixture
{
}

interface PayloadContractFixture
{
}

final class ConcretePayloadFixture
{
}
