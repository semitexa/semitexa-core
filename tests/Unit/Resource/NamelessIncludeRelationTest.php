<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AliasProfileCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * A relation whose metadata carries no include name, rendered with its data
 * present. The JSON and JSON-LD renderers handed that null to
 * IncludeSet::nested(string) — a TypeError under strict_types. Nothing below
 * such a relation can have been requested, so it renders with no includes.
 */
final class NamelessIncludeRelationTest extends TestCase
{
    /** @return iterable<string, array{class-string, RenderProfile}> */
    public static function renderers(): iterable
    {
        yield 'json' => [JsonResourceRenderer::class, RenderProfile::Json];
        yield 'json-ld' => [JsonLdResourceRenderer::class, RenderProfile::JsonLd];
    }

    /** @param class-string<JsonResourceRenderer|JsonLdResourceRenderer> $renderer */
    #[Test]
    #[DataProvider('renderers')]
    public function a_relation_without_an_include_name_renders_its_data(string $renderer, RenderProfile $profile): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $meta   = $extractor->extract(AliasProfileCustomerResource::class);
        $field  = $meta->fields['authorProfile'];
        $fields = $meta->fields;
        $fields['authorProfile'] = new ResourceFieldMetadata(
            name:         $field->name,
            kind:         $field->kind,
            nullable:     $field->nullable,
            target:       $field->target,
            include:      null,
            hrefTemplate: $field->hrefTemplate,
            expandable:   $field->expandable,
        );
        $registry->register(new ResourceObjectMetadata($meta->class, $meta->type, $meta->idField, $fields));

        $customer = new AliasProfileCustomerResource(
            id:            '1',
            authorProfile: ResourceRef::embed(
                ResourceIdentity::of('profile', 'p1'),
                new ProfileResource(
                    id:          'p1',
                    bio:         'bio',
                    preferences: ResourceRef::to(ResourceIdentity::of('preferences', 'pr1'), '/profiles/p1/preferences'),
                ),
            ),
        );

        $out = $renderer::forTesting($registry)->render(
            $customer,
            new RenderContext(profile: $profile, includes: IncludeSet::fromQueryString('')),
        );

        self::assertStringContainsString('"bio":"bio"', (string) json_encode($out), 'the nested profile rendered');
    }
}
