<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Contract\DeclaresWatchScopesInterface;
use Semitexa\Core\Http\WatchScopesOf;

/** One answer for a feed's watch scopes: the subscription and the contract both ask here. */
final class WatchScopesOfTest extends TestCase
{
    /** @var array<class-string, list<string>> */
    private array $cacheBefore = [];

    protected function setUp(): void
    {
        $cache = new \ReflectionProperty(WatchScopesOf::class, 'cache');
        /** @var array<class-string, list<string>> $before */
        $before = $cache->getValue();
        $this->cacheBefore = $before;
        $cache->setValue(null, []);
        CountingAttributeFixture::$calls = 0;
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(WatchScopesOf::class, 'cache'))->setValue(null, $this->cacheBefore);
        CountingAttributeFixture::$calls = 0;
    }

    #[Test]
    public function an_explicit_watch_scopes_wins_then_a_declaring_route_attribute_then_none(): void
    {
        self::assertSame(['explicit'], WatchScopesOf::payload(ExplicitAndDeclaredFixture::class));
        self::assertSame(['from-attribute:' . DeclaredOnlyFixture::class], WatchScopesOf::payload(DeclaredOnlyFixture::class));
        self::assertSame([], WatchScopesOf::payload(\stdClass::class));
        // @phpstan-ignore argument.type (a class that does not exist, on purpose)
        self::assertSame([], WatchScopesOf::payload('No\\Such\\Class'));
    }

    #[Test]
    public function a_repeated_question_is_answered_from_the_cache(): void
    {
        $first = WatchScopesOf::payload(CountedFixture::class);

        self::assertSame(['counted'], $first);
        self::assertSame($first, WatchScopesOf::payload(CountedFixture::class));
        self::assertSame(1, CountingAttributeFixture::$calls, 'the declaring attribute is asked once per class');
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DeclaringAttributeFixture implements DeclaresWatchScopesInterface
{
    public function watchScopes(string $payloadClass): array
    {
        return ['from-attribute:' . $payloadClass];
    }
}

#[WatchScopes('explicit')]
#[DeclaringAttributeFixture]
final class ExplicitAndDeclaredFixture
{
}

#[DeclaringAttributeFixture]
final class DeclaredOnlyFixture
{
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CountingAttributeFixture implements DeclaresWatchScopesInterface
{
    public static int $calls = 0;

    public function watchScopes(string $payloadClass): array
    {
        self::$calls++;

        return ['counted'];
    }
}

#[CountingAttributeFixture]
final class CountedFixture
{
}
