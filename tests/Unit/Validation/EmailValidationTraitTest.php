<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\EmailValidationTrait;

final class EmailValidationTraitTest extends TestCase
{
    #[Test]
    public function accepts_a_plain_email(): void
    {
        $errors = [];
        self::host()->email('email', 'a@b.co', $errors);

        self::assertSame([], $errors);
    }

    #[Test]
    public function rejects_an_email_with_a_trailing_newline(): void
    {
        $errors = [];
        self::host()->email('email', "a@b.co\n", $errors);

        self::assertSame(['email' => ['Invalid email format.']], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use EmailValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function email(string $f, string $v, array &$errors): void { $this->validateEmail($f, $v, $errors); }
        };
    }
}
