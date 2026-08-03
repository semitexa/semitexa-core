<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * The reading half every resource renderer needs, regardless of the format it
 * writes.
 *
 * JSON, JSON-LD and GraphQL disagree about almost everything downstream — link
 * shapes, envelopes, how a relationship is spelled — but they all start the same
 * way: pull the identity off a resource, decide whether a relation was asked
 * for, read a public property. That start was written out three times, verbatim.
 *
 * A trait rather than a base class, for two reasons. The renderers are `final`,
 * so there is no hierarchy to join. And a trait is flattened INTO the using
 * class, which means `$this->registry` and `new self()` resolve against that
 * class — where an abstract parent would only have seen what the child chose to
 * expose. (ep-duplication-sweep learned that the hard way one task earlier:
 * hoisting a method into a base class while its state stayed `private` on the
 * children silently disabled a guard, because PHP answers `isset()` on a
 * parent's view of a child's private property with `false` and says nothing.)
 */
trait RendersResourceObjects
{
    /**
     * Supplied by the using class, which must declare:
     *
     *     #[InjectAsReadonly]
     *     protected ResourceMetadataRegistry $registry;
     *
     * NOT declared here: the container refuses #[InjectAs*] inside a trait
     * outright (InjectionAnalyzer, "must be moved to the consuming class"), and
     * it is right to — an injection point that lives in a trait is invisible at
     * the class that actually receives it, so nothing reading the class can tell
     * what the container will hand it. Three identical declarations are the
     * honest cost of that rule.
     */

    /**
     * Build a renderer around a specific registry.
     *
     * Exists for tests that need to render against a hand-built metadata set
     * rather than whatever the container discovered.
     */
    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $renderer = new self();
        $renderer->registry = $registry;

        return $renderer;
    }

    /**
     * The `type` + `id` pair that addresses this resource.
     *
     * Both failures are LogicExceptions rather than soft nulls on purpose: a
     * resource reaching a renderer without a usable identity is a wiring
     * mistake, and the alternative — emitting a document with a null or empty
     * id — produces a response that looks valid and is not addressable.
     */
    private function extractIdentity(
        ResourceObjectInterface $resource,
        ResourceObjectMetadata $metadata,
    ): ResourceIdentity {
        $idField = $metadata->idField;
        if ($idField === null) {
            throw new \LogicException('extractIdentity called on resource without idField.');
        }

        $vars = get_object_vars($resource);
        $id = $vars[$idField] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \LogicException(sprintf(
                'Resource %s::$%s did not yield a non-empty string id.',
                $metadata->class,
                $idField,
            ));
        }

        return new ResourceIdentity($metadata->type, $id);
    }

    /**
     * Should this relation be rendered inline?
     *
     * Only relations the caller actually asked for via `include` are embedded —
     * a field that declares no include name is never embeddable, which is what
     * stops a renderer from walking the whole object graph.
     */
    private function shouldEmbed(ResourceFieldMetadata $field, IncludeSet $includes): bool
    {
        if ($field->include === null) {
            return false;
        }

        return $includes->has($field->include);
    }

    /**
     * Read a public property, or null when the resource does not expose it.
     *
     * `get_object_vars()` from inside the renderer returns only what is public
     * on the resource — which is exactly the intended boundary: a renderer must
     * not reach private state.
     */
    private function readPublicProperty(ResourceObjectInterface $resource, string $name): mixed
    {
        $vars = get_object_vars($resource);

        return $vars[$name] ?? null;
    }
}
