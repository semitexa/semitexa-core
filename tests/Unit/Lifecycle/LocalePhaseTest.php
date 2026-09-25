<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Lifecycle\LocalePhase;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Request;
use Semitexa\Locale\Application\Service\LocaleBootstrapper;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\Context\LocaleManager;

/**
 * `/en/...` under URL prefixes is answered with a 301 to the bare path. The
 * bare path is whatever followed the prefix, so a second slash or a backslash
 * right after it turned the Location into a protocol-relative URL on another
 * host.
 */
final class LocalePhaseTest extends TestCase
{
    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $localeState;

    protected function setUp(): void
    {
        // Snapshot BEFORE resetting: whatever an earlier test left in the
        // store is put back in tearDown(), so this class leaves no trace.
        $this->localeState = StaticState::snapshot(LocaleContextStore::class);
        LocaleContextStore::clearFallback();
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->localeState);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function defaultLocaleRedirects(): iterable
    {
        yield 'ordinary path' => ['/en/app/bookings', '', '/app/bookings'];
        yield 'prefix alone' => ['/en', '', '/'];
        yield 'query string kept' => ['/en/app', 'tab=2', '/app?tab=2'];
        yield 'double slash after prefix' => ['/en//evil.example/x', '', '/evil.example/x'];
        yield 'backslash after prefix' => ['/en/\\evil.example', '', '/evil.example'];
        yield 'mixed slashes after prefix' => ['/en/\\/\\evil.example', 'a=1', '/evil.example?a=1'];
    }

    #[Test]
    #[DataProvider('defaultLocaleRedirects')]
    public function the_default_locale_prefix_redirect_stays_on_this_host(string $path, string $query, string $expected): void
    {
        $uri = $query === '' ? $path : $path . '?' . $query;
        $request = new Request(
            method: 'GET',
            uri: $uri,
            headers: ['Host' => 'site.test'],
            query: [],
            post: [],
            server: ['request_uri' => $uri, 'query_string' => $query],
            cookies: [],
        );

        $bootstrapper = new LocaleBootstrapper(new LocaleManager(), new LocaleConfig(
            supportedLocales: ['en', 'uk'],
            resolverPriority: ['path'],
            urlPrefixEnabled: true,
        ));
        $phase = new LocalePhase(new RequestScopedContainer(new EmptyContainer()), $bootstrapper);
        $context = new RequestLifecycleContext($request);

        $phase->execute($context);

        self::assertTrue($context->hasEarlyResponse());
        $response = $context->getEarlyResponse();
        self::assertSame(301, $response->getStatusCode());
        self::assertSame($expected, $response->getHeaders()['Location'] ?? null);
    }
}

final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new \RuntimeException("no entry {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }
}
