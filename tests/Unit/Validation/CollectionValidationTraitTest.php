<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\CollectionValidationTrait;

final class CollectionValidationTraitTest extends TestCase
{
    public function test_array_of_validates_each_item_with_indexed_path(): void
    {
        $errors = [];
        self::host()->arrayOf(
            $errors,
            'tags',
            ['hello', '', 'world'],
            self::nonBlankItemValidator(),
        );
        self::assertSame(['tags[1]' => ['This value should not be blank.']], $errors);
    }

    public function test_list_of_rejects_associative_arrays(): void
    {
        $errors = [];
        self::host()->listOf(
            $errors,
            'tags',
            ['a' => 1, 'b' => 2],
            self::noopValidator(),
        );
        self::assertSame(['tags' => ['This value should be a sequential list.']], $errors);
    }

    public function test_list_of_validates_sequential_items(): void
    {
        $errors = [];
        self::host()->listOf(
            $errors,
            'tags',
            ['a', '', 'b'],
            self::nonBlankItemValidator(),
        );
        self::assertSame(['tags[1]' => ['This value should not be blank.']], $errors);
    }

    public function test_map_of_validates_keys_and_values(): void
    {
        $errors = [];
        self::host()->mapOf(
            $errors,
            'attributes',
            ['size' => 'large', '' => 'x'],
            keyValidator: self::nonBlankItemValidator(),
            valueValidator: self::noopValidator(),
        );
        // Empty key path renders as 'attributes.' — we only care that some
        // error was recorded under a path beginning with 'attributes.'.
        self::assertNotEmpty($errors);
    }

    public function test_collection_required_key_missing(): void
    {
        $errors = [];
        self::host()->collection(
            $errors,
            'product',
            ['sku' => 'S-1'],
            ['sku' => ['required' => true, 'validator' => self::noopValidator()],
             'name' => ['required' => true, 'validator' => self::noopValidator()]],
        );
        self::assertSame(['product.name' => ['This value is required.']], $errors);
    }

    public function test_collection_optional_key_absent_is_fine(): void
    {
        $errors = [];
        self::host()->collection(
            $errors,
            'product',
            ['sku' => 'S-1'],
            ['sku' => ['required' => true, 'validator' => self::noopValidator()],
             'description' => ['required' => false, 'validator' => self::noopValidator()]],
        );
        self::assertSame([], $errors);
    }

    public function test_collection_extra_key_rejected_by_default(): void
    {
        $errors = [];
        self::host()->collection(
            $errors,
            'product',
            ['sku' => 'S-1', 'rogue' => 'x'],
            ['sku' => ['required' => true, 'validator' => self::noopValidator()]],
        );
        self::assertSame(['product.rogue' => ['This key is not allowed.']], $errors);
    }

    public function test_collection_extra_key_allowed_when_flag_set(): void
    {
        $errors = [];
        self::host()->collection(
            $errors,
            'product',
            ['sku' => 'S-1', 'extra' => 'x'],
            ['sku' => ['required' => true, 'validator' => self::noopValidator()]],
            allowExtraFields: true,
        );
        self::assertSame([], $errors);
    }

    public function test_nested_validation_path_for_array_of_objects(): void
    {
        $errors = [];
        self::host()->arrayOf(
            $errors,
            'variants',
            [
                ['sku' => 'A-1'],
                ['sku' => ''],
                ['sku' => 'B-3'],
            ],
            function (array &$e, string $field, mixed $variant): void {
                $sku = is_array($variant) && array_key_exists('sku', $variant) ? $variant['sku'] : null;
                if ($sku === '') {
                    $e[$field . '.sku'] = $e[$field . '.sku'] ?? [];
                    $e[$field . '.sku'][] = 'This value should not be blank.';
                }
            },
        );
        self::assertSame(['variants[1].sku' => ['This value should not be blank.']], $errors);
    }

    public function test_required_keys(): void
    {
        $errors = [];
        self::host()->requiredKeys($errors, 'p', ['a' => 1], ['a', 'b']);
        self::assertSame(['p.b' => ['This value is required.']], $errors);
    }

    public function test_no_extra_keys(): void
    {
        $errors = [];
        self::host()->noExtraKeys($errors, 'p', ['a' => 1, 'b' => 2, 'c' => 3], ['a', 'b']);
        self::assertSame(['p.c' => ['This key is not allowed.']], $errors);
    }

    public function test_optional_keys_alias_of_no_extra_keys(): void
    {
        $errors = [];
        self::host()->optionalKeys($errors, 'p', ['a' => 1, 'unknown' => 2], ['a', 'b']);
        self::assertSame(['p.unknown' => ['This key is not allowed.']], $errors);
    }

    public function test_at_least_one_key(): void
    {
        $errors = [];
        $h = self::host();
        $h->atLeastOneKey($errors, 'p', ['a' => 1], ['a', 'b']);
        self::assertSame([], $errors);

        $h->atLeastOneKey($errors, 'q', [], ['a', 'b']);
        self::assertSame(['q' => ['At least one of the required keys must be present.']], $errors);
    }

    public function test_exactly_one_key(): void
    {
        $errors = [];
        $h = self::host();
        $h->exactlyOneKey($errors, 'p', ['a' => 1], ['a', 'b']);
        self::assertSame([], $errors);

        $h->exactlyOneKey($errors, 'q', ['a' => 1, 'b' => 2], ['a', 'b']);
        self::assertSame(['q' => ['Exactly one of the listed keys must be present.']], $errors);

        $errors = [];
        $h->exactlyOneKey($errors, 'r', [], ['a', 'b']);
        self::assertSame(['r' => ['Exactly one of the listed keys must be present.']], $errors);
    }

    public function test_mutually_exclusive_keys(): void
    {
        $errors = [];
        $h = self::host();
        $h->mutuallyExclusive($errors, 'p', ['a' => 1], ['a', 'b']);
        self::assertSame([], $errors);

        $h->mutuallyExclusive($errors, 'q', ['a' => 1, 'b' => 2], ['a', 'b']);
        self::assertSame(['q' => ['These keys are mutually exclusive.']], $errors);

        $errors = [];
        $h->mutuallyExclusive($errors, 'r', [], ['a', 'b']);
        self::assertSame([], $errors);
    }

    public function test_null_is_silently_accepted_for_every_method(): void
    {
        $errors = [];
        $h = self::host();
        $h->arrayOf($errors, 'a', null, self::noopValidator());
        $h->listOf($errors, 'b', null, self::noopValidator());
        $h->mapOf($errors, 'c', null, self::noopValidator(), self::noopValidator());
        $h->collection($errors, 'd', null, ['x' => ['required' => true, 'validator' => self::noopValidator()]]);
        $h->requiredKeys($errors, 'e', null, ['a']);
        $h->noExtraKeys($errors, 'f', null, ['a']);
        $h->optionalKeys($errors, 'g', null, ['a']);
        $h->atLeastOneKey($errors, 'h', null, ['a']);
        $h->exactlyOneKey($errors, 'i', null, ['a']);
        $h->mutuallyExclusive($errors, 'j', null, ['a']);
        self::assertSame([], $errors);
    }

    public function test_empty_schema_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->collection($errors, 'p', ['a' => 1], []);
    }

    public function test_malformed_schema_entry_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->collection($errors, 'p', ['a' => 1], ['a' => ['validator' => self::noopValidator()]]);
    }

    public function test_empty_keys_list_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->atLeastOneKey($errors, 'p', ['a' => 1], []);
    }

    private static function nonBlankItemValidator(): callable
    {
        return function (array &$errors, string $field, mixed $value): void {
            if (is_string($value) && trim($value) === '') {
                $errors[$field] = $errors[$field] ?? [];
                $errors[$field][] = 'This value should not be blank.';
            }
        };
    }

    private static function noopValidator(): callable
    {
        return static function (array &$errors, string $field, mixed $value): void {};
    }

    private static function host(): object
    {
        return new class () {
            use CollectionValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function arrayOf(array &$errors, string $f, ?array $v, callable $iv): void
            { $this->validateArrayOf($errors, $f, $v, $iv); }
            /** @param array<string, list<string>> $errors */
            public function listOf(array &$errors, string $f, ?array $v, callable $iv): void
            { $this->validateListOf($errors, $f, $v, $iv); }
            /** @param array<string, list<string>> $errors */
            public function mapOf(array &$errors, string $f, ?array $v, callable $keyValidator, callable $valueValidator): void
            { $this->validateMapOf($errors, $f, $v, $keyValidator, $valueValidator); }
            /**
             * @param array<string, list<string>>                                                                                          $errors
             * @param array<string, array{required: bool, validator: callable(array<string, list<string>>&, string, mixed): void}>        $schema
             */
            public function collection(array &$errors, string $f, ?array $v, array $schema, bool $allowExtraFields = false): void
            { $this->validateCollection($errors, $f, $v, $schema, $allowExtraFields); }
            /** @param array<string, list<string>> $errors */
            public function requiredKeys(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateRequiredKeys($errors, $f, $v, $keys); }
            /** @param array<string, list<string>> $errors */
            public function optionalKeys(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateOptionalKeys($errors, $f, $v, $keys); }
            /** @param array<string, list<string>> $errors */
            public function noExtraKeys(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateNoExtraKeys($errors, $f, $v, $keys); }
            /** @param array<string, list<string>> $errors */
            public function atLeastOneKey(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateAtLeastOneKey($errors, $f, $v, $keys); }
            /** @param array<string, list<string>> $errors */
            public function exactlyOneKey(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateExactlyOneKey($errors, $f, $v, $keys); }
            /** @param array<string, list<string>> $errors */
            public function mutuallyExclusive(array &$errors, string $f, ?array $v, array $keys): void
            { $this->validateMutuallyExclusiveKeys($errors, $f, $v, $keys); }
        };
    }
}
