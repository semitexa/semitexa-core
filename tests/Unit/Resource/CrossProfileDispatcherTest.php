<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\AcceptHeaderResolver;
use Semitexa\Core\Resource\CrossProfileDispatcher;
use Semitexa\Core\Resource\Exception\UnsupportedAcceptHeaderException;
use Semitexa\Core\Resource\JsonLdResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;

final class CrossProfileDispatcherTest extends TestCase
{
    private CrossProfileDispatcher $d;

    protected function setUp(): void
    {
        $this->d = CrossProfileDispatcher::forTesting(new AcceptHeaderResolver());
    }

    /** @return array<string, class-string> */
    private function defaultMap(): array
    {
        return [
            RenderProfile::Json->value    => JsonResourceResponse::class,
            RenderProfile::JsonLd->value  => JsonLdResourceResponse::class,
        ];
    }

    #[Test]
    public function selects_json_response_class_for_application_json(): void
    {
        $cls = $this->d->resolveResponseClass(
            [RenderProfile::Json, RenderProfile::JsonLd],
            $this->defaultMap(),
            'application/json',
        );
        self::assertSame(JsonResourceResponse::class, $cls);
    }

    #[Test]
    public function selects_jsonld_response_class_for_application_ld_json(): void
    {
        $cls = $this->d->resolveResponseClass(
            [RenderProfile::Json, RenderProfile::JsonLd],
            $this->defaultMap(),
            'application/ld+json',
        );
        self::assertSame(JsonLdResourceResponse::class, $cls);
    }

    #[Test]
    public function missing_accept_returns_first_declared_profile_class(): void
    {
        $cls = $this->d->resolveResponseClass(
            [RenderProfile::JsonLd, RenderProfile::Json],
            $this->defaultMap(),
            null,
        );
        self::assertSame(JsonLdResourceResponse::class, $cls);
    }

    #[Test]
    public function unsupported_accept_throws_406(): void
    {
        try {
            $this->d->resolveResponseClass(
                [RenderProfile::Json, RenderProfile::JsonLd],
                $this->defaultMap(),
                'application/xml',
                routeContext: '/x',
            );
            self::fail('Expected UnsupportedAcceptHeaderException');
        } catch (UnsupportedAcceptHeaderException $e) {
            self::assertSame(406, $e->getStatusCode()->value);
            $ctx = $e->getErrorContext();
            self::assertSame('application/xml', $ctx['requested_accept']);
            self::assertSame(['json', 'json-ld'], $ctx['supported_profiles']);
            self::assertSame(['application/json', 'application/ld+json'], $ctx['supported_media_types']);
            self::assertSame('/x', $ctx['route']);
        }
    }

    #[Test]
    public function declared_profile_without_response_class_throws_406(): void
    {
        // Misconfiguration: GraphQL declared but not mapped → treat as 406
        // with a clear context, not a generic RuntimeException.
        $this->expectException(UnsupportedAcceptHeaderException::class);
        $this->d->resolveResponseClass(
            [RenderProfile::Json, RenderProfile::GraphQL],
            [RenderProfile::Json->value => JsonResourceResponse::class],
            'application/graphql-response+json',
        );
    }

    #[Test]
    public function dispatch_is_deterministic_across_repeated_calls(): void
    {
        $a = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/ld+json');
        $b = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/ld+json');
        $c = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/ld+json');
        self::assertSame($a, $b);
        self::assertSame($b, $c);
    }

    #[Test]
    public function two_consecutive_calls_with_different_accept_headers_do_not_leak(): void
    {
        $a = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/json');
        $b = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/ld+json');
        $c = $this->d->resolveResponseClass([RenderProfile::Json, RenderProfile::JsonLd], $this->defaultMap(), 'application/json');

        self::assertSame(JsonResourceResponse::class, $a);
        self::assertSame(JsonLdResourceResponse::class, $b);
        self::assertSame(JsonResourceResponse::class, $c);
    }
}
