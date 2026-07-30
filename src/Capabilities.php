<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * A degenerate entry, and deliberately so. Every other declaration here answers
 * "you could be missing this"; nobody misses the runtime, because nothing else
 * in the framework loads without it. It is written anyway so that the catalog
 * has no silent hole for a reader to interpret — an absent package reads as an
 * oversight, and the reader cannot tell which absences were decided.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'core.framework',
    summary: 'The runtime every other package builds on: attribute discovery, the two-tier container, routing, events and the console kernel.',
    useWhen: 'Always. It is the floor of a Semitexa project rather than a choice made within one.',
    avoidWhen: 'Never, in a Semitexa project. Listed for completeness, not because the decision is live.',
    replaces: [
        'a container and a route table wired by hand in a bootstrap file',
        'a service locator resolving dependencies at the call site',
    ],
)]
final class Capabilities
{
}
