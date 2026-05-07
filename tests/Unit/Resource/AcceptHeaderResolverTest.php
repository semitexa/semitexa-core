<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\AcceptHeaderResolver;
use Semitexa\Core\Resource\RenderProfile;

final class AcceptHeaderResolverTest extends TestCase
{
    private AcceptHeaderResolver $r;

    protected function setUp(): void
    {
        $this->r = new AcceptHeaderResolver();
    }

    #[Test]
    public function exact_application_json_selects_json(): void
    {
        $p = $this->r->resolve('application/json', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function exact_application_ld_json_selects_jsonld(): void
    {
        $p = $this->r->resolve('application/ld+json', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::JsonLd, $p);
    }

    #[Test]
    public function missing_accept_returns_first_declared_profile(): void
    {
        $p = $this->r->resolve(null, [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::Json, $p);

        $p = $this->r->resolve(null, [RenderProfile::JsonLd, RenderProfile::Json]);
        self::assertSame(RenderProfile::JsonLd, $p);
    }

    #[Test]
    public function empty_accept_returns_first_declared_profile(): void
    {
        $p = $this->r->resolve('', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::Json, $p);

        $p = $this->r->resolve('   ', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function star_star_selects_first_declared_profile(): void
    {
        $p = $this->r->resolve('*/*', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function application_star_selects_first_declared_application_profile(): void
    {
        // application/* matches both application/json and application/ld+json
        // → highest q (1.0 default) → tie → declaration order wins.
        $p = $this->r->resolve('application/*', [RenderProfile::JsonLd, RenderProfile::Json]);
        self::assertSame(RenderProfile::JsonLd, $p);
    }

    #[Test]
    public function highest_q_wins(): void
    {
        $p = $this->r->resolve(
            'application/ld+json, application/json;q=0.5',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);

        $p = $this->r->resolve(
            'application/json;q=0.5, application/ld+json;q=0.9',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);

        $p = $this->r->resolve(
            'application/json;q=0.9, application/ld+json;q=0.5',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function tie_at_highest_q_falls_back_to_declaration_order(): void
    {
        $p = $this->r->resolve(
            'application/json;q=0.7, application/ld+json;q=0.7',
            [RenderProfile::JsonLd, RenderProfile::Json],
        );
        self::assertSame(RenderProfile::JsonLd, $p);

        $p = $this->r->resolve(
            'application/json, application/ld+json',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function q_zero_excludes_a_profile(): void
    {
        // application/json;q=0 must be ignored even though it would otherwise match.
        $p = $this->r->resolve(
            'application/json;q=0, application/ld+json',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);

        // If both are q=0 → no match → null.
        $p = $this->r->resolve(
            'application/json;q=0, application/ld+json;q=0',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertNull($p);
    }

    #[Test]
    public function most_specific_accept_match_overrides_wildcard_q(): void
    {
        $p = $this->r->resolve(
            'application/json;q=0, */*;q=1',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);
    }

    #[Test]
    public function out_of_range_q_values_are_ignored(): void
    {
        $p = $this->r->resolve(
            'application/json;q=9.5, application/ld+json;q=0.8',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);

        $p = $this->r->resolve(
            'application/json;q=2, application/ld+json;q=0.1',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::JsonLd, $p);
    }

    #[Test]
    public function unsupported_specific_type_returns_null(): void
    {
        $p = $this->r->resolve('application/xml', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertNull($p);
    }

    #[Test]
    public function text_html_returns_null_when_route_does_not_declare_html(): void
    {
        $p = $this->r->resolve('text/html', [RenderProfile::Json, RenderProfile::JsonLd]);
        self::assertNull($p);
    }

    #[Test]
    public function text_html_selects_html_when_route_declares_it(): void
    {
        $p = $this->r->resolve('text/html', [RenderProfile::Json, RenderProfile::Html]);
        self::assertSame(RenderProfile::Html, $p);
    }

    #[Test]
    public function media_type_parameters_are_ignored(): void
    {
        $p = $this->r->resolve(
            'application/json; charset=utf-8',
            [RenderProfile::Json, RenderProfile::JsonLd],
        );
        self::assertSame(RenderProfile::Json, $p);
    }

    #[Test]
    public function determinism_same_inputs_same_output(): void
    {
        $headers = [
            'application/json',
            'application/ld+json, application/json;q=0.5',
            '*/*',
            null,
        ];
        foreach ($headers as $h) {
            $a = $this->r->resolve($h, [RenderProfile::Json, RenderProfile::JsonLd]);
            $b = $this->r->resolve($h, [RenderProfile::Json, RenderProfile::JsonLd]);
            self::assertSame($a, $b, sprintf('Resolve must be deterministic for header: %s', var_export($h, true)));
        }
    }

    #[Test]
    public function media_type_helper_returns_canonical_media_types(): void
    {
        self::assertSame('application/json', AcceptHeaderResolver::profileMediaType(RenderProfile::Json));
        self::assertSame('application/ld+json', AcceptHeaderResolver::profileMediaType(RenderProfile::JsonLd));
        self::assertSame(
            ['application/json', 'application/ld+json'],
            AcceptHeaderResolver::mediaTypesForProfiles([RenderProfile::Json, RenderProfile::JsonLd]),
        );
    }
}
