<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\IncludeSet;
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
 * Phase 6g runtime-safety guards. The nested expansion change must
 * NOT loosen existing invariants:
 *
 *   - `ResourceExpansionPipeline` remains the only Resource-layer
 *     class that calls `->resolveBatch(`.
 *   - `IncludeValidator` / `HandlerProvidedIncludeValidator` still
 *     never instantiate resolvers.
 *   - Renderers still never call `->resolveBatch(`.
 *   - Pipeline source remains free of DB / ORM / HTTP / IriBuilder /
 *     renderer concerns.
 *   - The pipeline contains at most two `->resolveBatch(` call sites
 *     (Phase 6f top-level + Phase 6g nested). A third would signal a
 *     regressed per-parent loop or unbounded recursion.
 *   - Nested resolvers fire only when a dotted include token is
 *     requested.
 */
final class Phase6gRuntimeSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    #[Test]
    public function only_pipeline_invokes_resolve_batch_in_resource_layer(): void
    {
        $resourceDir = realpath(__DIR__ . '/../../../src/Resource');
        self::assertNotFalse($resourceDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        $callers = [];
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $stripped = $this->stripComments((string) file_get_contents($entry->getPathname()));
            if (str_contains($stripped, '->resolveBatch(')) {
                $callers[] = $entry->getPathname();
            }
        }

        self::assertCount(
            1,
            $callers,
            'Exactly one Resource-layer file may invoke ->resolveBatch(); found: ' . implode(', ', $callers),
        );
        self::assertSame('ResourceExpansionPipeline.php', basename($callers[0]));
    }

    #[Test]
    public function pipeline_contains_at_most_two_resolve_batch_call_sites(): void
    {
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php'),
        ));

        $occurrences = substr_count($stripped, '->resolveBatch(');
        self::assertGreaterThanOrEqual(1, $occurrences);
        self::assertLessThanOrEqual(
            2,
            $occurrences,
            'Phase 6g introduced one nested call site on top of Phase 6f. A third site indicates '
            . 'a regressed per-parent loop or unbounded recursion.',
        );
    }

    #[Test]
    public function nested_resolver_fires_only_when_dotted_token_requested(): void
    {
        $pipeline = $this->pipeline();

        // Empty includes → zero nested calls.
        $pipeline->expandMany([$this->root('1')], IncludeSet::empty(), $this->context(IncludeSet::empty()));
        self::assertSame([], RecordingPreferencesResolver::$calls);

        // Top-level only → still zero nested calls.
        $pipeline->expandMany(
            [$this->root('1'), $this->root('2')],
            IncludeSet::fromQueryString('profile,addresses'),
            $this->context(IncludeSet::fromQueryString('profile,addresses')),
        );
        self::assertSame(
            [],
            RecordingPreferencesResolver::$calls,
            'Plain top-level includes must never trigger the nested pass.',
        );

        // Dotted token → exactly one nested call.
        $pipeline->expandMany(
            [$this->root('1'), $this->root('2')],
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );
        self::assertCount(1, RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function pipeline_source_does_not_reference_db_orm_http_renderer(): void
    {
        $forbidden = [
            'PDO',
            'Doctrine\\',
            'Semitexa\\Orm\\',
            'curl_',
            'Guzzle',
            'IriBuilder',
            'Semitexa\\Core\\Request',
            'JsonResourceRenderer',
            'JsonLdResourceRenderer',
            'GraphqlResourceRenderer',
        ];

        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php'),
        ));

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                'ResourceExpansionPipeline must not reference `' . $needle . '`.',
            );
        }
    }

    #[Test]
    public function include_validator_still_does_not_instantiate_resolvers(): void
    {
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/IncludeValidator.php'),
        ));

        self::assertStringNotContainsString('->resolveBatch(', $stripped);
        self::assertStringNotContainsString('new RelationResolverInterface', $stripped);
    }

    #[Test]
    public function repeated_dotted_expansion_does_not_leak_overlay_state(): void
    {
        $pipeline = $this->pipeline();
        $tokens   = IncludeSet::fromQueryString('profile.preferences');

        $g1 = $pipeline->expand($this->root('alpha'), $tokens, $this->context($tokens));
        $g2 = $pipeline->expand($this->root('beta'),  $tokens, $this->context($tokens));

        $alpha = ResourceIdentity::of('profile', 'alpha-profile');
        $beta  = ResourceIdentity::of('profile', 'beta-profile');

        self::assertTrue($g1->has($alpha, 'preferences'));
        self::assertFalse($g1->has($beta, 'preferences'));
        self::assertFalse($g2->has($alpha, 'preferences'));
        self::assertTrue($g2->has($beta, 'preferences'));
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

    private function pipeline(): ResourceExpansionPipeline
    {
        $bindings = [
            RecordingProfileResolver::class     => new RecordingProfileResolver(),
            RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
            RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
        ];
        $container = new class ($bindings) implements ContainerInterface {
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
        return ResourceExpansionPipeline::forTesting($this->registry(), $container);
    }

    private function root(string $id = '7'): CustomerWithBothResolvedResource
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

    private function context(IncludeSet $includes): RenderContext
    {
        return new RenderContext(profile: RenderProfile::Json, includes: $includes);
    }

    private function stripComments(string $raw): string
    {
        $tokens   = \PhpToken::tokenize($raw);
        $stripped = '';
        foreach ($tokens as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $stripped .= $token->text;
        }
        return $stripped;
    }
}
