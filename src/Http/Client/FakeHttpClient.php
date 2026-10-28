<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

use Semitexa\Core\Http\Exception\HttpTransportException;

/**
 * HttpClientInterface for tests: nothing leaves the process. Answers come from
 * when() routes first, then from queue() in order; a request nothing answers
 * fails the test instead of returning something it did not ask for. Every
 * request is recorded for assertSent().
 *
 *     $http = new FakeHttpClient();
 *     $http->when('POST', '#/v1/messages$#', HttpResponse::make(200, '{"ok":true}'));
 *     ... the code under test, with $http injected ...
 *     $http->assertSent(fn (HttpRequest $r) => $r->header('x-api-key') === 'k');
 */
final class FakeHttpClient implements HttpClientInterface
{
    use SendsConvenienceRequests;

    /** @var list<HttpResponse|HttpTransportException> */
    private array $queue = [];

    /** @var list<array{method: string, pattern: string, answer: HttpResponse|HttpTransportException}> */
    private array $routes = [];

    /** @var list<array{request: HttpRequest, options: HttpCallOptions}> */
    private array $sent = [];

    public function queue(HttpResponse|HttpTransportException ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->queue[] = $answer;
        }

        return $this;
    }

    /** Answer every $method request whose URL matches the regex $urlPattern ('*' = any method). */
    public function when(string $method, string $urlPattern, HttpResponse|HttpTransportException $answer): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $urlPattern, 'answer' => $answer];

        return $this;
    }

    public function send(HttpRequest $request, ?HttpCallOptions $options = null): HttpResponse
    {
        $options ??= new HttpCallOptions();
        $this->sent[] = ['request' => $request, 'options' => $options];

        $answer = $this->route($request) ?? array_shift($this->queue)
            ?? throw new \LogicException(sprintf(
                'FakeHttpClient has no answer for %s %s: queue() one or add a when() route.',
                $request->method,
                HttpTransportException::safeUrl($request->url),
            ));

        if ($answer instanceof HttpTransportException) {
            throw $answer;
        }
        if ($options->onChunk !== null && $answer->isSuccessful()) {
            // As the real client: a successful stream goes to the callback and
            // the response comes back without a body.
            if ($answer->body !== '') {
                ($options->onChunk)($answer->body);
            }

            return new HttpResponse($answer->status, '', $answer->headers, $answer->rawHeaders, $answer->attempts, $answer->durationMs);
        }

        return $answer;
    }

    /** @return list<HttpRequest> */
    public function recorded(): array
    {
        return array_map(static fn (array $sent): HttpRequest => $sent['request'], $this->sent);
    }

    /** @return list<HttpCallOptions> */
    public function recordedOptions(): array
    {
        return array_map(static fn (array $sent): HttpCallOptions => $sent['options'], $this->sent);
    }

    /** @param \Closure(HttpRequest): bool $matches */
    public function assertSent(\Closure $matches): void
    {
        foreach ($this->recorded() as $request) {
            if ($matches($request)) {
                return;
            }
        }

        throw new \LogicException(sprintf(
            'No request matched; sent: %s',
            implode(', ', array_map(static fn (HttpRequest $r): string => $r->method . ' ' . HttpTransportException::safeUrl($r->url), $this->recorded())) ?: 'nothing',
        ));
    }

    public function assertSentCount(int $expected): void
    {
        if (count($this->sent) !== $expected) {
            throw new \LogicException(sprintf('Expected %d requests, %d were sent.', $expected, count($this->sent)));
        }
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->routes = [];
        $this->sent = [];
    }

    private function route(HttpRequest $request): HttpResponse|HttpTransportException|null
    {
        foreach ($this->routes as $route) {
            if (($route['method'] === '*' || $route['method'] === $request->method) && preg_match($route['pattern'], $request->url) === 1) {
                return $route['answer'];
            }
        }

        return null;
    }
}
