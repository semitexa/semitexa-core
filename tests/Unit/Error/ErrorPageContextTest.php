<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Error\ErrorPageContext;
use Semitexa\Core\Http\RedirectTarget;
use Semitexa\Core\Request;

/**
 * returnTo is where an error page sends the visitor back to, so it must be the
 * address as they asked for it, in every shape a request arrives in.
 */
final class ErrorPageContextTest extends TestCase
{
    #[Test]
    public function under_swoole_the_locale_prefix_and_the_raw_query_are_kept(): void
    {
        // The locale layer has stripped /uk from the routed uri; Swoole hands the
        // query over parsed (a repeated key collapsed) and raw in query_string.
        $request = $this->request('/account', ['a' => '2', 'preview' => ''], ['request_uri' => '/uk/account', 'query_string' => 'a=1&a=2&preview']);

        self::assertSame('/uk/account?a=1&a=2&preview', ErrorPageContext::returnToOf($request));
    }

    #[Test]
    public function a_backslash_in_the_query_is_encoded_so_the_return_survives_the_redirect_guard(): void
    {
        $request = $this->request('/search', ['q' => 'C:\\Users'], ['request_uri' => '/uk/search', 'query_string' => 'q=C:\\Users']);
        $returnTo = ErrorPageContext::returnToOf($request);

        self::assertSame('/uk/search?q=C:%5CUsers', $returnTo);
        self::assertSame($returnTo, RedirectTarget::localPath($returnTo));
        self::assertSame($returnTo, RedirectTarget::sanitize($returnTo, 'example.test'));
    }

    #[Test]
    public function a_uri_that_carries_its_query_gets_exactly_one_question_mark(): void
    {
        $request = $this->request('/uk/checkout?cart=123', [], ['REQUEST_URI' => '/uk/checkout?cart=123']);

        self::assertSame('/uk/checkout?cart=123', ErrorPageContext::returnToOf($request));
    }

    #[Test]
    public function with_no_raw_query_the_parsed_parameters_are_used(): void
    {
        $request = $this->request('/checkout', ['cart' => '123'], ['request_uri' => '/checkout']);

        self::assertSame('/checkout?cart=123', ErrorPageContext::returnToOf($request));
    }

    #[Test]
    public function a_request_with_no_server_entry_falls_back_to_the_routed_path(): void
    {
        self::assertSame('/account', ErrorPageContext::returnToOf($this->request('/account', [], [])));
    }

    #[Test]
    public function a_context_built_without_returnto_points_home_not_nowhere(): void
    {
        $context = new ErrorPageContext(
            statusCode: 401,
            reasonPhrase: 'Unauthorized',
            publicMessage: 'Authentication required',
            requestPath: '/account',
            requestMethod: 'GET',
            requestId: null,
            debugEnabled: false,
            exceptionClass: null,
            debugMessage: null,
            trace: null,
            originalRouteName: null,
        );

        self::assertSame('/', $context->returnTo);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $server
     */
    private function request(string $uri, array $query, array $server): Request
    {
        return new Request('GET', $uri, ['Accept' => 'text/html'], $query, [], $server, []);
    }
}
