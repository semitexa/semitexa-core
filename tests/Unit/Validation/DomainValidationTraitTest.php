<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\DomainValidationTrait;

final class DomainValidationTraitTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function countryCodes(): iterable
    {
        yield 'valid uppercase'        => ['US', true];
        yield 'invalid lowercase'      => ['us', false];
        yield 'invalid three letters'  => ['USA', false];
        yield 'invalid digits'         => ['U1', false];
        yield 'empty'                  => ['', false];
    }

    /** @dataProvider countryCodes */
    public function test_country_code(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->countryCode($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a 2-letter country code.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function currencyCodes(): iterable
    {
        yield 'valid uppercase'        => ['USD', true];
        yield 'invalid lowercase'      => ['usd', false];
        yield 'invalid two letters'    => ['US', false];
        yield 'invalid digits'         => ['US1', false];
    }

    /** @dataProvider currencyCodes */
    public function test_currency_code(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->currencyCode($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a 3-letter currency code.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function locales(): iterable
    {
        yield 'two letter'         => ['en', true];
        yield 'three letter'       => ['mul', true];
        yield 'language-region'    => ['en-US', true];
        yield 'language-region4'   => ['zh-Hant', true];
        yield 'too short'          => ['e', false];
        yield 'underscore'         => ['en_US', false];
        yield 'mixed case lang'    => ['EN', false];
    }

    /** @dataProvider locales */
    public function test_locale_code(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->localeCode($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid locale code.']],
            $errors,
        );
    }

    public function test_timezone_uses_listIdentifiers(): void
    {
        $errors = [];
        $h = self::host();
        $h->timezone($errors, 'a', 'Europe/Kyiv');
        $h->timezone($errors, 'b', 'America/New_York');
        $h->timezone($errors, 'c', 'UTC');
        self::assertSame([], $errors);

        $h->timezone($errors, 'd', 'Not/A_Zone');
        self::assertNotEmpty($errors['d']);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function e164Phones(): iterable
    {
        yield 'short valid'    => ['+12', true];
        yield 'medium valid'   => ['+14155552671', true];
        yield 'max length'     => ['+123456789012345', true];
        yield 'no plus'        => ['14155552671', false];
        yield 'leading zero'   => ['+04155552671', false];
        yield 'too long'       => ['+1234567890123456', false];
        yield 'with spaces'    => ['+1 415 555 2671', false];
        yield 'with hyphens'   => ['+1-415-555-2671', false];
    }

    /** @dataProvider e164Phones */
    public function test_e164_phone(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->phone($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid E.164 phone number.']],
            $errors,
        );
    }

    public function test_hex_color_with_and_without_alpha(): void
    {
        $errors = [];
        $h = self::host();
        $h->hexColor($errors, 'a', '#fff');
        $h->hexColor($errors, 'b', '#FFFFFF');
        $h->hexColor($errors, 'c', '#FFFFFFFF', allowAlpha: true);
        self::assertSame([], $errors);

        $errors = [];
        $h->hexColor($errors, 'd', '#FFFFFFFF', allowAlpha: false);
        self::assertNotEmpty($errors['d']);

        $errors = [];
        $h->hexColor($errors, 'e', 'fff');
        self::assertNotEmpty($errors['e']);

        $h->hexColor($errors, 'f', '#GG');
        self::assertNotEmpty($errors['f']);
    }

    public function test_base64_strict_round_trip(): void
    {
        $errors = [];
        $h = self::host();
        $h->base64($errors, 'a', base64_encode('hello'));
        self::assertSame([], $errors);

        $h->base64($errors, 'b', 'not base64!');
        self::assertNotEmpty($errors['b']);

        // Strict round-trip catches non-canonical inputs that decode but
        // do not re-encode to themselves.
        $h->base64($errors, 'c', 'aGVsbG8');
        self::assertNotEmpty($errors['c']);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function mimeTypes(): iterable
    {
        yield 'plain'              => ['application/json', true];
        yield 'with hyphen'        => ['application/x-www-form-urlencoded', true];
        yield 'no slash'           => ['application', false];
        yield 'spaces'             => ['application/ json', false];
        yield 'empty'              => ['', false];
    }

    /** @dataProvider mimeTypes */
    public function test_mime_type(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->mime($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid MIME type.']],
            $errors,
        );
    }

    public function test_null_is_silently_accepted_by_every_method(): void
    {
        $errors = [];
        $h = self::host();
        $h->countryCode($errors, 'a', null);
        $h->currencyCode($errors, 'b', null);
        $h->localeCode($errors, 'c', null);
        $h->timezone($errors, 'd', null);
        $h->phone($errors, 'e', null);
        $h->hexColor($errors, 'f', null);
        $h->base64($errors, 'g', null);
        $h->mime($errors, 'h', null);
        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use DomainValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function countryCode(array &$errors, string $f, ?string $v): void
            { $this->validateCountryCode($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function currencyCode(array &$errors, string $f, ?string $v): void
            { $this->validateCurrencyCode($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function localeCode(array &$errors, string $f, ?string $v): void
            { $this->validateLocaleCode($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function timezone(array &$errors, string $f, ?string $v): void
            { $this->validateTimezone($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function phone(array &$errors, string $f, ?string $v): void
            { $this->validateE164Phone($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function hexColor(array &$errors, string $f, ?string $v, bool $allowAlpha = true): void
            { $this->validateHexColor($errors, $f, $v, $allowAlpha); }
            /** @param array<string, list<string>> $errors */
            public function base64(array &$errors, string $f, ?string $v): void
            { $this->validateBase64($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function mime(array &$errors, string $f, ?string $v): void
            { $this->validateMimeType($errors, $f, $v); }
        };
    }
}
