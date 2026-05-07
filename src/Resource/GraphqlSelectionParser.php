<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\Exception\MalformedGraphqlSelectionException;
use Semitexa\Core\Resource\Exception\UnsupportedGraphqlFeatureException;

/**
 * Phase 5c: bounded GraphQL selection parser.
 *
 * **Deliberately NOT a GraphQL parser.** This class extracts a single
 * named or anonymous query operation's *selection set* from a query
 * string. Supports:
 *
 *   - `query { … }`
 *   - `{ … }` (shorthand anonymous query)
 *   - `query OperationName { … }` (name accepted, ignored)
 *   - field names: identifier characters
 *   - nested selection sets: `field { sub-field … }`
 *
 * Rejects (HTTP 400 via `UnsupportedGraphqlFeatureException`):
 *
 *   - mutations (`mutation { … }`)
 *   - subscriptions (`subscription { … }`)
 *   - fragments (`...Foo` / `fragment Foo on …`)
 *   - variables (`$var`)
 *   - directives (`@skip`, `@include`)
 *   - aliases (`alias: field`)
 *   - field arguments (`field(arg: 1)`)
 *
 * Pure: no IO, no DB, no Request access, no eval. Recursive descent
 * tokeniser; safe against runaway recursion via depth tracking and a
 * deterministic "unbalanced braces" failure mode.
 */
#[AsService]
final class GraphqlSelectionParser
{
    /**
     * Hard ceiling on selection-set nesting recursion to avoid stack
     * blow-up on adversarial input. The translator enforces a smaller
     * caller-controlled limit on top of this; this is just a safety net.
     */
    public const MAX_PARSE_DEPTH = 8;

    /**
     * Parse a GraphQL query string into a single root field with a
     * nested selection tree.
     *
     * @return GraphqlSelectionNode the implicit root field that wraps
     *         the actual root field declared by the client
     */
    public function parse(string $query): GraphqlSelectionNode
    {
        $stripped = $this->stripComments($query);
        $tokens   = $this->tokenize($stripped);

        if ($tokens === []) {
            throw new MalformedGraphqlSelectionException('empty query');
        }

        $i = 0;
        $this->skipOperationHeader($tokens, $i);

        if (!isset($tokens[$i]) || $tokens[$i]['v'] !== '{') {
            throw new MalformedGraphqlSelectionException(
                'expected "{" at start of root selection set',
                $tokens[$i]['o'] ?? -1,
                (string) ($tokens[$i]['v'] ?? ''),
            );
        }

        $root = new GraphqlSelectionNode('<root>');
        $this->parseSelectionSet($tokens, $i, $root, depth: 0);

        if (isset($tokens[$i])) {
            // Trailing tokens after the root selection set are not allowed
            // (multiple operations, stray noise).
            throw new MalformedGraphqlSelectionException(
                'unexpected tokens after root selection set',
                $tokens[$i]['o'],
                (string) $tokens[$i]['v'],
            );
        }

        return $root;
    }

    private function stripComments(string $query): string
    {
        // GraphQL comments start with `#` and run to end-of-line. Strip
        // them so the tokeniser doesn't have to know about them.
        return preg_replace('/#[^\n\r]*/', '', $query) ?? $query;
    }

    /**
     * @return list<array{v: string, o: int}> token + offset list
     */
    private function tokenize(string $query): array
    {
        $tokens = [];
        $len    = strlen($query);
        $i      = 0;

        while ($i < $len) {
            $c = $query[$i];

            if (ctype_space($c) || $c === ',') {
                // GraphQL treats commas as insignificant whitespace.
                $i++;
                continue;
            }

            if ($c === '{' || $c === '}' || $c === '(' || $c === ')') {
                $tokens[] = ['v' => $c, 'o' => $i];
                $i++;
                continue;
            }

            if ($c === '$') {
                throw new UnsupportedGraphqlFeatureException(
                    'variables',
                    hint: 'Phase 5c selection bridge does not parse variables.',
                );
            }

            if ($c === '@') {
                throw new UnsupportedGraphqlFeatureException(
                    'directives',
                    hint: 'Phase 5c selection bridge does not parse directives.',
                );
            }

            if ($c === '.') {
                // Spread operator `...` → fragment usage.
                if (substr($query, $i, 3) === '...') {
                    throw new UnsupportedGraphqlFeatureException(
                        'fragments',
                        hint: 'Phase 5c selection bridge does not parse fragments or fragment spreads.',
                    );
                }
                throw new MalformedGraphqlSelectionException('unexpected "."', $i, '.');
            }

            if ($c === ':') {
                throw new UnsupportedGraphqlFeatureException(
                    'aliases',
                    hint: 'Phase 5c selection bridge does not support field aliases.',
                );
            }

            if ($this->isNameStart($c)) {
                $start = $i;
                while ($i < $len && $this->isNameContinue($query[$i])) {
                    $i++;
                }
                $tokens[] = ['v' => substr($query, $start, $i - $start), 'o' => $start];
                continue;
            }

            throw new MalformedGraphqlSelectionException(
                sprintf('unexpected character "%s"', $c),
                $i,
                substr($query, $i, 8),
            );
        }

        return $tokens;
    }

    /**
     * @param list<array{v: string, o: int}> $tokens
     */
    private function skipOperationHeader(array $tokens, int &$i): void
    {
        $first = $tokens[$i]['v'] ?? null;

        // Shorthand anonymous query: `{ … }` — no header to skip.
        if ($first === '{') {
            return;
        }

        if ($first === 'mutation') {
            throw new UnsupportedGraphqlFeatureException(
                'mutations',
                hint: 'Phase 5c selection bridge accepts only `query { … }` operations.',
            );
        }
        if ($first === 'subscription') {
            throw new UnsupportedGraphqlFeatureException('subscriptions');
        }
        if ($first === 'fragment') {
            throw new UnsupportedGraphqlFeatureException('fragments');
        }

        if ($first !== 'query') {
            throw new MalformedGraphqlSelectionException(
                sprintf('expected `query` keyword or `{`, got "%s"', $first ?? '<eof>'),
                $tokens[$i]['o'] ?? -1,
                (string) ($first ?? ''),
            );
        }
        $i++;

        // Optional operation name.
        if (isset($tokens[$i]) && $this->isName($tokens[$i]['v'])) {
            $i++;
        }

        // Variable definitions block — explicitly rejected.
        if (isset($tokens[$i]) && $tokens[$i]['v'] === '(') {
            throw new UnsupportedGraphqlFeatureException(
                'variable definitions',
                hint: 'Phase 5c selection bridge does not accept variable declarations.',
            );
        }
    }

    /**
     * @param list<array{v: string, o: int}> $tokens
     */
    private function parseSelectionSet(array $tokens, int &$i, GraphqlSelectionNode $parent, int $depth): void
    {
        if ($depth >= self::MAX_PARSE_DEPTH) {
            throw new MalformedGraphqlSelectionException(
                sprintf('parse-time depth limit %d reached', self::MAX_PARSE_DEPTH),
                $tokens[$i]['o'] ?? -1,
            );
        }

        if (!isset($tokens[$i]) || $tokens[$i]['v'] !== '{') {
            throw new MalformedGraphqlSelectionException(
                'expected "{"',
                $tokens[$i]['o'] ?? -1,
                (string) ($tokens[$i]['v'] ?? ''),
            );
        }
        $i++; // consume '{'

        if (isset($tokens[$i]) && $tokens[$i]['v'] === '}') {
            // Empty selection set — accepted (vacuously) but parent stays empty.
            $i++;
            return;
        }

        while (isset($tokens[$i])) {
            if ($tokens[$i]['v'] === '}') {
                $i++;
                return;
            }

            // Field arguments — explicitly rejected. Detect by looking
            // ahead: a field followed by `(` is an argument list.
            $name = $tokens[$i]['v'];
            if (!$this->isName($name)) {
                throw new MalformedGraphqlSelectionException(
                    sprintf('expected field name, got "%s"', $name),
                    $tokens[$i]['o'],
                    (string) $name,
                );
            }
            $i++; // consume name

            if (isset($tokens[$i]) && $tokens[$i]['v'] === '(') {
                throw new UnsupportedGraphqlFeatureException(
                    'field arguments',
                    hint: 'Phase 5c selection bridge does not accept field arguments.',
                );
            }

            $child = new GraphqlSelectionNode($name);
            $parent->addChild($child);

            if (isset($tokens[$i]) && $tokens[$i]['v'] === '{') {
                $this->parseSelectionSet($tokens, $i, $child, $depth + 1);
            }
        }

        throw new MalformedGraphqlSelectionException('unterminated selection set — missing "}"');
    }

    private function isNameStart(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_';
    }

    private function isNameContinue(string $c): bool
    {
        return $this->isNameStart($c) || ($c >= '0' && $c <= '9');
    }

    private function isName(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (!$this->isNameStart($token[0])) {
            return false;
        }
        for ($k = 1, $n = strlen($token); $k < $n; $k++) {
            if (!$this->isNameContinue($token[$k])) {
                return false;
            }
        }
        return true;
    }
}
