<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Exception\AccessDeniedException;

final class InvalidSignatureException extends AccessDeniedException
{
    public function __construct(public readonly SignatureCheck $check)
    {
        parent::__construct($check === SignatureCheck::Expired ? 'This link has expired.' : 'This link is not valid.');
    }
}
