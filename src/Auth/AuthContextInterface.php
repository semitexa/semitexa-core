<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

interface AuthContextInterface
{
    public function getUser(): ?AuthenticatableInterface;

    public function isGuest(): bool;

    public function setUser(?AuthenticatableInterface $user): void;

    public static function get(): ?self;

    public static function getOrFail(): self;
}
