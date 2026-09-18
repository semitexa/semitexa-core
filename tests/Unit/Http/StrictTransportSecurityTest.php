<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\StrictTransportSecurity;
use Semitexa\Core\Http\StrictTransportSecurityDoctorCheck;
use Semitexa\Core\Support\DoctorStatus;

/**
 * HSTS is the one header here that a server cannot take back, so the tests that
 * matter most are the ones pinning that it stays OFF unless asked, and that
 * nothing is ever added to the policy on the operator's behalf.
 */
final class StrictTransportSecurityTest extends TestCase
{
    private const VARS = ['HSTS_MAX_AGE', 'HSTS_INCLUDE_SUBDOMAINS', 'HSTS_PRELOAD', 'APP_URL'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $this->saved[$name] = getenv($name);
            // An EMPTY process value, not an unset one. putenv($name) removes
            // the process entry and Environment::getEnvValue() then falls
            // through to its once-per-process cache of .env / .env.default — so
            // a developer with the variable set in .env ran a different test
            // from CI, silently. Empty is what "not configured" means to every
            // reader here.
            putenv($name . '=');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    #[Test]
    public function it_is_off_unless_asked(): void
    {
        self::assertNull(StrictTransportSecurity::headerValue());
    }

    #[Test]
    public function a_duration_turns_it_on(): void
    {
        putenv('HSTS_MAX_AGE=31536000');

        self::assertSame('max-age=31536000', StrictTransportSecurity::headerValue());
    }

    /**
     * Garbage turns it OFF rather than falling back to a default. Guessing a
     * duration here would pick a number nobody chose for a header browsers
     * honour for its full length whatever the server says afterwards.
     *
     * @param string $value what someone might put in .env by mistake
     */
    #[Test]
    #[DataProvider('valuesThatAreNotADuration')]
    public function a_value_that_is_not_a_duration_leaves_it_off(string $value): void
    {
        putenv('HSTS_MAX_AGE=' . $value);

        self::assertNull(StrictTransportSecurity::headerValue(), "should be off for: {$value}");
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatAreNotADuration(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a boolean somebody assumed' => ['true'];
        yield 'a duration with a unit' => ['1y'];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'a float' => ['31536000.0'];
    }

    #[Test]
    public function subdomains_and_preload_are_added_only_when_each_is_asked_for(): void
    {
        putenv('HSTS_MAX_AGE=31536000');
        putenv('HSTS_INCLUDE_SUBDOMAINS=true');

        self::assertSame('max-age=31536000; includeSubDomains', StrictTransportSecurity::headerValue());

        putenv('HSTS_PRELOAD=true');

        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            StrictTransportSecurity::headerValue(),
        );
    }

    #[Test]
    public function preload_does_not_silently_pull_in_includeSubDomains(): void
    {
        // The preload list rejects this combination, and the doctor says so.
        // Adding the missing directive here would widen the policy to every
        // subdomain without anyone asking, which is the opposite of an opt-in.
        putenv('HSTS_MAX_AGE=31536000');
        putenv('HSTS_PRELOAD=1');

        self::assertSame('max-age=31536000; preload', StrictTransportSecurity::headerValue());
    }

    #[Test]
    public function the_doctor_warns_when_an_https_deployment_sends_nothing(): void
    {
        putenv('APP_URL=https://semitexa.com');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        // Warn, never fail: HSTS is optional and irreversible, and a doctor that
        // failed over it would teach people to stop reading the doctor.
        self::assertSame(DoctorStatus::Warn, $result->status);
        self::assertStringContainsString('plaintext request', $result->message);
        self::assertStringContainsString('HSTS_MAX_AGE=300', (string) $result->hint);
    }

    #[Test]
    public function the_doctor_says_nothing_to_protect_on_a_plain_http_deployment(): void
    {
        putenv('APP_URL=http://localhost:9502');

        self::assertSame(DoctorStatus::Pass, (new StrictTransportSecurityDoctorCheck())->run()->status);
    }

    #[Test]
    public function the_doctor_fails_a_max_age_that_silently_disabled_the_header(): void
    {
        // The dangerous case: the operator believes HSTS is on.
        putenv('HSTS_MAX_AGE=1y');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('1y', $result->message);
    }

    #[Test]
    public function the_doctor_fails_a_preload_policy_the_list_would_reject(): void
    {
        putenv('HSTS_MAX_AGE=300');
        putenv('HSTS_PRELOAD=true');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('includeSubDomains is missing', $result->message);
        self::assertStringContainsString('below the required 31536000', $result->message);
    }

    #[Test]
    public function the_doctor_warns_even_on_a_correct_preload_policy(): void
    {
        putenv('HSTS_MAX_AGE=31536000');
        putenv('HSTS_INCLUDE_SUBDOMAINS=true');
        putenv('HSTS_PRELOAD=true');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        self::assertSame(DoctorStatus::Warn, $result->status, 'preloading is effectively permanent');
        self::assertStringContainsString('permanent', (string) $result->hint);
    }

    #[Test]
    public function the_doctor_passes_a_plain_on_policy(): void
    {
        putenv('HSTS_MAX_AGE=31536000');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('max-age=31536000', $result->message);
    }

    /**
     * A digit string wider than the platform's integer.
     *
     * `ctype_digit` accepts it and the `(int)` cast SATURATES, so an operator
     * who typed twenty digits got max-age=9223372036854775807 — about 292
     * billion years, cached and honoured by every browser that saw it — while
     * the doctor check called the value valid. The one outcome this header must
     * not produce is a duration nobody chose.
     */
    #[Test]
    public function a_max_age_wider_than_the_platform_integer_turns_the_header_off(): void
    {
        putenv('HSTS_MAX_AGE=99999999999999999999999');

        self::assertNull(StrictTransportSecurity::configuredMaxAge());
        self::assertNull(StrictTransportSecurity::headerValue());
    }

    #[Test]
    public function the_largest_holdable_max_age_is_still_accepted(): void
    {
        // The boundary itself is a real value and stays one — refusing it would
        // be a second guess about what the operator meant.
        putenv('HSTS_MAX_AGE=' . PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, StrictTransportSecurity::configuredMaxAge());

        putenv('HSTS_MAX_AGE=' . PHP_INT_MAX . '0');

        self::assertNull(StrictTransportSecurity::configuredMaxAge(), 'one digit past it is not a duration');
    }

    #[Test]
    public function leading_zeros_are_formatting_not_a_different_number(): void
    {
        putenv('HSTS_MAX_AGE=007');
        self::assertSame(7, StrictTransportSecurity::configuredMaxAge());

        putenv('HSTS_MAX_AGE=000');
        self::assertNull(StrictTransportSecurity::configuredMaxAge(), 'zero is off, however it is spelled');
    }

    /**
     * And the doctor says so, rather than reporting HSTS as merely "off".
     * It used to re-derive "is this a positive number" with its own
     * ctype_digit, which accepted the wide value and skipped the failing
     * branch — a second opinion about the same string, reaching the opposite
     * verdict from the code that builds the header.
     */
    #[Test]
    public function the_doctor_fails_on_a_max_age_the_platform_cannot_hold(): void
    {
        putenv('HSTS_MAX_AGE=99999999999999999999999');
        putenv('APP_URL=https://semitexa.com');

        $result = (new StrictTransportSecurityDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('99999999999999999999999', $result->message);
        self::assertStringContainsString('no Strict-Transport-Security header is sent', $result->message);
    }
}