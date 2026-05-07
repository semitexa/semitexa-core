<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6f runtime-safety guards. The list-parent batching change must
 * NOT loosen the existing invariants:
 *
 *   - `ResourceExpansionPipeline` remains the only Resource-layer
 *     class that calls `->resolveBatch(`.
 *   - `IncludeValidator` and `HandlerProvidedIncludeValidator` still
 *     never instantiate resolvers.
 *   - Renderers still never call `->resolveBatch(`.
 *   - The pipeline source must not import DB / ORM / HTTP / IriBuilder
 *     / renderer concerns.
 *   - The pipeline must NOT have introduced a per-parent inner loop
 *     that calls `->resolveBatch(` more than once per bucket — only one
 *     `->resolveBatch(` call site is allowed in the pipeline source.
 */
final class Phase6fRuntimeSafetyTest extends TestCase
{
    #[Test]
    public function pipeline_is_still_the_only_resolve_batch_caller_in_resource_layer(): void
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
    public function pipeline_source_contains_a_bounded_number_of_resolve_batch_call_sites(): void
    {
        // Phase 6f required exactly 1 call site (one per request).
        // Phase 6g introduces a deliberate second call site for the
        // nested expansion pass — the top-level pass and the nested
        // pass each issue one `->resolveBatch(` per bucket. Two is
        // the architectural ceiling for this codebase right now;
        // a third call site would indicate someone has reintroduced
        // a per-parent loop or an unbounded recursion, which is the
        // regression both phases guard against.
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php'),
        ));

        $occurrences = substr_count($stripped, '->resolveBatch(');
        self::assertGreaterThanOrEqual(
            1,
            $occurrences,
            'Pipeline must contain at least one ->resolveBatch() call site.',
        );
        self::assertLessThanOrEqual(
            2,
            $occurrences,
            'Pipeline must contain at most two ->resolveBatch() call sites '
            . '(Phase 6f top-level bucket + Phase 6g nested bucket). A third '
            . 'site signals a regressed per-parent loop or unbounded recursion.',
        );
    }

    #[Test]
    public function expand_many_does_not_call_resolver_once_per_parent(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry  = $this->registry();
        $pipeline  = $this->pipeline($registry);
        $roots     = [];
        for ($i = 1; $i <= 10; $i++) {
            $roots[] = $this->customer((string) $i);
        }
        $includes = IncludeSet::fromQueryString('profile,addresses');

        $pipeline->expandMany($roots, $includes, $this->context($includes));

        // 10 parents × 2 relations naive = 20 calls. Phase 6f promises
        // exactly 1 call per resolver bucket = 2.
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertCount(10, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame(10, RecordingAddressesResolver::$calls[0]['count']);
    }

    #[Test]
    public function unrelated_include_token_does_not_trigger_resolver(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        // 'comments' is a token that no resource on this graph
        // resolves. Both resolvers must stay quiet.
        $includes = IncludeSet::fromQueryString('comments');
        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2')],
            $includes,
            $this->context($includes),
        );

        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
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

        // Validator must not call resolvers. It is metadata-only.
        self::assertStringNotContainsString('->resolveBatch(', $stripped);
        // Validator must not container-instantiate a RelationResolverInterface.
        self::assertStringNotContainsString('new RelationResolverInterface', $stripped);
    }

    #[Test]
    public function resolved_resource_graph_is_still_pure_data(): void
    {
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResolvedResourceGraph.php'),
        ));

        foreach (['->resolveBatch(', 'PDO', 'Doctrine\\', 'Semitexa\\Orm\\', 'curl_', 'JsonResourceRenderer', 'IriBuilder'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                'ResolvedResourceGraph must not reference `' . $needle . '`.',
            );
        }
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
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

    private function pipeline(ResourceMetadataRegistry $registry): ResourceExpansionPipeline
    {
        return ResourceExpansionPipeline::forTesting(
            $registry,
            new class implements ContainerInterface {
                public function get(string $id): object
                {
                    if ($id === RecordingProfileResolver::class) {
                        return new RecordingProfileResolver();
                    }
                    if ($id === RecordingAddressesResolver::class) {
                        return new RecordingAddressesResolver();
                    }
                    throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
                }

                public function has(string $id): bool
                {
                    return in_array($id, [RecordingProfileResolver::class, RecordingAddressesResolver::class], true);
                }
            },
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
