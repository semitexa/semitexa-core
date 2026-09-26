<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Core\Auth\AuthenticatableInterface;

/** A user principal the stub auth handler authenticates as. */
final readonly class AuthDemoUser implements AuthenticatableInterface
{
    public function __construct(public string $id) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }
}
