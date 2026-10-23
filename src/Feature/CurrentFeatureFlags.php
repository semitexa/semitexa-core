<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * The current request's flags, for code that lives as long as the worker and
 * so cannot be injected with them — a template function. Set by
 * CurrentFeatureFlagsListener in the route pipeline and by KissVisitor for a
 * render on the stream (which runs no pipeline); carried into the child
 * coroutines a render spawns. isEnabled() answers off outside a request and
 * inside forSubjectDuring(); the template function feature() then asks the
 * container (FeatureFlagTwigExtension), for the subject named, if any.
 */
final class CurrentFeatureFlags
{
    private const KEY = '__semitexa_feature_flags';
    private const SUBJECT_KEY = '__semitexa_feature_flags_subject';

    public static function set(FeatureFlagsInterface $flags): void
    {
        CarriedContext::carry(self::KEY);
        CoroutineLocal::set(self::KEY, $flags);
    }

    /**
     * Run $render with feature() in its templates answering for this site
     * and user, not the request's or the job's (an injected
     * FeatureFlagsInterface still answers for the request): a mail rendered by a queue worker, which has no
     * tenant, answered with every site's setting, so a flag one site turned
     * off still showed in that site's mail. Both are put back afterwards.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public static function forSubjectDuring(?string $tenantId, ?string $userId, callable $render): mixed
    {
        $flags = CoroutineLocal::get(self::KEY);
        $subject = CoroutineLocal::get(self::SUBJECT_KEY);
        CoroutineLocal::remove(self::KEY);
        // Into the child coroutines a render spawns, as the flags are.
        CarriedContext::carry(self::SUBJECT_KEY);
        CoroutineLocal::set(self::SUBJECT_KEY, [$tenantId, $userId]);
        try {
            return $render();
        } finally {
            $flags === null ? CoroutineLocal::remove(self::KEY) : CoroutineLocal::set(self::KEY, $flags);
            $subject === null ? CoroutineLocal::remove(self::SUBJECT_KEY) : CoroutineLocal::set(self::SUBJECT_KEY, $subject);
        }
    }

    /** @return array{0: ?string, 1: ?string}|null the subject forSubjectDuring() names */
    public static function subject(): ?array
    {
        $subject = CoroutineLocal::get(self::SUBJECT_KEY);

        return is_array($subject) ? $subject : null;
    }

    /** The flags set for this request, or null outside one. */
    public static function current(): ?FeatureFlagsInterface
    {
        $flags = CoroutineLocal::get(self::KEY);

        return $flags instanceof FeatureFlagsInterface ? $flags : null;
    }

    public static function isEnabled(string $flag): bool
    {
        $flags = CoroutineLocal::get(self::KEY);

        return $flags instanceof FeatureFlagsInterface && $flags->isEnabled($flag);
    }
}
