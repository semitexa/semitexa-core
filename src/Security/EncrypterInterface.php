<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

/**
 * Encrypts a string so only this application can read it back, and tells when
 * one was changed. For a value that leaves the server and must come back
 * unread and untouched: a cookie, a hidden field, a token in a link.
 */
interface EncrypterInterface
{
    /**
     * A URL-safe token: encrypting the same text twice gives two different tokens.
     *
     * $context names what the value is for ('cookie:cart', 'link:invite'): a
     * token decrypts only under the context it was made with, so one handed
     * out for one purpose cannot be passed off as another's.
     */
    public function encrypt(string $plaintext, string $context = ''): string;

    /** @throws DecryptionException when the token was changed, made with another secret or another context, or is not a token at all */
    public function decrypt(string $token, string $context = ''): string;
}
