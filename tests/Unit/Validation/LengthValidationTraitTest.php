<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\LengthValidationTrait;

final class LengthValidationTraitTest extends TestCase
{
    #[Test]
    public function string_length_is_counted_in_characters_not_bytes(): void
    {
        $errors = [];
        self::host()->length('latin', 'héllo', 5, 5, $errors);
        self::host()->length('cyrillic', 'привіт', null, 6, $errors);

        self::assertSame([], $errors);
    }

    #[Test]
    public function string_longer_than_max_characters_is_rejected(): void
    {
        $errors = [];
        self::host()->length('f', 'привіт!', null, 6, $errors);

        self::assertSame(['f' => ['Length must be at most 6.']], $errors);
    }

    #[Test]
    public function array_length_is_its_element_count(): void
    {
        $errors = [];
        self::host()->length('f', ['a', 'b', 'c'], 1, 2, $errors);

        self::assertSame(['f' => ['Length must be at most 2.']], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use LengthValidationTrait;
            /**
             * @param string|array<mixed> $v
             * @param array<string, list<string>> $errors
             */
            public function length(string $f, string|array $v, ?int $min, ?int $max, array &$errors): void
            {
                $this->validateLength($f, $v, $min, $max, $errors);
            }
        };
    }
}
