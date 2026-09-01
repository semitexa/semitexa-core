<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Raised when a discovery scan gives up because its worker is shutting down.
 *
 * Not an error condition: the worker was asked to exit, and the scan stood down so it
 * could. It is an exception rather than an early return because that is the only way to
 * reach {@see ClassDiscovery::runOncePerKey()}'s failure path, which drops the production
 * gate and wakes every waiter to retry. Returning normally would memoise a HALF-SCANNED
 * classmap as the process-wide answer, and a partial registry outlives the request that
 * built it — the failure mode is silent and permanent, which is much worse than one
 * in-flight request failing on a worker that is going away anyway.
 */
final class DiscoveryInterruptedException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $scanned = 0)
    {
        parent::__construct($message);
    }

    /** How far the scan got before standing down — the useful half of the diagnostic. */
    public function scanned(): int
    {
        return $this->scanned;
    }
}
