<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

/**
 * @deprecated Use #[ExecutionScoped] instead. This attribute is scheduled for removal.
 *
 * Legacy alias for the same execution-scoped lifecycle now expressed by #[ExecutionScoped].
 *
 * Previously marked a class as request-scoped (mutable). The container clones a
 * prototype of this class per execution and injects execution-context values.
 *
 * Migration: Replace #[AsMutable] with #[ExecutionScoped] on the class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsMutable
{
}
