<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

/** Whose requests a limit counts together. */
enum RateLimitKey: string
{
    /** The client address (ClientIp); an IPv6 address counts with its whole /64. */
    case Ip = 'ip';

    /** The signed-in user; a guest is counted by address, as Ip. */
    case User = 'user';
}
