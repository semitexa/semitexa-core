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
    #[Test]
    public function an_explicit_watch_scopes_wins_then_a_declaring_route_attribute_then_none(): void
    {
        self::assertSame(['explicit'], WatchScopesOf::payload(ExplicitAndDeclaredFixture::class));
        self::assertSame(['from-attribute:' . DeclaredOnlyFixture::class], WatchScopesOf::payload(DeclaredOnlyFixture::class));
        self::assertSame([], WatchScopesOf::payload(\stdClass::class));
        self::assertSame([], WatchScopesOf::payload('No\\Such\\Class'));
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
