<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

interface AuthenticatableInterface
{
    public function getId(): string;

    public function getAuthIdentifierName(): string;

    public function getAuthIdentifier(): mixed;
}
