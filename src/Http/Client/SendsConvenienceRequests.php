<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/** get()/postJson()/postForm() over send(), shared by every HttpClientInterface. */
trait SendsConvenienceRequests
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = [], ?HttpCallOptions $options = null): HttpResponse
    {
        return $this->send(HttpRequest::make('GET', $url)->withHeaders($headers), $options);
    }

    /**
     * @param array<mixed>|\JsonSerializable $body
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array|\JsonSerializable $body, array $headers = [], ?HttpCallOptions $options = null): HttpResponse
    {
        $request = HttpRequest::make('POST', $url)
            // The defaults first, then the caller's, which replace them in any
            // case: a `+` kept the default over a caller's 'Accept'.
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->withHeaders($headers)
            ->withBody(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->send($request, $options);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function postForm(string $url, array $fields, array $headers = [], ?HttpCallOptions $options = null): HttpResponse
    {
        $request = HttpRequest::make('POST', $url)
            ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withHeaders($headers)
            ->withBody(http_build_query($fields, '', '&', PHP_QUERY_RFC3986));

        return $this->send($request, $options);
    }
}
