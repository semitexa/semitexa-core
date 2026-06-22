<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Contract\CollectionAwareContributorInterface;
use Semitexa\Core\Contract\RouteContractAssemblerInterface;
use Semitexa\Core\Contract\RouteContractBlockContributorInterface;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderProfile;

/**
 * One Way Pattern: the default route contract assembler.
 *
 * Joins the two existing metadata halves for a route — the input side
 * ({@see PayloadMetadataReflector}, served over OPTIONS since Multi-Modal
 * Mode 4) and the output side ({@see ResourceMetadataRegistry}, consumed by
 * OpenAPI) — with named blocks contributed by packages
 * through {@see RouteContractBlockContributorInterface}. Core reads BOTH
 * halves as-is; their semantics are untouched.
 *
 * Contribution is additive only: a contributed block may not shadow a key
 * of the legacy document or the core-owned `input`/`output` blocks, and the
 * first contributor to claim a key wins.
 *
 * Assembly is lazy-with-cache per (payload, response) pair: every input is
 * boot-time attribute data, so the first OPTIONS hit per route pays one
 * reflection pass and every later hit is a dictionary lookup (the design's
 * boot-time intent, realized as first-hit caching to keep worker boot
 * flat across all routes).
 */
#[AsService]
#[SatisfiesServiceContract(of: RouteContractAssemblerInterface::class)]
final class DefaultRouteContractAssembler implements RouteContractAssemblerInterface
{
    /**
     * Keys of the legacy OPTIONS document plus the core-owned contract
     * blocks — contributed blocks may never collide with these.
     */
    private const RESERVED_KEYS = [
        'endpoint', 'methods', 'access', 'transport', 'modes', 'fields',
        'sseGateModel', 'invalidatedBy', 'input', 'output',
    ];

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    /** @var list<RouteContractBlockContributorInterface>|null test-injected contributor set */
    private ?array $fixedContributors = null;

    /** @var array<string, RouteContract> */
    private array $cache = [];

    /**
     * Bypass property injection for unit tests.
     *
     * @param list<RouteContractBlockContributorInterface> $contributors
     */
    public static function forTesting(ResourceMetadataRegistry $registry, array $contributors = []): self
    {
        $assembler = new self();
        $assembler->registry = $registry;
        $assembler->fixedContributors = $contributors;

        return $assembler;
    }

    public function assemble(string $payloadClass, ?string $responseClass): RouteContract
    {
        if ($responseClass === '') {
            $responseClass = null;
        }
        $cacheKey = $payloadClass . '|' . ($responseClass ?? '');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $base = PayloadMetadataReflector::describe($payloadClass);

        $blocks = [];
        $resourceClass = null;
        $isCollection = false;
        foreach ($this->contributors() as $contributor) {
            if ($resourceClass === null) {
                $resourceClass = $contributor->resolveResourceClass($responseClass);
            }
            if (!$isCollection && $contributor instanceof CollectionAwareContributorInterface) {
                $isCollection = $contributor->resolvesCollection($responseClass);
            }
            foreach ($contributor->contributeBlocks($payloadClass, $responseClass) as $key => $block) {
                if (in_array($key, self::RESERVED_KEYS, true) || isset($blocks[$key])) {
                    continue;
                }
                $blocks[$key] = $block;
            }
        }

        // Fallback: a response class that IS a registered resource needs no
        // package vocabulary to link it.
        if ($resourceClass === null && $responseClass !== null && $this->registry->has($responseClass)) {
            $resourceClass = $responseClass;
        }

        $output = null;
        if ($resourceClass !== null) {
            $metadata = $this->registry->get($resourceClass);
            if ($metadata !== null) {
                $output = ResolvedResourceContract::fromMetadata($metadata, $this->registry);
            }
        }

        // One Way Pattern: the contributed search declaration names the
        // free-text param; the search role lights up only for that field.
        $searchParam = $blocks['collection']['search']['param'] ?? null;

        $input = ResolvedPayloadContract::fromReflectedFields(
            is_array($base['fields'] ?? null) ? $base['fields'] : [],
            isset($blocks['collection']),
            self::declaresGraphqlProfile($payloadClass),
            is_string($searchParam) ? $searchParam : null,
        );

        return $this->cache[$cacheKey] = new RouteContract($base, $input, $output, $blocks, $isCollection);
    }

    /** @return list<RouteContractBlockContributorInterface> */
    private function contributors(): array
    {
        if ($this->fixedContributors !== null) {
            return $this->fixedContributors;
        }

        if (isset($this->container) && $this->container instanceof SemitexaContainer) {
            return $this->container->getAllImplementationsOf(RouteContractBlockContributorInterface::class);
        }

        return [];
    }

    /** @param class-string $payloadClass */
    private static function declaresGraphqlProfile(string $payloadClass): bool
    {
        $attrs = (new ReflectionClass($payloadClass))
            ->getAttributes(AbstractPayloadRoute::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return false;
        }

        $profile = $attrs[0]->newInstance()->renderProfile;
        if ($profile instanceof RenderProfile) {
            return $profile === RenderProfile::GraphQL;
        }
        if (is_array($profile)) {
            foreach ($profile as $entry) {
                if ($entry === RenderProfile::GraphQL) {
                    return true;
                }
            }
        }

        return false;
    }
}
