<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\GraphqlResourceResponse;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonLdResourceResponse;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6h: collection response rendering across the three profiles.
 *
 * The tests build collection responses directly with fixture roots
 * (no HTTP), exercising the `withResources()` API end to end. Each
 * test asserts:
 *
 *   - the right resolver(s) fire — once per bucket, never per parent;
 *   - per-parent overlay slots are correctly applied to each rendered
 *     item;
 *   - empty collections produce the right empty envelope per profile;
 *   - the singular `withResource()` API still produces byte-identical
 *     output for the regression check on the existing route.
 */
final class Phase6hCollectionResponseTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerWithBothResolvedResource::class));
        return $registry;
    }

    private function customer(string $id): CustomerWithBothResolvedResource
    {
        return new CustomerWithBothResolvedResource(
            id: $id,
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', $id . '-profile'),
                "/x/{$id}/profile",
            ),
            addresses: ResourceRefList::to("/x/{$id}/addresses"),
        );
    }

    /** @return list<CustomerWithBothResolvedResource> */
    private function customers(): array
    {
        return [$this->customer('1'), $this->customer('2'), $this->customer('3')];
    }

    /** @param array<class-string, object> $bindings */
    private function container(array $bindings): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            /** @param array<class-string, object> $b */
            public function __construct(private array $b) {}

            public function get(string $id): object
            {
                if (!array_key_exists($id, $this->b)) {
                    throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
                }
                return $this->b[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->b);
            }
        };
    }

    private function pipeline(ResourceMetadataRegistry $registry): ResourceExpansionPipeline
    {
        return ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
    }

    private function jsonResponse(ResourceMetadataRegistry $registry): JsonResourceResponse
    {
        return (new JsonResourceResponse())->bindServices(
            JsonResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    private function jsonLdResponse(ResourceMetadataRegistry $registry): JsonLdResourceResponse
    {
        return (new JsonLdResourceResponse())->bindServices(
            JsonLdResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    private function graphqlResponse(ResourceMetadataRegistry $registry): GraphqlResourceResponse
    {
        return (new GraphqlResourceResponse())->bindServices(
            GraphqlResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    /** @return array{registry: ResourceMetadataRegistry, body: array<string, mixed>} */
    private function renderJsonCollection(IncludeSet $includes, array $customers): array
    {
        $registry = $this->registry();
        $response = $this->jsonResponse($registry);
        $context  = new RenderContext(
            profile:  RenderProfile::Json,
            includes: $includes,
        );
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return ['registry' => $registry, 'body' => $body];
    }

    /** @return array{registry: ResourceMetadataRegistry, body: array<string, mixed>} */
    private function renderJsonLdCollection(IncludeSet $includes, array $customers): array
    {
        $registry = $this->registry();
        $response = $this->jsonLdResponse($registry);
        $context  = new RenderContext(
            profile:  RenderProfile::JsonLd,
            includes: $includes,
        );
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return ['registry' => $registry, 'body' => $body];
    }

    /** @return array{registry: ResourceMetadataRegistry, body: array<string, mixed>} */
    private function renderGraphqlCollection(IncludeSet $includes, array $customers, string $rootField = 'customers'): array
    {
        $registry = $this->registry();
        $response = $this->graphqlResponse($registry);
        $context  = new RenderContext(
            profile:  RenderProfile::GraphQL,
            includes: $includes,
        );
        $response->withResources($rootField, $customers, $context, CustomerWithBothResolvedResource::class);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return ['registry' => $registry, 'body' => $body];
    }

    // ----- JSON ------------------------------------------------------

    #[Test]
    public function json_collection_no_include_renders_data_array(): void
    {
        $r = $this->renderJsonCollection(IncludeSet::empty(), $this->customers());
        $body = $r['body'];

        self::assertSame(['data'], array_keys($body));
        self::assertCount(3, $body['data']);
        self::assertSame('1', $body['data'][0]['id']);
        self::assertSame('3', $body['data'][2]['id']);
        // Reference-only profile — no `data` key.
        self::assertArrayNotHasKey('data', $body['data'][0]['profile']);
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function json_collection_include_profile_batches_and_embeds_per_customer(): void
    {
        $r = $this->renderJsonCollection(
            IncludeSet::fromQueryString('profile'),
            $this->customers(),
        );
        $body = $r['body'];

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(3, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);

        foreach ($body['data'] as $i => $item) {
            self::assertSame($item['profile']['data']['id'], $item['id'] . '-profile');
        }
    }

    #[Test]
    public function json_collection_include_addresses_batches_and_embeds_per_customer(): void
    {
        $r = $this->renderJsonCollection(
            IncludeSet::fromQueryString('addresses'),
            $this->customers(),
        );
        $body = $r['body'];

        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertSame(3, RecordingAddressesResolver::$calls[0]['count']);
        self::assertSame([], RecordingProfileResolver::$calls);

        foreach ($body['data'] as $item) {
            self::assertCount(2, $item['addresses']['data']);
            self::assertStringStartsWith($item['id'] . '-', $item['addresses']['data'][0]['id']);
        }
    }

    #[Test]
    public function json_collection_include_profile_preferences_runs_two_batches_in_order(): void
    {
        $r = $this->renderJsonCollection(
            IncludeSet::fromQueryString('profile.preferences'),
            $this->customers(),
        );
        $body = $r['body'];

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(3, RecordingProfileResolver::$calls[0]['parents']);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertCount(3, RecordingPreferencesResolver::$calls[0]['parents']);
        self::assertSame([], RecordingAddressesResolver::$calls);

        foreach ($body['data'] as $item) {
            self::assertSame(
                'prefs-' . $item['id'] . '-profile',
                $item['profile']['data']['preferences']['data']['id'],
            );
        }
    }

    #[Test]
    public function json_empty_collection_renders_empty_data_array_and_invokes_no_resolver(): void
    {
        $r = $this->renderJsonCollection(IncludeSet::fromQueryString('profile'), []);
        self::assertSame(['data' => []], $r['body']);
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    // ----- JSON-LD ---------------------------------------------------

    #[Test]
    public function jsonld_collection_no_include_renders_context_and_graph(): void
    {
        $r = $this->renderJsonLdCollection(IncludeSet::empty(), $this->customers());
        $body = $r['body'];

        self::assertSame(['@context', '@graph'], array_keys($body));
        self::assertSame(JsonLdResourceRenderer::DEFAULT_VOCAB, $body['@context']);
        self::assertCount(3, $body['@graph']);
        // Each node is fully specified — no inner `@context`.
        foreach ($body['@graph'] as $node) {
            self::assertArrayNotHasKey('@context', $node);
            self::assertArrayHasKey('@type', $node);
            self::assertSame('phase6e_customer', $node['@type']);
        }
    }

    #[Test]
    public function jsonld_collection_with_nested_include_embeds_preferences_per_customer(): void
    {
        $r = $this->renderJsonLdCollection(
            IncludeSet::fromQueryString('profile.preferences'),
            $this->customers(),
        );
        $body = $r['body'];

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);

        foreach ($body['@graph'] as $node) {
            self::assertArrayHasKey('profile', $node);
            self::assertArrayHasKey('preferences', $node['profile']);
            self::assertSame('phase6g_preferences', $node['profile']['preferences']['@type']);
        }
    }

    #[Test]
    public function jsonld_empty_collection_renders_context_and_empty_graph(): void
    {
        $r = $this->renderJsonLdCollection(IncludeSet::empty(), []);
        self::assertSame(
            ['@context' => JsonLdResourceRenderer::DEFAULT_VOCAB, '@graph' => []],
            $r['body'],
        );
    }

    // ----- GraphQL ---------------------------------------------------

    #[Test]
    public function graphql_collection_no_include_renders_data_root_array(): void
    {
        $r = $this->renderGraphqlCollection(IncludeSet::empty(), $this->customers());
        $body = $r['body'];

        self::assertSame(['data'], array_keys($body));
        self::assertSame(['customers'], array_keys($body['data']));
        self::assertCount(3, $body['data']['customers']);
        self::assertSame('1', $body['data']['customers'][0]['id']);
    }

    #[Test]
    public function graphql_collection_with_nested_include_embeds_preferences(): void
    {
        $r = $this->renderGraphqlCollection(
            IncludeSet::fromQueryString('profile.preferences'),
            $this->customers(),
        );
        $body = $r['body'];

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);

        foreach ($body['data']['customers'] as $node) {
            self::assertSame(
                'prefs-' . $node['id'] . '-profile',
                $node['profile']['preferences']['id'],
            );
        }
    }

    #[Test]
    public function graphql_empty_collection_renders_empty_array_under_data_root(): void
    {
        $r = $this->renderGraphqlCollection(IncludeSet::empty(), []);
        self::assertSame(['data' => ['customers' => []]], $r['body']);
    }

    // ----- Resolver invocation contract -------------------------------

    #[Test]
    public function collection_with_profile_and_addresses_calls_each_resolver_exactly_once(): void
    {
        $this->renderJsonCollection(
            IncludeSet::fromQueryString('profile,addresses'),
            $this->customers(),
        );
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function collection_with_profile_preferences_and_addresses_calls_three_buckets(): void
    {
        $this->renderJsonCollection(
            IncludeSet::fromQueryString('profile.preferences,addresses'),
            $this->customers(),
        );
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);
    }

    // ----- Per-parent isolation + DTO immutability --------------------

    #[Test]
    public function per_parent_overlay_slots_have_no_data_leakage(): void
    {
        $customers = [$this->customer('alice'), $this->customer('bob')];
        $r = $this->renderJsonCollection(
            IncludeSet::fromQueryString('addresses'),
            $customers,
        );
        $body = $r['body'];

        $alice = $body['data'][0]['addresses']['data'];
        $bob   = $body['data'][1]['addresses']['data'];
        foreach ($alice as $a) {
            self::assertStringStartsWith('alice-', $a['id']);
        }
        foreach ($bob as $a) {
            self::assertStringStartsWith('bob-', $a['id']);
        }
    }

    #[Test]
    public function repeated_collection_renders_are_deterministic(): void
    {
        $customers = $this->customers();
        $first  = $this->renderJsonCollection(IncludeSet::fromQueryString('profile.preferences'), $customers);
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
        $second = $this->renderJsonCollection(IncludeSet::fromQueryString('profile.preferences'), $customers);

        self::assertSame($first['body'], $second['body']);
    }

    #[Test]
    public function collection_does_not_mutate_root_dtos(): void
    {
        $customers = $this->customers();
        $snapshot  = [];
        foreach ($customers as $c) {
            $snapshot[$c->id] = [
                'profile_data'   => $c->profile?->data,
                'addresses_data' => $c->addresses->data,
            ];
        }

        $this->renderJsonCollection(
            IncludeSet::fromQueryString('profile.preferences,addresses'),
            $customers,
        );

        foreach ($customers as $c) {
            self::assertSame($snapshot[$c->id]['profile_data'], $c->profile?->data);
            self::assertSame($snapshot[$c->id]['addresses_data'], $c->addresses->data);
        }
    }

    #[Test]
    public function singular_with_resource_path_remains_byte_identical_when_collection_class_is_loaded(): void
    {
        // Regression: importing JsonResourceResponse to add withResources()
        // must not change the byte output of the existing single-resource
        // path.
        $registry = $this->registry();
        $renderer = JsonResourceRenderer::forTesting($registry);
        $pipeline = $this->pipeline($registry);
        $response = (new JsonResourceResponse())->bindServices(
            $renderer,
            $registry,
            IncludeValidator::forTesting($registry),
            $pipeline,
        );

        $context = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::fromQueryString('profile'));
        $response->withResource($this->customer('7'), $context);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['data'], array_keys($body));
        self::assertSame('7', $body['data']['id']);
        self::assertSame('7-profile', $body['data']['profile']['data']['id']);
    }
}
