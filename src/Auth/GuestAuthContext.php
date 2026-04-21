<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

use Semitexa\Core\Support\CoroutineLocal;

final class GuestAuthContext implements AuthContextInterface
{
    private const LAST_RESULT_KEY = 'semitexa.core.auth.guest.last_result';

    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getUser(): ?AuthenticatableInterface
    {
        return null;
    }

    public function isGuest(): bool
    {
        return true;
    }

    public function setUser(?AuthenticatableInterface $user): void
    {
    }

    public function resetToGuest(): void
    {
        CoroutineLocal::remove(self::LAST_RESULT_KEY);
    }

    public function setAuthResult(AuthResult $result): void
    {
        CoroutineLocal::set(self::LAST_RESULT_KEY, $result);
    }

    public function getLastResult(): ?AuthResult
    {
        $result = CoroutineLocal::get(self::LAST_RESULT_KEY);

        return $result instanceof AuthResult ? $result : null;
    }

    public static function get(): ?self
    {
        return self::getInstance();
    }

    public static function getOrFail(): self
    {
        return self::getInstance();
    }
}
