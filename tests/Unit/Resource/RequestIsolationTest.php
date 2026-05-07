<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\HandlerProvidedIncludeRegistry;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\IriBuilder;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 2.5 explicit Swoole-style isolation guards. Two simulated requests
 * with different inputs must not see each other's state.
 */
final class RequestIsolationTest extends TestCase
{
    private function buildRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        return $registry;
    }

    #[Test]
    public function two_iri_builders_with_different_base_urls_emit_distinct_hrefs(): void
    {
        $registry = $this->buildRegistry();
        $a = IriBuilder::forTesting($registry, 'https://a.example.com');
        $b = IriBuilder::forTesting($registry, 'https://b.example.com');

        self::assertSame('https://a.example.com/customers/7/addresses', $a->forRelation(CustomerResource::class, ['id' => '7'], 'addresses'));
        self::assertSame('https://b.example.com/customers/7/addresses', $b->forRelation(CustomerResource::class, ['id' => '7'], 'addresses'));
    }

    #[Test]
    public function shared_renderer_does_not_leak_render_context_between_calls(): void
    {
        $registry = $this->buildRegistry();
        $renderer = JsonResourceRenderer::forTesting($registry);

        $customerA = new CustomerResource(
            id:        '1',
            name:      'A',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '1'), '/p/1'),
            addresses: ResourceRefList::to('/a/1'),
        );
        $customerB = new CustomerResource(
            id:        '2',
            name:      'B',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '2'), '/p/2'),
            addresses: ResourceRefList::to('/a/2'),
        );

        $ctxA = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());
        $ctxB = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());

        $a1 = $renderer->render($customerA, $ctxA);
        $b1 = $renderer->render($customerB, $ctxB);
        $a2 = $renderer->render($customerA, $ctxA);

        self::assertSame('1', $a1['id']);
        self::assertSame('2', $b1['id']);
        self::assertSame($a1, $a2, 'Repeated rendering of the same DTO under the same context produces stable output.');
    }

    #[Test]
    public function shared_validator_does_not_accumulate_state_across_calls(): void
    {
        $registry        = $this->buildRegistry();
        // Phase 6c: Customer's expandable relations must be either
        // resolver-backed or handler-provided to be satisfiable. The
        // shared-validator isolation property is profile-neutral.
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            'TestPayload' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['addresses', 'profile'],
            ],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);

        $rootMeta = $registry->require(CustomerResource::class);

        // First request: valid include
        $validator->validate(IncludeSet::fromQueryString('addresses'), $rootMeta, 'TestPayload');

        // Second request: the same validator instance must still reject unknown includes.
        $threw = false;
        try {
            $validator->validate(IncludeSet::fromQueryString('orders'), $rootMeta, 'TestPayload');
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'Validator must reject unknown includes regardless of previous state.');

        // Third request: still valid for a known include.
        $validator->validate(IncludeSet::fromQueryString('profile'), $rootMeta, 'TestPayload');
        self::assertTrue(true, 'Validator handled three sequential includes correctly.');
    }

    #[Test]
    public function metadata_registry_is_stable_across_many_renders(): void
    {
        $registry = $this->buildRegistry();
        $renderer = JsonResourceRenderer::forTesting($registry);

        $serializedBefore = serialize($registry->all());

        for ($i = 0; $i < 100; $i++) {
            $cust = new CustomerResource(
                id:        (string) $i,
                name:      'X',
                profile:   ResourceRef::to(ResourceIdentity::of('profile', (string) $i), '/p'),
                addresses: ResourceRefList::to('/a'),
            );
            $renderer->render($cust, new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty()));
        }

        self::assertSame($serializedBefore, serialize($registry->all()));
    }
}
