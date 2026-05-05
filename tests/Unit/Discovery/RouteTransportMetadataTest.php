<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
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
                'accessType' => PayloadAccessType::Public,
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
                'accessType' => null,
                'responseWith' => null,
                'consumes' => null,
                'produces' => null,
                'transport' => null,
            ],
        );

        self::assertSame('/events/live', $merged['path']);
        self::assertSame(TransportType::Sse, $merged['transport']);
        self::assertSame(PayloadAccessType::Public, $merged['accessType']);
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
                'accessType' => PayloadAccessType::Public,
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
        self::assertSame(PayloadAccessType::Public, $defaults['accessType']);
    }

    #[Test]
    public function applying_defaults_to_payload_without_access_type_throws(): void
    {
        $this->expectException(\Semitexa\Core\Exception\ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must declare an access attribute/');

        $this->invokeAttributeDiscoveryStatic(
            'applyRequestDefaults',
            [
                'path' => '/no-access',
                'methods' => null,
                'name' => null,
                'requirements' => null,
                'defaults' => null,
                'options' => null,
                'tags' => null,
                'accessType' => null,
                'responseWith' => null,
                'consumes' => null,
                'produces' => null,
                'transport' => null,
            ],
            'NoAccessPayload',
            'Semitexa\\Core\\Tests\\Fixture\\NoAccessPayload',
        );
    }

    #[Test]
    public function as_protected_payload_keeps_named_arguments_compatible(): void
    {
        $attribute = new AsProtectedPayload(
            doc: null,
            base: null,
            overrides: null,
            path: '/docs',
            methods: ['GET'],
            name: 'docs.show',
            requirements: null,
            defaults: null,
            options: null,
            tags: null,
            responseWith: 'App\\Response\\DocResponse',
            consumes: ['application/json'],
            produces: ['text/html'],
        );

        self::assertSame('App\\Response\\DocResponse', $attribute->responseWith);
        self::assertSame(['application/json'], $attribute->consumes);
        self::assertSame(['text/html'], $attribute->produces);
        self::assertNull($attribute->transport);
        self::assertSame(PayloadAccessType::Protected, $attribute->getAccessType());
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
            'accessType' => PayloadAccessType::Public,
            'tenantScopes' => [],
        ]);

        $metadata = (new DefaultRouteMetadataResolver())->resolve($route);

        self::assertSame('sse', $route->transport);
        self::assertSame(PayloadAccessType::Public, $route->accessType);
        self::assertSame('sse', $metadata->extensions['transport'] ?? null);
    }

    private function invokeAttributeDiscoveryStatic(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(AttributeDiscovery::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }
}
