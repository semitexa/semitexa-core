<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Security\SignatureCheck;
use Semitexa\Core\Security\UrlSigner;

final class UrlSignerTest extends TestCase
{
    #[Test]
    public function a_signed_link_verifies_and_any_change_to_it_does_not(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        $link = $signer->sign('/unsubscribe?list=news&user=42');

        self::assertSame(SignatureCheck::Valid, $this->check($signer, $link));
        self::assertSame(SignatureCheck::Invalid, $this->check($signer, str_replace('user=42', 'user=43', $link)));
        self::assertSame(SignatureCheck::Invalid, $this->check($signer, $link . '&admin=1'), 'an added parameter');
        self::assertSame(SignatureCheck::Invalid, $this->check($signer, str_replace('/unsubscribe', '/delete', $link)));
        self::assertSame(SignatureCheck::Invalid, $signer->check('/unsubscribe', 'list=news&user=42'), 'unsigned');
        self::assertSame(SignatureCheck::Invalid, $this->check(UrlSigner::withSecrets('secret-b'), $link), 'another secret');
    }

    #[Test]
    public function a_link_with_a_ttl_expires_and_its_expiry_cannot_be_moved(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        $link = $signer->sign('/download?file=a.pdf', ttlSeconds: 60, now: 1_000);

        self::assertSame(SignatureCheck::Valid, $this->check($signer, $link, 1_059));
        self::assertSame(SignatureCheck::Expired, $this->check($signer, $link, 1_060));
        self::assertSame(SignatureCheck::Invalid, $this->check($signer, str_replace('expires=1060', 'expires=9999', $link), 1_100));
    }

    #[Test]
    public function order_and_encoding_of_the_parameters_do_not_matter(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        $link = $signer->sign('/files/a%20b?b=2&a=1&tags%5B%5D=x&tags%5B%5D=y');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        // As Swoole hands a request over: decoded path, parameters rebuilt.
        self::assertSame(SignatureCheck::Valid, $signer->check('/files/a b', http_build_query(array_reverse($query, true))));
    }

    #[Test]
    public function an_encoded_slash_is_not_a_slash(): void
    {
        // The router keeps them apart, so /files/a%2Fb and /files/a/b can be
        // two routes; one signature must not open both.
        $signer = UrlSigner::withSecrets('secret-a');
        $link = $signer->sign('/files/a%2Fb');
        $query = (string) parse_url($link, PHP_URL_QUERY);

        self::assertSame(SignatureCheck::Valid, $signer->check('/files/a%2Fb', $query));
        self::assertSame(SignatureCheck::Invalid, $signer->check('/files/a/b', $query));
    }

    /** A literal "%2F" in a path (sent as %252F) signed the same as an encoded slash. */
    #[Test]
    public function an_encoded_percent_is_not_an_encoded_slash(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        $query = (string) parse_url($signer->sign('/files/a%2Fb'), PHP_URL_QUERY);

        self::assertSame(SignatureCheck::Valid, $signer->check('/files/a%2Fb', $query));
        self::assertSame(SignatureCheck::Invalid, $signer->check('/files/a%252Fb', $query));
    }

    #[Test]
    public function a_zero_ttl_or_a_url_that_does_not_parse_is_refused(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        foreach ([['/x', 0], ['http://', null]] as [$url, $ttl]) {
            try {
                $signer->sign($url, $ttl);
                self::fail('signed ' . $url);
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    $ttl === 0 ? 'A signed URL needs a TTL of at least one second.' : "Cannot sign '{$url}': not a URL.",
                    $e->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function an_empty_or_array_signature_is_invalid(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');

        self::assertSame(SignatureCheck::Invalid, $signer->check('/x', 'signature='));
        self::assertSame(SignatureCheck::Invalid, $signer->check('/x', 'signature[]=a'));
    }

    /** A signed expiry that is not whole seconds is expired, never valid for ever. */
    #[Test]
    public function a_signed_but_malformed_expiry_is_expired(): void
    {
        $signer = UrlSigner::withSecrets('secret-a');
        // sign() writes expires itself, so a malformed one is signed by hand.
        $signature = new \ReflectionMethod($signer, 'signature');
        foreach (['abc', ['1']] as $expires) {
            $query = http_build_query(['expires' => $expires]);
            $mac = $signature->invoke($signer, 'secret-a', '/x', ['expires' => $expires]);

            self::assertSame(SignatureCheck::Expired, $signer->check('/x', $query . '&signature=' . $mac, 0), json_encode($expires));
        }
    }

    #[Test]
    public function the_link_keeps_its_host_and_its_query_as_written(): void
    {
        $link = UrlSigner::withSecrets('secret-a')->sign('https://example.org/confirm?a.b=1&x=y#top', ttlSeconds: 60, now: 0);

        self::assertStringStartsWith('https://example.org/confirm?a.b=1&x=y&expires=60&signature=', $link);
        self::assertStringEndsWith('#top', $link);
    }

    #[Test]
    public function a_link_signed_before_a_rotation_still_verifies(): void
    {
        $link = UrlSigner::withSecrets('old')->sign('/unsubscribe?user=42');

        self::assertSame(SignatureCheck::Valid, $this->check(UrlSigner::withSecrets('new', ['old']), $link));
        self::assertStringNotContainsString(
            (string) parse_url($link, PHP_URL_QUERY),
            UrlSigner::withSecrets('new', ['old'])->sign('/unsubscribe?user=42'),
            'new links are signed with the current secret',
        );
    }

    private function check(UrlSigner $signer, string $link, ?int $now = null): SignatureCheck
    {
        return $signer->check((string) parse_url($link, PHP_URL_PATH), (string) parse_url($link, PHP_URL_QUERY), $now);
    }
}
