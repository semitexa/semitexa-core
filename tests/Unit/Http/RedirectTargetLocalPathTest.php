<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\RedirectTarget;

/**
 * A return address comes back from the browser, so anyone can post their own.
 * localPath() keeps only a path on this origin: everything sanitize() lets
 * through for a handler's own redirect (a login provider, localhost, a sibling
 * host) is refused here.
 */
final class RedirectTargetLocalPathTest extends TestCase
{
    #[Test]
    #[DataProvider('pathsOnThisOrigin')]
    public function a_path_on_this_origin_is_kept(string $target): void
    {
        self::assertSame($target, RedirectTarget::localPath($target));
    }

    /** @return iterable<string, array{string}> */
    public static function pathsOnThisOrigin(): iterable
    {
        yield 'root' => ['/'];
        yield 'locale prefix and query' => ['/uk/account?tab=keys'];
        yield 'encoded backslash in the query' => ['/search?q=C:%5CUsers'];
        yield 'fragment' => ['/docs/page#install'];
    }

    #[Test]
    #[DataProvider('targetsThatLeaveTheOrigin')]
    public function anything_that_leaves_the_origin_falls_back(string $target): void
    {
        self::assertSame('/account', RedirectTarget::localPath($target, '/account'));
    }

    /** @return iterable<string, array{string}> */
    public static function targetsThatLeaveTheOrigin(): iterable
    {
        // sanitize() passes the first three: they are legitimate for a handler's own redirect.
        yield 'login provider' => ['https://github.com/attacker'];
        yield 'loopback' => ['http://127.0.0.1:9000/x'];
        yield 'localhost' => ['http://localhost/x'];
        yield 'protocol relative' => ['//evil.com/'];
        yield 'slash backslash' => ['/\\evil.com/'];
        yield 'tab between slashes' => ["/\t/evil.com"];
        yield 'line break between slashes' => ["/\n/evil.com"];
        yield 'leading space' => [' /account'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative without a slash' => ['account'];
        yield 'empty' => [''];
    }
}
