<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\AcceptHeaderResolver;
use Semitexa\Core\Resource\CrossProfileDispatcher;
use Semitexa\Core\Resource\GraphqlResourceResponse;
use Semitexa\Core\Resource\JsonLdResourceResponse;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\RenderProfile;

/**
 * Phase 5a: confirm the existing CrossProfileDispatcher correctly routes
 * `application/graphql-response+json` to the GraphQL response class on a
 * three-profile route (Json + JsonLd + GraphQL), without regressing JSON or
 * JSON-LD selection.
 */
final class GraphqlAcceptDispatchTest extends TestCase
{
    private CrossProfileDispatcher $d;

    protected function setUp(): void
    {
        $this->d = CrossProfileDispatcher::forTesting(new AcceptHeaderResolver());
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        return [
            RenderProfile::Json->value    => JsonResourceResponse::class,
            RenderProfile::JsonLd->value  => JsonLdResourceResponse::class,
            RenderProfile::GraphQL->value => GraphqlResourceResponse::class,
        ];
    }

    /** @return list<RenderProfile> */
    private function declared(): array
    {
        return [RenderProfile::Json, RenderProfile::JsonLd, RenderProfile::GraphQL];
    }

    #[Test]
    public function application_json_still_selects_json(): void
    {
        $cls = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/json');
        self::assertSame(JsonResourceResponse::class, $cls);
    }

    #[Test]
    public function application_ld_json_still_selects_jsonld(): void
    {
        $cls = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/ld+json');
        self::assertSame(JsonLdResourceResponse::class, $cls);
    }

    #[Test]
    public function application_graphql_response_json_selects_graphql(): void
    {
        $cls = $this->d->resolveResponseClass(
            $this->declared(),
            $this->map(),
            'application/graphql-response+json',
        );
        self::assertSame(GraphqlResourceResponse::class, $cls);
    }

    #[Test]
    public function missing_accept_still_selects_first_declared_profile(): void
    {
        $cls = $this->d->resolveResponseClass($this->declared(), $this->map(), null);
        self::assertSame(JsonResourceResponse::class, $cls);

        $cls = $this->d->resolveResponseClass(
            [RenderProfile::GraphQL, RenderProfile::Json, RenderProfile::JsonLd],
            $this->map(),
            null,
        );
        self::assertSame(GraphqlResourceResponse::class, $cls);
    }

    #[Test]
    public function unsupported_accept_still_returns_406(): void
    {
        $this->expectException(\Semitexa\Core\Resource\Exception\UnsupportedAcceptHeaderException::class);
        $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/xml');
    }

    #[Test]
    public function q_value_negotiation_works_across_three_profiles(): void
    {
        $cls = $this->d->resolveResponseClass(
            $this->declared(),
            $this->map(),
            'application/json;q=0.4, application/ld+json;q=0.6, application/graphql-response+json;q=0.9',
        );
        self::assertSame(GraphqlResourceResponse::class, $cls);
    }

    #[Test]
    public function consecutive_calls_do_not_leak_profile_state(): void
    {
        $a = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/json');
        $b = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/graphql-response+json');
        $c = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/ld+json');
        $d = $this->d->resolveResponseClass($this->declared(), $this->map(), 'application/json');

        self::assertSame(JsonResourceResponse::class, $a);
        self::assertSame(GraphqlResourceResponse::class, $b);
        self::assertSame(JsonLdResourceResponse::class, $c);
        self::assertSame(JsonResourceResponse::class, $d);
    }
}
