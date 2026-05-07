<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\Memo\ResolverMemoStore;
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
 * Phase 7 runtime-safety guards. The expansion-scoped resolver memo
 * must NOT loosen the Phase 6f / 6g / 6l invariants and must satisfy
 * its own Swoole-safety contract:
 *
 *   - `ResolverMemoStore` source is pure: no DB / ORM / HTTP /
 *     IriBuilder / renderer / `RelationResolverInterface` / direct
 *     `->resolveBatch(` invocation.
 *   - `ResolverMemoStore` is NOT a container-managed service and
 *     declares no static state. The store is always method-local.
 *   - `ResourceExpansionPipeline` is still the only Resource-layer
 *     file that calls `->resolveBatch(`.
 *   - The pipeline still contains at most TWO `->resolveBatch(` call
 *     sites (Phase 6f top-level + Phase 6g nested). Phase 7 wraps the
 *     existing dispatch sites with a memo split — it does NOT add a
 *     third call site.
 *   - The pipeline instantiates the memo exactly ONCE per
 *     `expandMany()` invocation (i.e. method-local, not on a
 *     long-lived property), and no Resource-layer file outside the
 *     pipeline + the memo source itself instantiates it.
 *   - Two separate `expand()` calls grow the resolver call counter:
 *     the memo is expansion-scoped, so a second call sees a fresh
 *     store and re-asks the resolver. (Cross-request Swoole safety
 *     follows from this.)
 */
final class Phase7RuntimeSafetyTest extends TestCase
{
    private const FORBIDDEN_RUNTIME_TOKENS = [
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
        '->resolveBatch(',
        'RelationResolverInterface',
    ];

    protected function setUp(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    #[Test]
    public function memo_store_source_is_pure(): void
    {
        $abs = realpath(__DIR__ . '/../../../src/Resource/Memo/ResolverMemoStore.php');
        self::assertNotFalse($abs);
        $stripped = $this->stripComments((string) file_get_contents($abs));

        foreach (self::FORBIDDEN_RUNTIME_TOKENS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                'ResolverMemoStore must not reference `' . $needle . '`.',
            );
        }
    }

    #[Test]
    public function memo_store_is_not_a_container_managed_service(): void
    {
        $abs = realpath(__DIR__ . '/../../../src/Resource/Memo/ResolverMemoStore.php');
        $stripped = $this->stripComments((string) file_get_contents((string) $abs));

        // The memo is request-/expansion-scoped. Wiring it through
        // the container would create a worker-lived singleton and
        // leak resolver results across requests on Swoole.
        foreach (['#[AsService]', '#[InjectAsReadonly]', '#[Bind('] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                'ResolverMemoStore must not be wired as a container-managed service (' . $needle . ').',
            );
        }
    }

    #[Test]
    public function memo_store_declares_no_static_state(): void
    {
        $abs = realpath(__DIR__ . '/../../../src/Resource/Memo/ResolverMemoStore.php');
        self::assertNotFalse($abs);
        $tokens = \PhpToken::tokenize((string) file_get_contents($abs));

        // Walk tokens looking for `static $foo` (a static property
        // declaration) — a stricter check than a substring scan.
        $staticBeforeProperty = false;
        for ($i = 0; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            if ($t->is(T_STATIC)) {
                // Look ahead, skipping whitespace, for either T_FUNCTION
                // (allowed: static method) or T_VARIABLE (forbidden:
                // static property).
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    $next = $tokens[$j];
                    if ($next->is([T_WHITESPACE, T_DOC_COMMENT, T_COMMENT])) {
                        continue;
                    }
                    if ($next->is(T_FUNCTION)) {
                        break;
                    }
                    if ($next->is(T_VARIABLE)) {
                        $staticBeforeProperty = true;
                        break;
                    }
                    // Visibility / readonly / type modifiers between
                    // `static` and the name: keep scanning.
                    if ($next->text === 'public' || $next->text === 'protected'
                        || $next->text === 'private' || $next->text === 'readonly'
                        || $next->is([T_STRING, T_NS_SEPARATOR, '?', '|', '&'])) {
                        continue;
                    }
                    break;
                }
                if ($staticBeforeProperty) {
                    break;
                }
            }
        }
        self::assertFalse(
            $staticBeforeProperty,
            'ResolverMemoStore must not declare static properties — '
            . 'static fields would leak resolver values across Swoole requests.',
        );
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
            'Only ResourceExpansionPipeline may invoke ->resolveBatch(); found: '
            . implode(', ', $callers),
        );
        self::assertSame('ResourceExpansionPipeline.php', basename($callers[0]));
    }

    #[Test]
    public function pipeline_still_contains_at_most_two_resolve_batch_call_sites(): void
    {
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php'),
        ));
        $occurrences = substr_count($stripped, '->resolveBatch(');
        self::assertGreaterThanOrEqual(1, $occurrences);
        self::assertLessThanOrEqual(
            2,
            $occurrences,
            'Phase 7 wraps the existing top-level + nested resolveBatch() sites with a memo split. '
            . 'A third call site indicates a regressed per-parent loop or a broken memo path.',
        );
    }

    #[Test]
    public function pipeline_does_not_hold_memo_store_as_property(): void
    {
        $abs = realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php');
        self::assertNotFalse($abs);
        $stripped = $this->stripComments((string) file_get_contents($abs));

        // Property declarations match `<visibility> ResolverMemoStore $name`
        // or `<visibility> readonly ResolverMemoStore $name`. A
        // long-lived property would mean the memo persists across
        // requests on Swoole — the exact bug Phase 7 must not introduce.
        $propertyPatterns = [
            '/(public|protected|private)(\s+(?:readonly|static))*\s+\??ResolverMemoStore\s+\$/',
        ];
        foreach ($propertyPatterns as $pattern) {
            self::assertSame(
                0,
                preg_match($pattern, $stripped),
                'ResourceExpansionPipeline must not hold ResolverMemoStore as a property — '
                . 'the memo is method-local and must not survive across expand() calls.',
            );
        }
    }

    #[Test]
    public function pipeline_instantiates_memo_store_exactly_once(): void
    {
        $abs = realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php');
        self::assertNotFalse($abs);
        $stripped = $this->stripComments((string) file_get_contents($abs));

        self::assertSame(
            1,
            substr_count($stripped, 'new ResolverMemoStore('),
            'ResourceExpansionPipeline must instantiate ResolverMemoStore exactly once — '
            . 'inside expandMany(), so the store is fresh per expansion call.',
        );
    }

    #[Test]
    public function memo_store_is_only_instantiated_inside_the_pipeline(): void
    {
        $resourceDir = realpath(__DIR__ . '/../../../src/Resource');
        self::assertNotFalse($resourceDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resourceDir, \FilesystemIterator::SKIP_DOTS),
        );
        $instantiators = [];
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $stripped = $this->stripComments((string) file_get_contents($entry->getPathname()));
            if (str_contains($stripped, 'new ResolverMemoStore(')) {
                $instantiators[] = basename($entry->getPathname());
            }
        }
        self::assertSame(
            ['ResourceExpansionPipeline.php'],
            $instantiators,
            'Only ResourceExpansionPipeline may instantiate ResolverMemoStore in the Resource layer; '
            . 'found: ' . implode(', ', $instantiators),
        );
    }

    #[Test]
    public function memo_is_expansion_scoped_not_request_scoped(): void
    {
        $pipeline = $this->pipeline();
        $tokens   = IncludeSet::fromQueryString('profile');
        $context  = $this->context($tokens);

        $pipeline->expand($this->customer('1'), $tokens, $context);
        self::assertCount(1, RecordingProfileResolver::$calls);

        // A second expansion of the SAME parent in a SEPARATE call
        // must call the resolver a second time. If the memo were
        // request-scoped (or worker-scoped), this would be 1.
        $pipeline->expand($this->customer('1'), $tokens, $context);
        self::assertCount(
            2,
            RecordingProfileResolver::$calls,
            'Two separate expand() calls must trigger two resolver calls — '
            . 'the memo lives only for one expansion.',
        );
    }

    #[Test]
    public function pipeline_imports_memo_store(): void
    {
        // A trivial-but-load-bearing guard: someone refactoring the
        // pipeline cannot quietly drop the memo path.
        $abs = realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php');
        $stripped = $this->stripComments((string) file_get_contents((string) $abs));

        self::assertStringContainsString(
            'use Semitexa\\Core\\Resource\\Memo\\ResolverMemoStore;',
            $stripped,
            'ResourceExpansionPipeline must import ResolverMemoStore.',
        );
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
