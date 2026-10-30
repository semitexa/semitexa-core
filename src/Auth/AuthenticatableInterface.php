<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/** @api */
interface AuthenticatableInterface
{
    public function getId(): string;

    public function getAuthIdentifierName(): string;

    public function getAuthIdentifier(): mixed;
}
