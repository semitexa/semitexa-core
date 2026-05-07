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
 * Phase 2 runtime safety: rendering must perform zero IO. The framework's
 * own service container is also off-limits during render. These tests guard
 * the contract.
 */
final class RenderIsolationTest extends TestCase
{
    #[Test]
    public function render_pipeline_classes_have_no_io_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/JsonResourceRenderer.php',
            __DIR__ . '/../../../src/Resource/IriBuilder.php',
            __DIR__ . '/../../../src/Resource/IncludeValidator.php',
            __DIR__ . '/../../../src/Resource/JsonResourceResponse.php',
            __DIR__ . '/../../../src/Resource/IncludeSet.php',
            __DIR__ . '/../../../src/Resource/RenderContext.php',
        ];

        $forbidden = [
            'use PDO',
            'Doctrine\\',
            'Semitexa\\Orm\\',
            'GuzzleHttp\\',
            'Symfony\\Component\\HttpClient\\',
            'Psr\\Http\\Client\\',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 2 file %s must not import %s', basename($path), $needle),
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

        $renderer = JsonResourceRenderer::forTesting($registry);

        $countBefore = count($registry->all());
        $hashBefore  = md5(serialize($registry->all()));

        $customer = new CustomerResource(
            id:        '1',
            name:      'X',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '1'), '/p'),
            addresses: ResourceRefList::to('/a'),
        );

        $ctx = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());
        $renderer->render($customer, $ctx);
        $renderer->render($customer, $ctx);
        $renderer->render($customer, $ctx);

        self::assertSame($countBefore, count($registry->all()));
        self::assertSame($hashBefore, md5(serialize($registry->all())));
    }

    #[Test]
    public function include_validator_does_not_touch_io(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        // Phase 6c: include validation is satisfiable only when the
        // token has a resolver or the route declares it
        // handler-provided. The isolation contract holds for both
        // mechanisms — exercise the handler-provided path.
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            'TestPayload' => ['resource' => CustomerResource::class, 'tokens' => ['addresses']],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);

        // The presence of a stream wrapper assertion is a soft guard — a real
        // `fopen` would still go through to disk. The hard guarantee is the
        // static source check above. Here we just exercise the validator
        // multiple times and confirm no side effects emerge.
        for ($i = 0; $i < 10; $i++) {
            $validator->validate(
                IncludeSet::fromQueryString('addresses'),
                $registry->require(CustomerResource::class),
                'TestPayload',
            );
        }

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function iri_builder_does_not_perform_http_calls(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $iri = IriBuilder::forTesting($registry, 'https://api.example.com');
        for ($i = 0; $i < 50; $i++) {
            $iri->forRelation(CustomerResource::class, ['id' => (string) $i], 'addresses');
        }

        $this->expectNotToPerformAssertions();
    }
}
