<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

/**
 * Flags for tests: set what is on, inject it where FeatureFlagsInterface is
 * expected. Anything not set is off. forSubject() answers from the same set.
 *
 *     $flags = new InMemoryFeatureFlags(['checkout.new-flow' => true]);
 */
final class InMemoryFeatureFlags implements FeatureFlagsInterface
{
    /** @param array<string, bool> $flags */
    public function __construct(private array $flags = []) {}

    public function enable(string $flag): self
    {
        $this->flags[$flag] = true;

        return $this;
    }

    public function disable(string $flag): self
    {
        $this->flags[$flag] = false;

        return $this;
    }

    public function isEnabled(string $flag): bool
    {
        return $this->flags[$flag] ?? false;
    }

    public function forSubject(?string $tenantId, ?string $userId): FeatureFlagsInterface
    {
        return $this;
    }
}
