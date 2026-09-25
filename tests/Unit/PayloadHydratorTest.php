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
            public string $name = '';

            // Positive control: a public setter in the same body must run, or
            // the two assertions below pass without the guard being exercised.
            public function setName(string $value): void
            {
                $this->name = $value;
            }

            private function setIsAdmin(bool $value): void
            {
                $this->isAdmin = $value;
            }

            public static function setFlag(bool $value): void
            {
                self::$flag = $value;
            }
        };

        $hydrated = PayloadHydrator::hydrate($dto, $this->jsonRequest(['is_admin' => true, 'flag' => true, 'name' => 'ran'], strict: false));

        self::assertSame('ran', $hydrated->name);
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

    #[Test]
    public function strict_request_rejects_a_number_an_int_cannot_hold_exactly(): void
    {
        // is_numeric() let these through and (int) then truncated or clamped
        // them: "1.9" became 1, a 20-digit id PHP_INT_MAX, 1e30 garbage.
        foreach (['1.9', '99999999999999999999', 1.5, 1e30, '1e30'] as $value) {
            try {
                PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => $value], strict: true));
                self::fail('accepted ' . var_export($value, true));
            } catch (TypeMismatchException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function strict_request_accepts_every_exact_int_spelling(): void
    {
        foreach (['42' => 42, '-7' => -7, '007' => 7, ' 5' => 5, '1e3' => 1000] as $value => $expected) {
            $hydrated = PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => (string) $value], strict: true));
            self::assertSame($expected, $hydrated->n, (string) $value);
        }

        $hydrated = PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => 3.0], strict: true));
        self::assertSame(3, $hydrated->n);

        $hydrated = PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => PHP_INT_MAX], strict: true));
        self::assertSame(PHP_INT_MAX, $hydrated->n);
    }

    #[Test]
    public function strict_request_picks_the_float_arm_for_a_fraction(): void
    {
        $dto = new class {
            public int|float|null $v = null;

            public function setV(int|float $value): void
            {
                $this->v = $value;
            }
        };

        $hydrated = PayloadHydrator::hydrate($dto, $this->jsonRequest(['v' => '1.9'], strict: true));

        self::assertSame(1.9, $hydrated->v);
    }

    #[Test]
    public function an_integer_key_still_reaches_a_setter_named_after_it(): void
    {
        // PHP allows set0(), and PayloadMetadataReflector publishes it as field
        // `0`; skipping int keys made that published field impossible to fill.
        $dto = new class {
            public ?string $zero = null;

            public function set0(string $value): void
            {
                $this->zero = $value;
            }
        };

        self::assertSame('first', PayloadHydrator::hydrate($dto, $this->jsonRequest(['first', 'second'], strict: false))->zero);
    }

    #[Test]
    public function strict_int_does_not_accept_a_spelling_a_float_would_change(): void
    {
        // Each of these used to pass: `+ 0` turned them into 1, 0 and
        // 9007199254740992 before the check looked, and the cast stored that.
        foreach (['1.0000000000000001', '1e-400', '9007199254740993e0', '9223372036854775808'] as $value) {
            try {
                PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => $value], strict: true));
                self::fail("strict int accepted {$value}");
            } catch (TypeMismatchException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['1.0' => 1, '9007199254740992e0' => 9007199254740992, '9223372036854775807' => PHP_INT_MAX, '-9223372036854775808' => PHP_INT_MIN] as $value => $expected) {
            self::assertSame($expected, PayloadHydrator::hydrate($this->intDto(), $this->jsonRequest(['n' => (string) $value], strict: true))->n);
        }
    }
}
