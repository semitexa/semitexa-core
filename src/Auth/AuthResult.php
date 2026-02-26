<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

readonly class AuthResult
{
    public function __construct(
        public bool $success,
        public ?AuthenticatableInterface $user = null,
        public ?string $reason = null,
    ) {}

    public static function success(AuthenticatableInterface $user): self
    {
        return new self(success: true, user: $user);
    }

    public static function failed(?string $reason = null): self
    {
        return new self(success: false, reason: $reason);
    }
}
