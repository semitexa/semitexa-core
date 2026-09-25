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

    #[Test]
    public function percent_encoded_path_param_is_decoded(): void
    {
        $hydrated = PayloadHydrator::hydrate(
            $this->sefDto(),
            $this->pathRequest('/pages/' . rawurlencode('Виставки №1')),
        );

        self::assertSame('Виставки №1', $hydrated->sef);
    }

    #[Test]
    public function plain_ascii_path_param_is_unchanged(): void
    {
        $hydrated = PayloadHydrator::hydrate($this->sefDto(), $this->pathRequest('/pages/about-us'));

        self::assertSame('about-us', $hydrated->sef);
    }

    #[Test]
    public function encoded_slash_still_matches_the_segment_and_decodes_in_the_value(): void
    {
        // %2F is not a literal slash, so the {sef} segment ([^/]+) still matches;
        // the captured value is then decoded to a real slash.
        $hydrated = PayloadHydrator::hydrate($this->sefDto(), $this->pathRequest('/pages/a%2Fb'));

        self::assertSame('a/b', $hydrated->sef);
    }

    private function sefDto(): object
    {
        return new #[\Semitexa\Core\Attribute\AsPublicPayload(path: '/pages/{sef}')] class {
            public ?string $sef = null;

            public function setSef(string $value): void
            {
                $this->sef = $value;
            }
        };
    }

    private function pathRequest(string $uri): Request
    {
        return new Request(
            method: 'GET',
            uri: $uri,
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: [],
        );
    }

    #[Test]
    public function a_non_public_or_static_setter_is_not_reachable_from_the_body(): void
    {
        // The published contract (PayloadMetadataReflector) lists public setters
        // only; a body key must not reach a private helper the DTO uses itself.
        $dto = new class {
            public bool $isAdmin = false;
            public static bool $flag = false;

            private function setIsAdmin(bool $value): void
            {
                $this->isAdmin = $value;
            }

            public static function setFlag(bool $value): void
            {
                self::$flag = $value;
            }
        };

        $hydrated = PayloadHydrator::hydrate($dto, $this->jsonRequest(['is_admin' => true, 'flag' => true], strict: false));

        self::assertFalse($hydrated->isAdmin);
        self::assertFalse($hydrated::$flag);
    }

    #[Test]
    public function integer_keys_in_the_body_are_ignored_rather_than_failing_the_request(): void
    {
        // A JSON list, `{"0":1}` or a form field named `0` all decode to int
        // keys; under strict_types they used to TypeError in keyToSetterName()
        // and the whole request was rejected.
        $hydrated = PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest([1, 2, 3], strict: false));
        self::assertSame(-1, $hydrated->n);

        $form = new Request(
            method: 'POST',
            uri: '/demo',
            headers: [],
            query: [],
            post: [0 => 'x', 'n' => '7'],
            server: [],
            cookies: [],
        );
        self::assertSame(7, PayloadHydrator::hydrate($this->intDto(), $form)->n);
    }

    #[Test]
    public function a_nullable_union_setter_receives_null_for_null(): void
    {
        // ?int already mapped null (and '') to null; the union branch ignored
        // allowsNull() and cast null to the first arm, handing over 0 or "".
        $dto = new class {
            public int|float|null $number = -1;
            public int|string|null $label = 'unset';

            public function setNumber(int|float|null $value): void
            {
                $this->number = $value;
            }

            public function setLabel(int|string|null $value): void
            {
                $this->label = $value;
            }
        };

        foreach ([false, true] as $strict) {
            $hydrated = PayloadHydrator::hydrate(clone $dto, $this->jsonRequest(['number' => null, 'label' => null], strict: $strict));
            self::assertNull($hydrated->number);
            self::assertNull($hydrated->label);

            $hydrated = PayloadHydrator::hydrate(clone $dto, $this->jsonRequest(['number' => '', 'label' => ''], strict: $strict));
            self::assertNull($hydrated->number, 'an empty value means null, as it does for ?int');
            self::assertNull($hydrated->label);
        }
    }
}
