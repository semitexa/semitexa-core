<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * An exception whose error response needs headers of its own: Retry-After on a
 * 429 or a 503. The exception mapper adds them to the response it renders, in
 * every format.
 */
interface CarriesResponseHeadersInterface
{
    /** @return array<string, string> header name => value */
    public function headers(): array;
}
