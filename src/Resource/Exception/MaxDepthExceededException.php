<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

/**
 * Diagnostic — not normally raised. The renderer's depth/cycle protection
 * silently downgrades to ref-only output and records a diagnostic. This
 * exception is reserved for misconfigurations where the diagnostic path
 * itself fails (e.g. negative maxDepth).
 */
final class MaxDepthExceededException extends RuntimeException
{
}
