<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

/**
 * Marks a class as execution-scoped: a fresh clone is created for each execution.
 * #[InjectAsMutable] properties are re-injected on each clone.
 *
 * Implied by #[AsPayloadHandler], #[AsEventListener], #[AsPipelineListener].
 * Use this attribute for non-handler/listener classes that need per-execution state.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ExecutionScoped {}
