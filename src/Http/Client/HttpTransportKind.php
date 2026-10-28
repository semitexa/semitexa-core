<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/** Why no response came back. */
enum HttpTransportKind: string
{
    case Connect = 'connect';
    case Dns = 'dns';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case TooLarge = 'too_large';
    case Protocol = 'protocol';

    /** Worth sending again: the next attempt can succeed where this one failed. */
    public function isRetryable(): bool
    {
        return $this !== self::TooLarge && $this !== self::Tls && $this !== self::Protocol;
    }
}
