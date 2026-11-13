<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * Who the current request acts as: the authenticated user, or a guest.
 *
 * @api
 */
interface AuthContextInterface
{
    public function getUser(): ?AuthenticatableInterface;

    public function isGuest(): bool;

    public function setUser(?AuthenticatableInterface $user): void;

    public static function get(): ?self;

    public static function getOrFail(): self;
}
