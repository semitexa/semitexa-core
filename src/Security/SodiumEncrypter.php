<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * XSalsa20-Poly1305 (libsodium secretbox) under a key derived from APP_SECRET.
 *
 * A token is `se1.` + base64url(nonce ‖ ciphertext). Decryption tries the
 * current secret, then each of APP_SECRET_PREVIOUS, so a rotation does not
 * make yesterday's cookies unreadable.
 */
#[SatisfiesServiceContract(of: EncrypterInterface::class)]
final class SodiumEncrypter implements EncrypterInterface
{
    private const PREFIX = 'se1.';
    private const PURPOSE = 'encrypt';

    /** @var non-empty-list<string>|null secrets for a hand-built instance; null reads the environment */
    private ?array $secrets = null;

    /** @param list<string> $previous */
    public static function withSecrets(string $current, array $previous = []): self
    {
        $encrypter = new self();
        $encrypter->secrets = [$current, ...$previous];

        return $encrypter;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, AppSecret::derive($this->secrets()[0], self::PURPOSE));

        return self::PREFIX . self::base64UrlEncode($nonce . $cipher);
    }

    public function decrypt(string $token): string
    {
        if (!str_starts_with($token, self::PREFIX)) {
            throw new DecryptionException('Not an encrypted value.');
        }

        $raw = self::base64UrlDecode(substr($token, strlen(self::PREFIX)));
        if ($raw === null || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new DecryptionException('Not an encrypted value.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        foreach ($this->secrets() as $secret) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, AppSecret::derive($secret, self::PURPOSE));
            if ($plain !== false) {
                return $plain;
            }
        }

        throw new DecryptionException('The value was changed, or encrypted with another secret.');
    }

    /** @return non-empty-list<string> */
    private function secrets(): array
    {
        return $this->secrets ?? [AppSecret::current(), ...AppSecret::previous()];
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
