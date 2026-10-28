<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

use Semitexa\Core\Http\Exception\HttpTransportException;

/**
 * The framework's outbound HTTP. One client for every package, so timeouts,
 * retries and what reaches a log are decided once, and a test swaps in
 * FakeHttpClient to see what would have been sent.
 */
interface HttpClientInterface
{
    /**
     * A non-2xx status is returned, never thrown.
     *
     * @throws HttpTransportException when no response came back
     */
    public function send(HttpRequest $request, ?HttpCallOptions $options = null): HttpResponse;

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = [], ?HttpCallOptions $options = null): HttpResponse;

    /**
     * @param array<mixed>|\JsonSerializable $body
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array|\JsonSerializable $body, array $headers = [], ?HttpCallOptions $options = null): HttpResponse;

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function postForm(string $url, array $fields, array $headers = [], ?HttpCallOptions $options = null): HttpResponse;
}
