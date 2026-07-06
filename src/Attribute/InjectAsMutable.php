<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Inject a fresh clone of the mutable prototype per get(); RequestContext is injected after clone.
 * Only allowed on protected properties. The type to inject is the property's type hint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsMutable
{
    public function __construct(
        /**
         * Soft dependency: when the container has no binding for the property's
         * type, injection is SKIPPED and the property stays uninitialized — the
         * class must isset-guard access (typically a lazy accessor with a
         * null-object fallback). This replaces the old "nullable property type"
         * convention, which lint:di forbids: the property type stays honest,
         * absence is expressed structurally.
         */
        public bool $optional = false,
    ) {
    }
}
