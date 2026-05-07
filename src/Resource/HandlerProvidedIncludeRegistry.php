<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use ReflectionClass;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Attribute\HandlerProvidesResourceIncludes;

/**
 * Phase 6c: worker-scoped registry of payload-class →
 * `#[HandlerProvidesResourceIncludes]` declarations.
 *
 * Built lazily on first lookup (or eagerly via `ensureBuilt()`) by
 * scanning the composer classmap for classes carrying the attribute.
 * The registry stores **normalized** token lists (lowercase, deduped,
 * sorted) and the declared root resource class, so:
 *
 *   - duplicate tokens collapse,
 *   - case differences do not produce false negatives,
 *   - downstream consumers (`IncludeValidator`,
 *     `HandlerProvidedIncludeValidator`) iterate a deterministic list.
 *
 * The registry never instantiates the payload, never reads request
 * data, never queries the container at lookup time.
 */
#[AsService]
final class HandlerProvidedIncludeRegistry
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /** @var array<class-string, list<string>> normalized tokens per payload class */
    private array $tokensByPayload = [];

    /** @var array<class-string, class-string> declared root resource per payload */
    private array $resourceByPayload = [];

    private bool $built = false;

    public static function forTesting(ClassDiscovery $classDiscovery): self
    {
        $r = new self();
        $r->classDiscovery = $classDiscovery;
        return $r;
    }

    /**
     * Test factory that bypasses discovery entirely. Useful for unit
     * tests of `IncludeValidator` and the renderer wiring that don't
     * exercise classmap scanning.
     *
     * @param array<class-string, array{resource: class-string, tokens: list<string>}> $declarations
     */
    public static function withDeclarations(array $declarations): self
    {
        $r = new self();
        foreach ($declarations as $payloadClass => $entry) {
            $r->tokensByPayload[$payloadClass]   = $r->normalize($entry['tokens']);
            $r->resourceByPayload[$payloadClass] = $entry['resource'];
        }
        $r->built = true;
        return $r;
    }

    public function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }

        /** @var list<class-string> $classes */
        $classes = $this->classDiscovery->findClassesWithAttribute(HandlerProvidesResourceIncludes::class);

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $attrs      = $reflection->getAttributes(HandlerProvidesResourceIncludes::class);

            // First attribute wins. Multiple declarations on the same
            // class are unusual — `lint:resources` flags any structural
            // problem; the runtime is intentionally tolerant.
            if ($attrs === []) {
                continue;
            }

            /** @var HandlerProvidesResourceIncludes $h */
            $h = $attrs[0]->newInstance();

            $this->tokensByPayload[$class]   = $this->normalize($h->tokens);
            $this->resourceByPayload[$class] = $h->resource;
        }

        $this->built = true;
    }

    /**
     * @param class-string $payloadClass
     * @return list<string> normalized token list, or `[]` when the
     *                      payload has no declaration
     */
    public function tokensFor(string $payloadClass): array
    {
        $this->ensureBuilt();
        return $this->tokensByPayload[$payloadClass] ?? [];
    }

    /**
     * @param class-string $payloadClass
     * @return class-string|null declared root resource class, or null
     *                            when the payload has no declaration
     */
    public function resourceFor(string $payloadClass): ?string
    {
        $this->ensureBuilt();
        return $this->resourceByPayload[$payloadClass] ?? null;
    }

    /** @return array<class-string, list<string>> all declarations, payload class → tokens */
    public function all(): array
    {
        $this->ensureBuilt();
        return $this->tokensByPayload;
    }

    /** @return array<class-string, class-string> all declarations, payload class → resource class */
    public function allResources(): array
    {
        $this->ensureBuilt();
        return $this->resourceByPayload;
    }

    public function reset(): void
    {
        $this->tokensByPayload   = [];
        $this->resourceByPayload = [];
        $this->built             = false;
    }

    /**
     * @param array<int, string> $tokens
     * @return list<string>
     */
    private function normalize(array $tokens): array
    {
        $seen = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }
            $seen[$token] = true;
        }

        $out = array_keys($seen);
        sort($out, SORT_STRING);
        return $out;
    }
}
