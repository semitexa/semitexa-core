<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

final readonly class IncludeSet
{
    /** @param list<string> $tokens normalized, deduped, sorted */
    public function __construct(public array $tokens)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromQueryString(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return new self([]);
        }

        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $normalized = [];
        foreach ($parts as $token) {
            $token = strtolower(trim($token));
            if ($token === '') {
                continue;
            }
            $normalized[$token] = true;
        }

        $tokens = array_keys($normalized);
        sort($tokens);

        return new self($tokens);
    }

    public function has(string $token): bool
    {
        return in_array(strtolower($token), $this->tokens, true);
    }

    public function nested(string $prefix): self
    {
        $prefix = strtolower($prefix);
        $needle = $prefix . '.';
        $children = [];
        foreach ($this->tokens as $token) {
            if (str_starts_with($token, $needle)) {
                $children[] = substr($token, strlen($needle));
            }
        }

        return new self($children);
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }
}
