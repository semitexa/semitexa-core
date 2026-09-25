<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\Exception\NegotiationFailedException;
use Semitexa\Core\Request;

final class ContentNegotiatorTest extends TestCase
{
    private const PRODUCES = ['application/json', 'text/html'];

    #[Test]
    public function a_wildcard_does_not_pick_a_type_the_client_refused(): void
    {
        self::assertSame('html', $this->negotiate('application/json;q=0, */*'));
        self::assertSame('html', $this->negotiate('*/*, application/json;q=0'));
    }

    #[Test]
    public function a_type_wildcard_does_not_pick_a_type_the_client_refused(): void
    {
        self::assertSame(
            'txt',
            ContentNegotiator::negotiateResponseFormat(['text/html', 'text/plain'], $this->request('text/html;q=0, text/*')),
        );
    }

    #[Test]
    public function a_refused_range_is_not_picked_by_a_wildcard_unless_named_exactly(): void
    {
        self::assertSame('json', $this->negotiate('text/*;q=0, */*', ['text/html', 'application/json']));
        self::assertSame('html', $this->negotiate('text/*;q=0, text/html, */*', ['text/html', 'application/json']));
    }

    #[Test]
    public function an_explicit_refusal_is_never_selected(): void
    {
        $this->expectException(NegotiationFailedException::class);

        $this->negotiate('application/json;q=0, */*', ['application/json']);
    }

    #[Test]
    public function a_wildcard_without_refusals_still_picks_the_first_produced_type(): void
    {
        self::assertSame('json', $this->negotiate('text/plain, */*;q=0.1'));
    }

    /**
     * @param list<string> $produces
     */
    #[Test]
    public function an_unrestricted_route_does_not_fall_back_to_a_refused_default(): void
    {
        // No `produces` (ExceptionMapper's case): `*/*` used to hand back the
        // json default the client had just refused.
        $this->expectException(NegotiationFailedException::class);
        ContentNegotiator::negotiateResponseFormat(null, $this->request('application/json;q=0, */*'), 'json');
    }

    #[Test]
    public function an_unrestricted_route_still_serves_its_default_when_it_is_not_refused(): void
    {
        self::assertSame('json', ContentNegotiator::negotiateResponseFormat(null, $this->request('*/*'), 'json'));
        self::assertSame('html', ContentNegotiator::negotiateResponseFormat(null, $this->request('application/json;q=0, text/html'), 'json'));
    }

    #[Test]
    public function a_produce_without_a_format_key_does_not_borrow_a_refused_default(): void
    {
        // `?? $defaultFormat` labelled an unknown produce with the default —
        // the refused one, here.
        $this->expectException(NegotiationFailedException::class);
        ContentNegotiator::negotiateResponseFormat(['text/csv'], $this->request('application/json;q=0, */*'), 'json');
    }

    #[Test]
    public function a_global_refusal_refuses_everything_not_named(): void
    {
        foreach ([null, self::PRODUCES] as $produces) {
            try {
                ContentNegotiator::negotiateResponseFormat($produces, $this->request('*/*;q=0'), 'json');
                self::fail('*/*;q=0 was answered for produces ' . json_encode($produces));
            } catch (NegotiationFailedException) {
                self::addToAssertionCount(1);
            }
        }
        // …unless a more specific entry names the type with a non-zero q.
        self::assertSame('html', $this->negotiate('*/*;q=0, text/html'));
    }

    #[Test]
    public function each_type_takes_the_quality_of_its_most_specific_range(): void
    {
        // html is named at 0.1; json only inherits */* at 1 — json is preferred.
        self::assertSame('json', $this->negotiate('text/*;q=0, text/html;q=0.1, */*;q=1', ['text/html', 'application/json']));
        self::assertSame('json', $this->negotiate('text/html;q=0.9, */*', ['text/html', 'application/json']));
    }

    #[Test]
    public function equal_quality_goes_to_the_client_order_then_the_route_order(): void
    {
        self::assertSame('json', $this->negotiate('application/json, text/html', ['text/html', 'application/json']));
        self::assertSame('html', $this->negotiate('*/*;q=0.5', ['text/html', 'application/json']));
        // What a browser sends: html by name at 1 beats everything at 0.8.
        self::assertSame('html', $this->negotiate(
            'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            ['application/json', 'text/html'],
        ));
    }

    private function negotiate(string $accept, array $produces = self::PRODUCES): string
    {
        return ContentNegotiator::negotiateResponseFormat($produces, $this->request($accept));
    }

    private function request(string $accept): Request
    {
        return new Request(
            method: 'GET',
            uri: '/demo',
            headers: ['Accept' => $accept],
            query: [],
            post: [],
            server: [],
            cookies: [],
        );
    }
}
