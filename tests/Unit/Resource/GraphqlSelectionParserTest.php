<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\MalformedGraphqlSelectionException;
use Semitexa\Core\Resource\Exception\UnsupportedGraphqlFeatureException;
use Semitexa\Core\Resource\GraphqlSelectionParser;

final class GraphqlSelectionParserTest extends TestCase
{
    private GraphqlSelectionParser $p;

    protected function setUp(): void
    {
        $this->p = new GraphqlSelectionParser();
    }

    /** @return list<string> child names of the parsed root field */
    private function childNames(string $query): array
    {
        $root = $this->p->parse($query)->singleRootField();
        $out = [];
        foreach ($root->children() as $c) {
            $out[] = $c->name;
        }
        return $out;
    }

    #[Test]
    public function parses_anonymous_query(): void
    {
        self::assertSame(
            ['id', 'name'],
            $this->childNames('query { customer { id name } }'),
        );
    }

    #[Test]
    public function parses_shorthand_anonymous_query(): void
    {
        self::assertSame(
            ['id', 'name'],
            $this->childNames('{ customer { id name } }'),
        );
    }

    #[Test]
    public function parses_named_query_and_ignores_the_name(): void
    {
        self::assertSame(
            ['id'],
            $this->childNames('query GetCustomer { customer { id } }'),
        );
    }

    #[Test]
    public function parses_nested_selection(): void
    {
        $root = $this->p->parse('{ customer { id addresses { id city } profile { id name } } }')
            ->singleRootField();

        self::assertSame('customer', $root->name);
        self::assertSame(['id', 'addresses', 'profile'], array_map(static fn ($c) => $c->name, $root->children()));

        $addr = $root->children()[1];
        self::assertSame(['id', 'city'], array_map(static fn ($c) => $c->name, $addr->children()));
    }

    #[Test]
    public function strips_comments(): void
    {
        $q = "query {\n  # this is a comment\n  customer {\n    id  # trailing\n  }\n}";
        self::assertSame(['id'], $this->childNames($q));
    }

    #[Test]
    public function commas_are_ignored_as_whitespace(): void
    {
        self::assertSame(['id', 'name'], $this->childNames('{ customer { id, name } }'));
    }

    #[Test]
    public function rejects_empty_query(): void
    {
        $this->expectException(MalformedGraphqlSelectionException::class);
        $this->p->parse('   ');
    }

    #[Test]
    public function rejects_unbalanced_braces(): void
    {
        $this->expectException(MalformedGraphqlSelectionException::class);
        $this->p->parse('{ customer { id }');
    }

    #[Test]
    public function rejects_mutation(): void
    {
        try {
            $this->p->parse('mutation { customer { id } }');
            self::fail('Expected UnsupportedGraphqlFeatureException');
        } catch (UnsupportedGraphqlFeatureException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertStringContainsString('mutations', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_subscription(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('subscription { customer { id } }');
    }

    #[Test]
    public function rejects_fragment_definition(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('fragment X on Customer { id }');
    }

    #[Test]
    public function rejects_fragment_spread(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('{ customer { id ...Frag } }');
    }

    #[Test]
    public function rejects_variable(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('{ customer { addresses(id: $someVar) { id } } }');
    }

    #[Test]
    public function rejects_variable_definitions(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('query Foo($x: ID!) { customer { id } }');
    }

    #[Test]
    public function rejects_directive(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('{ customer { id @skip } }');
    }

    #[Test]
    public function rejects_alias(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('{ customer { c: id } }');
    }

    #[Test]
    public function rejects_field_arguments(): void
    {
        $this->expectException(UnsupportedGraphqlFeatureException::class);
        $this->p->parse('{ customer { addresses(first: 5) { id } } }');
    }

    #[Test]
    public function rejects_trailing_garbage(): void
    {
        $this->expectException(MalformedGraphqlSelectionException::class);
        $this->p->parse('{ customer { id } } ; drop table');
    }

    #[Test]
    public function repeated_parsing_is_deterministic(): void
    {
        $q = '{ customer { id name addresses { id city } } }';
        $a = $this->childNames($q);
        $b = $this->childNames($q);
        self::assertSame($a, $b);
    }
}
