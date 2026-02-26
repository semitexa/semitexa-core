<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

final class GuestAuthContext implements AuthContextInterface
{
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

    public static function get(): ?self
    {
        return self::getInstance();
    }

    public static function getOrFail(): self
    {
        return self::getInstance();
    }
}
