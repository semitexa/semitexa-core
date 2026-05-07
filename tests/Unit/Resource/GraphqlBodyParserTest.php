<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\Exception\InvalidGraphqlQueryException;
use Semitexa\Core\Resource\Exception\MalformedGraphqlRequestBodyException;
use Semitexa\Core\Resource\Exception\MissingGraphqlQueryException;
use Semitexa\Core\Resource\Exception\UnsupportedGraphqlRequestBodyException;
use Semitexa\Core\Resource\GraphqlBodyParser;

/**
 * Phase 5d: extract a GraphQL query string from the request body.
 * Tests construct a real Request with a forged body + headers + method
 * and assert the parser's behaviour for each supported and rejected
 * shape.
 */
final class GraphqlBodyParserTest extends TestCase
{
    private GraphqlBodyParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GraphqlBodyParser();
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $body, array $headers = []): Request
    {
        return new Request(
            method:  $method,
            uri:     '/customers/123',
            headers: $headers,
            query:   [],
            post:    [],
            server:  [],
            cookies: [],
            content: $body,
        );
    }

    #[Test]
    public function get_request_returns_null(): void
    {
        $r = $this->request('GET', '', ['Content-Type' => 'application/json']);
        self::assertNull($this->parser->extract($r));
    }

    #[Test]
    public function post_with_no_content_type_returns_null(): void
    {
        // Caller falls back to ?query= / ?include= when the body has no
        // recognisable Content-Type.
        $r = $this->request('POST', '', []);
        self::assertNull($this->parser->extract($r));
    }

    #[Test]
    public function post_application_json_with_query_returns_query_string(): void
    {
        $body = json_encode(['query' => '{ customer { id name } }'], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);
        self::assertSame('{ customer { id name } }', $this->parser->extract($r));
    }

    #[Test]
    public function post_application_graphql_returns_raw_body(): void
    {
        $r = $this->request(
            'POST',
            "query { customer { id addresses { id city } } }\n",
            ['Content-Type' => 'application/graphql'],
        );
        self::assertSame(
            "query { customer { id addresses { id city } } }\n",
            $this->parser->extract($r),
        );
    }

    #[Test]
    public function post_application_json_with_charset_parameter_is_accepted(): void
    {
        $body = json_encode(['query' => '{ customer { id } }'], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json; charset=utf-8']);
        self::assertSame('{ customer { id } }', $this->parser->extract($r));
    }

    #[Test]
    public function malformed_json_returns_400(): void
    {
        $r = $this->request('POST', '{not valid json', ['Content-Type' => 'application/json']);
        try {
            $this->parser->extract($r);
            self::fail('Expected MalformedGraphqlRequestBodyException');
        } catch (MalformedGraphqlRequestBodyException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertStringContainsString('json_decode', $e->getMessage());
        }
    }

    #[Test]
    public function empty_json_body_returns_400(): void
    {
        $r = $this->request('POST', '', ['Content-Type' => 'application/json']);
        $this->expectException(MalformedGraphqlRequestBodyException::class);
        $this->expectExceptionMessageMatches('/empty JSON body/');
        $this->parser->extract($r);
    }

    #[Test]
    public function json_array_root_returns_400(): void
    {
        $r = $this->request('POST', '[1,2,3]', ['Content-Type' => 'application/json']);
        $this->expectException(MalformedGraphqlRequestBodyException::class);
        $this->expectExceptionMessageMatches('/must decode to an object/');
        $this->parser->extract($r);
    }

    #[Test]
    public function missing_query_field_returns_400(): void
    {
        $body = json_encode(['operation' => 'getCustomer'], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);
        $this->expectException(MissingGraphqlQueryException::class);
        $this->parser->extract($r);
    }

    #[Test]
    public function null_query_returns_400(): void
    {
        $r = $this->request('POST', '{"query": null}', ['Content-Type' => 'application/json']);
        $this->expectException(InvalidGraphqlQueryException::class);
        $this->expectExceptionMessageMatches('/must not be null/');
        $this->parser->extract($r);
    }

    #[Test]
    public function non_string_query_returns_400(): void
    {
        $r = $this->request('POST', '{"query": 123}', ['Content-Type' => 'application/json']);
        $this->expectException(InvalidGraphqlQueryException::class);
        $this->expectExceptionMessageMatches('/must be a string/');
        $this->parser->extract($r);
    }

    #[Test]
    public function empty_string_query_returns_400(): void
    {
        $r = $this->request('POST', '{"query": "   "}', ['Content-Type' => 'application/json']);
        $this->expectException(InvalidGraphqlQueryException::class);
        $this->expectExceptionMessageMatches('/must not be empty/');
        $this->parser->extract($r);
    }

    #[Test]
    public function non_empty_variables_returns_400(): void
    {
        $body = json_encode([
            'query'     => '{ customer { id } }',
            'variables' => ['x' => 1],
        ], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);

        try {
            $this->parser->extract($r);
            self::fail('Expected UnsupportedGraphqlRequestBodyException');
        } catch (UnsupportedGraphqlRequestBodyException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertStringContainsString('variables', $e->getMessage());
        }
    }

    #[Test]
    public function empty_variables_object_is_ignored(): void
    {
        $body = json_encode([
            'query'     => '{ customer { id } }',
            'variables' => [],
        ], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);
        self::assertSame('{ customer { id } }', $this->parser->extract($r));
    }

    #[Test]
    public function null_variables_is_ignored(): void
    {
        $body = json_encode([
            'query'     => '{ customer { id } }',
            'variables' => null,
        ], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);
        self::assertSame('{ customer { id } }', $this->parser->extract($r));
    }

    #[Test]
    public function operation_name_is_silently_ignored(): void
    {
        $body = json_encode([
            'query'         => 'query GetCustomer { customer { id } }',
            'operationName' => 'GetCustomer',
        ], JSON_THROW_ON_ERROR);
        $r = $this->request('POST', $body, ['Content-Type' => 'application/json']);
        self::assertSame(
            'query GetCustomer { customer { id } }',
            $this->parser->extract($r),
        );
    }

    #[Test]
    public function unsupported_content_type_returns_400(): void
    {
        $r = $this->request('POST', 'something', ['Content-Type' => 'application/xml']);
        try {
            $this->parser->extract($r);
            self::fail('Expected UnsupportedGraphqlRequestBodyException');
        } catch (UnsupportedGraphqlRequestBodyException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertStringContainsString('application/xml', $e->getMessage());
        }
    }

    #[Test]
    public function empty_application_graphql_body_returns_400(): void
    {
        $r = $this->request('POST', '   ', ['Content-Type' => 'application/graphql']);
        $this->expectException(MalformedGraphqlRequestBodyException::class);
        $this->expectExceptionMessageMatches('/empty application\/graphql body/');
        $this->parser->extract($r);
    }

    #[Test]
    public function repeated_extracts_with_different_bodies_do_not_leak_state(): void
    {
        $body1 = json_encode(['query' => '{ a }'], JSON_THROW_ON_ERROR);
        $body2 = json_encode(['query' => '{ b }'], JSON_THROW_ON_ERROR);
        $body3 = json_encode(['query' => '{ a }'], JSON_THROW_ON_ERROR);

        $r1 = $this->request('POST', $body1, ['Content-Type' => 'application/json']);
        $r2 = $this->request('POST', $body2, ['Content-Type' => 'application/json']);
        $r3 = $this->request('POST', $body3, ['Content-Type' => 'application/json']);

        self::assertSame('{ a }', $this->parser->extract($r1));
        self::assertSame('{ b }', $this->parser->extract($r2));
        self::assertSame('{ a }', $this->parser->extract($r3));
    }
}
