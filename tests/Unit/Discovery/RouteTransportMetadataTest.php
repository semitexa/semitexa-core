<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\DiscoveredRoute;

final class RouteTransportMetadataTest extends TestCase
{
    #[Test]
    public function merged_request_attributes_keep_base_transport_when_override_omits_it(): void
    {
        /** @var array{path: string, transport: TransportType} $merged */
        $merged = $this->invokeAttributeDiscoveryStatic(
            'mergeRequestAttributes',
            [
                'path' => '/events',
                'methods' => ['GET'],
                'name' => 'events.stream',
                'requirements' => [],
                'defaults' => [],
                'options' => [],
                'tags' => [],
                'public' => true,
                'responseWith' => null,
                'consumes' => null,
                'produces' => ['text/event-stream'],
                'transport' => TransportType::Sse,
            ],
            [
                'path' => '/events/live',
                'methods' => null,
                'name' => null,
                'requirements' => null,
                'defaults' => null,
                'options' => null,
                'tags' => null,
                'public' => null,
                'responseWith' => null,
                'consumes' => null,
                'produces' => null,
                'transport' => null,
            ],
        );

        self::assertSame('/events/live', $merged['path']);
        self::assertSame(TransportType::Sse, $merged['transport']);
    }

    #[Test]
    public function request_defaults_assign_http_transport_when_missing(): void
    {
        /** @var array{methods: list<string>, transport: TransportType} $defaults */
        $defaults = $this->invokeAttributeDiscoveryStatic(
            'applyRequestDefaults',
            [
                'path' => '/docs',
                'methods' => null,
                'name' => null,
                'requirements' => null,
                'defaults' => null,
                'options' => null,
                'tags' => null,
                'public' => null,
                'responseWith' => null,
                'consumes' => null,
                'produces' => null,
                'transport' => null,
            ],
            'DocsPayload',
            'Semitexa\\Core\\Tests\\Fixture\\DocsPayload',
        );

        self::assertSame(['GET'], $defaults['methods']);
        self::assertSame(TransportType::Http, $defaults['transport']);
    }

    #[Test]
    public function as_payload_keeps_legacy_positional_arguments_compatible(): void
    {
        $attribute = new AsPayload(
            null,
            null,
            null,
            '/docs',
            ['GET'],
            'docs.show',
            null,
            null,
            null,
            null,
            true,
            'http',
            'App\\Response\\DocResponse',
            ['application/json'],
            ['text/html'],
        );

        self::assertSame('App\\Response\\DocResponse', $attribute->responseWith);
        self::assertSame(['application/json'], $attribute->consumes);
        self::assertSame(['text/html'], $attribute->produces);
        self::assertNull($attribute->transport);
    }

    #[Test]
    public function typed_route_metadata_keeps_transport_extension(): void
    {
        $route = DiscoveredRoute::fromArray([
            'path' => '/sse',
            'methods' => ['GET'],
            'name' => 'events.stream',
            'class' => 'App\\Payload\\SsePayload',
            'responseClass' => 'App\\Response\\SseResponse',
            'handlers' => [],
            'type' => 'http-request',
            'transport' => 'sse',
            'produces' => ['text/event-stream'],
            'consumes' => null,
            'module' => 'Ssr',
            'requirements' => [],
            'defaults' => [],
            'options' => [],
            'tags' => [],
            'public' => true,
            'tenantScopes' => [],
        ]);

        $metadata = (new DefaultRouteMetadataResolver())->resolve($route);

        self::assertSame('sse', $route->transport);
        self::assertSame('sse', $metadata->extensions['transport'] ?? null);
    }

    private function invokeAttributeDiscoveryStatic(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(AttributeDiscovery::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }
}
