<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
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
 * Phase 4 runtime safety: JSON-LD rendering must be a pure function over
 * the metadata graph + DTO graph. Static source check + runtime
 * non-mutation checks.
 */
final class JsonLdRenderIsolationTest extends TestCase
{
    #[Test]
    public function jsonld_render_pipeline_has_no_io_or_runtime_render_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/JsonLdResourceRenderer.php',
            __DIR__ . '/../../../src/Resource/JsonLdResourceResponse.php',
        ];

        // Match real *usage* — docblocks may legitimately mention the names.
        $forbidden = [
            'use PDO',
            'use Doctrine\\',
            'use Semitexa\\Orm\\',
            'use GuzzleHttp\\',
            'use Psr\\Http\\Client\\',
            'use Semitexa\\Core\\Request;',
            'use Semitexa\\Core\\Resource\\IriBuilder;',
            'use Semitexa\\Core\\Resource\\JsonResourceRenderer;',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 4 file %s must not import %s', basename($path), $needle),
                );
            }
        }
    }

    #[Test]
    public function rendering_does_not_mutate_metadata_registry(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $renderer = JsonLdResourceRenderer::forTesting($registry);

        $countBefore = count($registry->all());
        $hashBefore  = md5(serialize($registry->all()));

        $customer = new CustomerResource(
            id:        '1',
            name:      'X',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '1'), '/p'),
            addresses: ResourceRefList::to('/a'),
        );

        $ctx = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty());
        $renderer->render($customer, $ctx);
        $renderer->render($customer, $ctx);
        $renderer->render($customer, $ctx);

        self::assertSame($countBefore, count($registry->all()));
        self::assertSame($hashBefore, md5(serialize($registry->all())));
    }

    #[Test]
    public function two_contexts_with_different_base_urls_do_not_leak(): void
    {
        // Same DTO + identical baseUrl-less ResourceRef hrefs must yield
        // identical JSON-LD output regardless of RenderContext::$baseUrl,
        // because the renderer never reads baseUrl (that is IriBuilder's
        // job, applied by the handler before the DTO ever reaches the
        // renderer).
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $renderer = JsonLdResourceRenderer::forTesting($registry);
        $customer = new CustomerResource(
            id:        '1',
            name:      'X',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '1'), '/p'),
            addresses: ResourceRefList::to('/a'),
        );

        $ctx1 = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty(), baseUrl: 'https://a.example.com');
        $ctx2 = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty(), baseUrl: 'https://b.example.com');

        self::assertSame(
            $renderer->render($customer, $ctx1),
            $renderer->render($customer, $ctx2),
        );
    }
}
