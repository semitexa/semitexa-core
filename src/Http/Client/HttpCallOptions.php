<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/** How one call is made. The defaults are safe for a call nobody tuned. */
final readonly class HttpCallOptions
{
    public function __construct(
        /** The whole attempt, in seconds. */
        public float $timeout = 30.0,
        public float $connectTimeout = 5.0,
        /** A larger body fails the call (HttpTransportKind::TooLarge) instead of filling memory. */
        public int $maxResponseBytes = 16 * 1024 * 1024,
        /** Off by default: a redirect to another host would carry the request's credentials there. */
        public bool $followRedirects = false,
        /** Ignore HTTP(S)_PROXY from the environment. */
        public bool $noProxy = false,
        /**
         * curl RESOLVE entries (`host:port:ip`): connect to the address already
         * checked, so a DNS answer cannot change between the check and the call.
         *
         * @var list<string>
         */
        public array $pinnedResolve = [],
        /** Only http and https may be spoken, also on redirects. */
        public bool $httpOnly = true,
        public RetryPolicy $retry = new RetryPolicy(),
        /**
         * Receives the body as it arrives (a stream); the response body is then empty.
         *
         * @var (\Closure(string): void)|null
         */
        public ?\Closure $onChunk = null,
    ) {}
}
