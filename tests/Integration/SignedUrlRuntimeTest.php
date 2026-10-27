<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Security\UrlSigner;
use Semitexa\Core\Tests\Fixtures\SignedUrlDemo\Payload\SignedDownloadPayload;
use Semitexa\Core\Tests\Support\FixtureModules;

/**
 * #[RequiresSignedUrl] through the real pipeline (Application::handleRequest):
 * the signed link answers, a changed, unsigned or expired one is a 403.
 */
final class SignedUrlRuntimeTest extends TestCase
{
    private Application $app;

    public static function setUpBeforeClass(): void
    {
        FixtureModules::install();
    }

    public static function tearDownAfterClass(): void
    {
        FixtureModules::uninstall();
    }

    protected function setUp(): void
    {
        $this->app = new Application();
        ContainerFactory::get()->get(AttributeDiscovery::class)->initialize();
    }

    #[Test]
    public function a_signed_link_answers_and_a_changed_one_does_not(): void
    {
        $signer = ContainerFactory::get()->get(UrlSigner::class);
        $link = $signer->sign(SignedDownloadPayload::PATH . '?file=report.pdf', ttlSeconds: 600);

        self::assertSame(200, $this->get($link)->getStatusCode());
        self::assertSame(403, $this->get(str_replace('report.pdf', 'salaries.pdf', $link))->getStatusCode());
        self::assertSame(403, $this->get(SignedDownloadPayload::PATH . '?file=report.pdf')->getStatusCode(), 'unsigned');
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $signer = ContainerFactory::get()->get(UrlSigner::class);
        $link = $signer->sign(SignedDownloadPayload::PATH . '?file=report.pdf', ttlSeconds: 60, now: time() - 120);

        $response = $this->get($link);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('expired', (string) $response->getContent());
    }

    #[Test]
    public function a_signed_link_sent_with_a_body_is_refused(): void
    {
        // Body fields win over query values when a payload is filled: a signed
        // link replayed with {"file":"salaries.pdf"} would have run with it.
        $link = ContainerFactory::get()->get(UrlSigner::class)->sign(SignedDownloadPayload::PATH . '?file=report.pdf');

        self::assertSame(403, $this->get($link, content: '{"file":"salaries.pdf"}', headers: ['Content-Type' => 'application/json'])->getStatusCode());
        self::assertSame(403, $this->get($link, post: ['file' => 'salaries.pdf'])->getStatusCode());
        self::assertSame(200, $this->get($link)->getStatusCode());
    }

    /** The signature does not cover the method: a link handed out for a GET was good for a DELETE. */
    #[Test]
    public function a_signed_link_is_good_for_get_and_head_only(): void
    {
        $link = ContainerFactory::get()->get(UrlSigner::class)->sign(SignedDownloadPayload::PATH . '?file=report.pdf');

        self::assertSame(403, $this->get($link, method: 'POST')->getStatusCode());
        self::assertSame(403, $this->get($link, method: 'DELETE')->getStatusCode());
        self::assertSame(200, $this->get($link)->getStatusCode());
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $headers
     */
    private function get(string $target, ?string $content = null, array $post = [], array $headers = [], string $method = 'GET'): HttpResponse
    {
        $path = (string) parse_url($target, PHP_URL_PATH);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        // As Swoole hands it over: the path in request_uri, the parameters apart.
        $request = new Request(
            method: $method,
            uri: $path,
            headers: ['Accept' => 'application/json'] + $headers,
            query: $query,
            post: $post,
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'remote_addr' => '203.0.113.20'],
            cookies: [],
            content: $content,
        );

        try {
            return $this->app->handleRequest($request);
        } finally {
            $this->app->requestScopedContainer->reset();
            AuthContextStore::clear();
        }
    }
}
