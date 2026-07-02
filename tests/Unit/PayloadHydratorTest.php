<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Exception\TypeMismatchException;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Request;

final class PayloadHydratorTest extends TestCase
{
    #[Test]
    public function non_strict_request_silently_casts_incompatible_value(): void
    {
        $dto = $this->intDto();

        $hydrated = PayloadHydrator::hydrate($dto, $this->jsonRequest(['n' => 'hello'], strict: false));

        self::assertSame(0, $hydrated->n, 'Default (non-strict) hydration coerces "hello" to 0.');
    }

    #[Test]
    public function strict_request_rejects_incompatible_value(): void
    {
        $this->expectException(TypeMismatchException::class);

        PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => 'hello'], strict: true));
    }

    #[Test]
    public function strict_request_still_casts_a_compatible_value(): void
    {
        $hydrated = PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => '42'], strict: true));

        self::assertSame(42, $hydrated->n);
    }

    #[Test]
    public function strict_request_selects_the_compatible_arm_of_a_union(): void
    {
        $dto = new class {
            public int|string|null $v = null;

            public function setV(int|string $value): void
            {
                $this->v = $value;
            }
        };

        // "hello" is invalid for the int arm but valid for the string arm; strict
        // hydration must select string instead of rejecting the value outright.
        $hydrated = PayloadHydrator::hydrate($dto, $this->jsonRequest(['v' => 'hello'], strict: true));

        self::assertSame('hello', $hydrated->v);
    }

    private function intDto(): object
    {
        return new class {
            public int $n = -1;

            public function setN(int $value): void
            {
                $this->n = $value;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body, bool $strict): Request
    {
        return new Request(
            method: 'POST',
            uri: '/demo',
            headers: ['Content-Type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: (string) json_encode($body),
            strictHydration: $strict,
        );
    }

    #[Test]
    public function hydrate_ignores_non_string_query_keys(): void
    {
        $dto = new class {
            public ?string $name = null;

            public function setName(string $name): void
            {
                $this->name = $name;
            }
        };

        $request = new Request(
            'GET',
            '/demo',
            [],
            [0 => 'bad', 'name' => 'alice'],
            [],
            [],
            [],
        );

        $hydrated = PayloadHydrator::hydrate($dto, $request);

        self::assertSame('alice', $hydrated->name);
    }
}
