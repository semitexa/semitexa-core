<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Declares that an attribute class represents a framework capability, and
 * describes it well enough for tooling to offer it to whoever is building
 * something.
 *
 * The framework already knows what it can do — `#[AsDeferred]`, `#[AsComponent]`,
 * `#[WithTransport]` and their peers are a machine-readable vocabulary of
 * abilities. What was missing is anywhere that vocabulary is *spoken*: the
 * generators never mention it, the verifier never mentions it, and the agent
 * instructions shipped to consumer projects mentioned none of it at all. So a
 * capability that exists and works goes unused, and the same thing gets
 * hand-rolled instead — a `fetch()` against a bespoke JSON route where a
 * deferred slot was already available.
 *
 * Two shapes, one attribute. On an attribute class it describes a MECHANISM —
 * `#[AsDeferred]` and its peers, written into application code. On any other
 * class it describes what a PACKAGE offers, which is the only way to advertise
 * something like `semitexa/files` or `semitexa/weave`: those ship no attributes
 * at all, so a mechanism-only vocabulary cannot see them. By convention a
 * package declares these on a single `Capabilities` class, so there is one
 * definite place to look and for a guard to check.
 *
 * Repeatable, because one such class may own several feature areas.
 *
 * It lives next to the thing it describes on purpose: a catalog kept in a
 * separate file drifts from the code the first time someone adds a parameter
 * and forgets, and a stale catalog is worse than none — it teaches the wrong
 * thing confidently.
 *
 * ## What counts as a capability
 *
 * An ability someone can **miss**: the work gets finished without it, worse.
 * That is the whole test, and it has an operational form — a capability can
 * name what someone would have built by hand instead. If no such alternative
 * can be written down, the thing is not missable and does not belong here.
 *
 * `#[AsDeferred]` qualifies: without it people write a `fetch` into
 * `innerHTML`. `#[InjectAsReadonly]` does not: nobody builds a service without
 * meeting it, and there is no worse thing they would have reached for instead.
 *
 * This is why `replaces` is required rather than optional. It is not
 * documentation — it is the evidence that the entry is worth advertising at
 * all, and it is what the verify rules key on. An attribute that is plumbing
 * carries {@see InternalAttribute} instead, with the reason recorded next to
 * the class rather than in a list that drifts.
 *
 * The criterion was not invented for this docblock: every one of the eighteen
 * capabilities declared when it was written already names an alternative.
 *
 * Nothing reads this at runtime. It exists for tooling: `ai:ask capabilities`
 * derives its catalog from these declarations across every installed package,
 * which is what lets a consumer project pick up a capability added later from
 * `composer update` alone, with no edit to that project.
 *
 * ```php
 * #[Capability(
 *     id: 'ssr.deferred',
 *     summary: 'Renders a region after the main page and streams it in when ready.',
 *     useWhen: 'A region is slow enough to delay first paint — an external call, a heavy query.',
 *     replaces: ['client-side fetch() to a bespoke JSON route', 'blocking the page on a slow query'],
 * )]
 * #[Attribute(Attribute::TARGET_CLASS)]
 * final class AsDeferred { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Capability
{
    /**
     * @param string $id      Stable catalog id, `<area>.<name>` (`ssr.deferred`).
     *                        Referenced by verify findings, so renaming one
     *                        breaks a published pointer — treat as an API.
     * @param string $summary One line: what the capability does.
     * @param string $useWhen The situation that should make someone reach for
     *                        it. This is the half that decides whether it gets
     *                        used, so it describes a circumstance, not a
     *                        feature.
     * @param string $avoidWhen The situation where reaching for it is the wrong
     *                        call. Mirrors the `Avoid when` field the command
     *                        catalog already uses, and earns its place: a
     *                        capability described only by its upside gets
     *                        applied everywhere, and over-application discredits
     *                        the catalog faster than omission does.
     * @param list<string> $replaces The hand-rolled equivalents someone would
     *                        otherwise write. Required, and not merely for the
     *                        verify rules that key on it: being unable to name
     *                        one means the ability cannot be missed, which
     *                        means it is plumbing and belongs behind
     *                        {@see InternalAttribute}. Each entry must name
     *                        something detectable in code rather than a vague
     *                        alternative.
     * @param string $seeAlso Optional pointer to a related capability id.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly string $useWhen,
        public readonly string $avoidWhen,
        public readonly array $replaces,
        public readonly string $seeAlso = '',
    ) {
    }
}
