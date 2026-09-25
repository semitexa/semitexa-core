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
