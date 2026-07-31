<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks an attribute class as framework plumbing that is deliberately NOT
 * advertised as a capability.
 *
 * The capability catalog exists so someone building a feature learns about an
 * ability they might otherwise miss. Most attributes are not that. `#[AsService]`
 * and `#[InjectAsReadonly]` are how anything gets built here at all — nobody
 * writes a service without meeting them, and there is no worse alternative they
 * would have reached for instead. Listing them would bury the handful of
 * genuinely missable mechanisms under a reflection dump, and a catalog that is
 * tedious to read stops being read.
 *
 * This exists so the choice is *recorded* rather than merely omitted. The guard
 * in each package requires every attribute class to carry either
 * `#[Capability]` or this, so a new attribute cannot slip through undecided —
 * which is how a real capability would quietly become invisible. An exclusion
 * list kept in a test file would drift from the code the first time someone
 * added an attribute; a marker next to the class cannot.
 *
 * ```php
 * #[InternalAttribute('Dependency injection plumbing: every container-managed
 *     class meets it, and there is no hand-rolled alternative to describe.')]
 * #[Attribute(Attribute::TARGET_PROPERTY)]
 * final class InjectAsReadonly { ... }
 * ```
 *
 * @see Capability for the test that separates the two.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InternalAttribute
{
    /**
     * @param string $reason Why this is plumbing rather than a capability.
     *                       Written for the next person deciding the same
     *                       question about a neighbouring attribute, so it
     *                       should say what makes this one unmissable — not
     *                       merely that it is "internal".
     */
    public function __construct(
        public readonly string $reason,
    ) {
    }
}
