<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

enum SignatureCheck: string
{
    case Valid = 'valid';
    /** Signed by this application, but its time is up. */
    case Expired = 'expired';
    /** No signature, a changed URL, or another application's signature. */
    case Invalid = 'invalid';
}
