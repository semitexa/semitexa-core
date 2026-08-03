<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeChainResolver;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\PayloadAttributeSchema;
use Semitexa\Core\Discovery\DiscoveredRoute;

final class RouteTransportMetadataTest extends TestCase
{
    #[Test]
    public function merged_request_attributes_keep_base_transport_when_override_omits_it(): void
    {
        // Was a reflection call into AttributeDiscovery::mergeRequestAttributes.
        // ep-slay-attribute-discovery moved that merge into AttributeChainResolver,
        // so this now exercises the real path a payload takes — declare a base,
        // override only the path — instead of poking a private in isolation.
        $cache = [];
        /** @var array{path: string, transport: TransportType, accessType: PayloadAccessType} $merged */
        $merged = (new AttributeChainResolver(new PayloadAttributeSchema()))->resolve(
            'App\\Payload\\LiveEventsPayload',
            [
                'App\\Payload\\EventsPayload' => [
                    'class' => 'App\\Payload\\EventsPayload',
                    'short' => 'EventsPayload',
                    'attr' => self::payloadAttributes([
                        'path' => '/events',
                        'name' => 'events.stream',
                        'accessType' => PayloadAccessType::Public,
                        'produces' => ['text/event-stream'],
                        'transport' => TransportType::Sse,
                    ]),
                ],
                'App\\Payload\\LiveEventsPayload' => [
                    'class' => 'App\\Payload\\LiveEventsPayload',
                    'short' => 'LiveEventsPayload',
                    'attr' => self::payloadAttributes([
                        'base' => 'App\\Payload\\EventsPayload',
                        'path' => '/events/live',
                    ]),
                ],
            ],
            $cache,
        );

        self::assertSame('/events/live', $merged['path']);
        self::assertSame(TransportType::Sse, $merged['transport']);
        self::assertSame(PayloadAccessType::Public, $merged['accessType']);
    }

    #[Test]
    public function request_defaults_assign_http_transport_when_missing(): void
    {
        /** @var array{methods: list<string>, transport: TransportType, accessType: PayloadAccessType} $defaults */
        $defaults = (new PayloadAttributeSchema())->applyDefaults(
            self::payloadAttributes([
                'path' => '/docs',
                'accessType' => PayloadAccessType::Public,
            ]),
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

        (new PayloadAttributeSchema())->applyDefaults(
            self::payloadAttributes(['path' => '/no-access']),
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
            'path' => '/events/stream',
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

    /**
     * A full payload attribute map with every slot present and null, overlaid
     * with whatever the case under test declares.
     *
     * Discovery always hands the resolver a complete map — every key present,
     * unset ones null — so building fixtures that way keeps these tests honest
     * about the shape the production code actually sees.
     *
     * @param  array<string, mixed> $declared
     * @return array<string, mixed>
     */
    private static function payloadAttributes(array $declared): array
    {
        $empty = ['base' => null, 'overrides' => null];
        foreach ((new PayloadAttributeSchema())->mergeableKeys() as $key) {
            $empty[$key] = null;
        }

        return array_replace($empty, $declared);
    }
}
