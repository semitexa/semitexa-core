<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\FormatValidationTrait;

final class FormatValidationTraitTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function emailLenient(): iterable
    {
        yield 'simple'       => ['user@example.com', true];
        yield 'plus tag'     => ['user+tag@example.com', true];
        yield 'subdomain'    => ['a@b.c.d', true];
        yield 'no @'         => ['userexample.com', false];
        yield 'no domain'    => ['user@', false];
        yield 'whitespace'   => ['user @example.com', false];
        yield 'no tld dot'   => ['user@localhost', false];
    }

    /** @dataProvider emailLenient */
    public function test_email_practical_policy(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->email($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid email address.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function rfcEmail(): iterable
    {
        yield 'simple'         => ['user@example.com', true];
        yield 'no @'           => ['no-at', false];
        yield 'spaces'         => ['a b@x.com', false];
        yield 'host only'      => ['user@localhost', false];
    }

    /** @dataProvider rfcEmail */
    public function test_rfc_email(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->rfcEmail($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid email address.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function urls(): iterable
    {
        yield 'https'       => ['https://example.com', true];
        yield 'http path'   => ['http://example.com/foo', true];
        yield 'with query'  => ['https://example.com/foo?bar=1', true];
        yield 'no scheme'   => ['example.com', false];
        yield 'just text'   => ['hello world', false];
        yield 'spaces'      => ['http://example .com', false];
    }

    /** @dataProvider urls */
    public function test_url(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->url($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid URL.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function uuids(): iterable
    {
        yield 'v4 lowercase'   => ['550e8400-e29b-41d4-a716-446655440000', true];
        yield 'v4 uppercase'   => ['550E8400-E29B-41D4-A716-446655440000', true];
        yield 'v7'             => ['018f8e0e-1b1f-7c5e-89ad-65f8d8c12345', true];
        yield 'nil rejected'   => ['00000000-0000-0000-0000-000000000000', false];
        yield 'too short'      => ['550e8400-e29b-41d4-a716', false];
        yield 'no dashes'      => ['550e8400e29b41d4a716446655440000', false];
        yield 'wrong variant'  => ['550e8400-e29b-41d4-7716-446655440000', false];
    }

    /** @dataProvider uuids */
    public function test_uuid(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->uuid($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid UUID.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function ulids(): iterable
    {
        yield 'canonical'        => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', true];
        yield 'lowercase'        => ['01arz3ndektsv4rrffq69g5fav', true];
        yield 'too short'        => ['01ARZ3NDEK',                false];
        yield 'with I (excluded)'=> ['01ARZ3NDEKTSV4RRFFQ69G5FAI', false];
        yield 'leading 8'        => ['81ARZ3NDEKTSV4RRFFQ69G5FAV', false];
    }

    /** @dataProvider ulids */
    public function test_ulid(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->ulid($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid ULID.']],
            $errors,
        );
    }

    public function test_ip_default_accepts_v4_and_v6(): void
    {
        $h = self::host();

        $errors = [];
        $h->ip($errors, 'a', '127.0.0.1', null);
        $h->ip($errors, 'b', '::1', null);
        self::assertSame([], $errors);

        $errors = [];
        $h->ip($errors, 'c', 'not-an-ip', null);
        self::assertSame(['c' => ['This value should be a valid IP address.']], $errors);
    }

    public function test_ip_with_v4_only_flag_rejects_v6(): void
    {
        $errors = [];
        self::host()->ip($errors, 'addr', '::1', FILTER_FLAG_IPV4);
        self::assertNotEmpty($errors);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function hostnames(): iterable
    {
        yield 'simple'         => ['example.com', true];
        yield 'sub'            => ['a.b.example.com', true];
        yield 'with hyphen'    => ['my-host.example.com', true];
        yield 'leading hyphen' => ['-bad.example.com', false];
        yield 'empty'          => ['', false];
        yield 'space'          => ['bad host.com', false];
        yield 'trailing dot'   => ['example.com.', false];
    }

    /** @dataProvider hostnames */
    public function test_hostname(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->hostname($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid hostname.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function jsonStrings(): iterable
    {
        yield 'object'  => ['{"a":1}', true];
        yield 'array'   => ['[1,2,3]', true];
        yield 'string'  => ['"hi"',    true];
        yield 'number'  => ['42',      true];
        yield 'true'    => ['true',    true];
        yield 'invalid' => ['{a:1}',   false];
        yield 'empty'   => ['',        false];
        yield 'garbage' => ['not-json',false];
    }

    /** @dataProvider jsonStrings */
    public function test_json_string(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->jsonString($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid JSON string.']],
            $errors,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function slugs(): iterable
    {
        yield 'simple'           => ['hello-world', true];
        yield 'numeric segment'  => ['post-2026',   true];
        yield 'just digits'      => ['12345',       true];
        yield 'leading hyphen'   => ['-bad',        false];
        yield 'trailing hyphen'  => ['bad-',        false];
        yield 'double hyphen'    => ['a--b',        false];
        yield 'uppercase'        => ['Hello',       false];
        yield 'underscore'       => ['hello_world', false];
        yield 'empty'            => ['',            false];
    }

    /** @dataProvider slugs */
    public function test_slug(string $value, bool $valid): void
    {
        $errors = [];
        self::host()->slug($errors, 'f', $value);
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a valid slug.']],
            $errors,
        );
    }

    public function test_null_is_silently_accepted_by_every_method(): void
    {
        $errors = [];
        $h = self::host();

        $h->email($errors, 'a', null);
        $h->rfcEmail($errors, 'b', null);
        $h->url($errors, 'c', null);
        $h->uuid($errors, 'd', null);
        $h->ulid($errors, 'e', null);
        $h->ip($errors, 'f', null, null);
        $h->hostname($errors, 'g', null);
        $h->jsonString($errors, 'h', null);
        $h->slug($errors, 'i', null);

        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use FormatValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function email(array &$errors, string $f, ?string $v): void    { $this->validateEmail($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function rfcEmail(array &$errors, string $f, ?string $v): void { $this->validateRfcEmail($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function url(array &$errors, string $f, ?string $v): void      { $this->validateUrl($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function uuid(array &$errors, string $f, ?string $v): void     { $this->validateUuid($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function ulid(array &$errors, string $f, ?string $v): void     { $this->validateUlid($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function ip(array &$errors, string $f, ?string $v, ?int $flags): void
            { $this->validateIp($errors, $f, $v, $flags); }
            /** @param array<string, list<string>> $errors */
            public function hostname(array &$errors, string $f, ?string $v): void { $this->validateHostname($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function jsonString(array &$errors, string $f, ?string $v): void { $this->validateJsonString($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function slug(array &$errors, string $f, ?string $v): void     { $this->validateSlug($errors, $f, $v); }
        };
    }
}
