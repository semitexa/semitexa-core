<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\ResponseRenderer;

/**
 * An application split across hosts — a public site on the apex, a cabinet on
 * `account.` — moves visitors between them as ordinary navigation. The
 * open-redirect guard had no way to express that and replaced the target with
 * '/', which reads as the redirect simply not working.
 *
 * The dot boundary is the whole safety of it: a bare suffix match is the
 * classic form of this bug, because `evil-example.com` ends with `example.com`.
 */
final class SiblingHostRedirectTest extends TestCase
{
    #[Test]
    public function a_cabinet_may_send_a_visitor_to_the_public_site(): void
    {
        self::assertTrue($this->isSibling('example.com', 'account.example.com'));
    }

    #[Test]
    public function the_public_site_may_send_a_visitor_to_the_cabinet(): void
    {
        self::assertTrue($this->isSibling('account.example.com', 'example.com'));
    }

    /**
     * The attack this guard exists for: a name an attacker can register that
     * merely ENDS with ours. No label boundary, no relationship.
     */
    #[Test]
    public function a_lookalike_domain_is_not_a_sibling(): void
    {
        self::assertFalse($this->isSibling('evil-example.com', 'example.com'));
        self::assertFalse($this->isSibling('example.com.evil.com', 'example.com'));
        self::assertFalse($this->isSibling('example.com', 'notexample.com'));
    }

    #[Test]
    public function an_unrelated_host_is_not_a_sibling(): void
    {
        self::assertFalse($this->isSibling('elsewhere.org', 'example.com'));
    }

    /** A single label names no site, so it can have no sibling. */
    #[Test]
    public function a_dotless_host_has_no_siblings(): void
    {
        self::assertFalse($this->isSibling('localhost', 'example.com'));
        self::assertFalse($this->isSibling('example.com', 'localhost'));
        self::assertFalse($this->isSibling('', 'example.com'));
    }

    /** Deeper subdomains are still the same site. */
    #[Test]
    public function a_deeper_subdomain_is_still_the_same_site(): void
    {
        self::assertTrue($this->isSibling('example.com', 'staging.account.example.com'));
    }

    private function isSibling(string $redirectHost, string $requestHost): bool
    {
        $method = new \ReflectionMethod(ResponseRenderer::class, 'isSiblingHost');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $redirectHost, $requestHost);
    }
}
