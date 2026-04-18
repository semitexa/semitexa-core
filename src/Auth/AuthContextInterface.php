<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

interface AuthContextInterface
{
    public function getUser(): ?AuthenticatableInterface;

    public function isGuest(): bool;

    public function setUser(?AuthenticatableInterface $user): void;

    /**
     * Clear the current auth state, leaving the request in a guest state.
     * Implementations should be idempotent.
     */
    public function resetToGuest(): void;

    /**
     * Record the result of the most recent authentication attempt. Successful
     * results must also set the user on this context.
     */
    public function setAuthResult(AuthResult $result): void;

    /**
     * Return the most recent AuthResult recorded on this context, or null if
     * no attempt has been made yet.
     */
    public function getLastResult(): ?AuthResult;

    public static function get(): ?self;

    public static function getOrFail(): self;
}
