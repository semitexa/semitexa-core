<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Security\DecryptionException;
use Semitexa\Core\Security\SodiumEncrypter;

final class SodiumEncrypterTest extends TestCase
{
    #[Test]
    public function what_is_encrypted_reads_back_and_never_looks_the_same_twice(): void
    {
        $encrypter = SodiumEncrypter::withSecrets('secret-a');

        $one = $encrypter->encrypt('card ending 4242');
        $two = $encrypter->encrypt('card ending 4242');

        self::assertNotSame($one, $two);
        self::assertStringNotContainsString('4242', $one);
        self::assertMatchesRegularExpression('/^se1\.[A-Za-z0-9_-]+$/', $one, 'safe in a URL or a cookie');
        self::assertSame('card ending 4242', $encrypter->decrypt($one));
        self::assertSame('', $encrypter->decrypt($encrypter->encrypt('')));
    }

    #[Test]
    public function a_changed_token_is_refused(): void
    {
        $encrypter = SodiumEncrypter::withSecrets('secret-a');
        $token = $encrypter->encrypt('user=42');
        $last = substr($token, -1);
        $changed = substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $this->expectException(DecryptionException::class);
        $encrypter->decrypt($changed);
    }

    #[Test]
    public function another_secret_cannot_read_it_and_a_previous_one_still_can(): void
    {
        $token = SodiumEncrypter::withSecrets('old')->encrypt('user=42');

        self::assertSame('user=42', SodiumEncrypter::withSecrets('new', ['old'])->decrypt($token));

        $this->expectException(DecryptionException::class);
        SodiumEncrypter::withSecrets('new')->decrypt($token);
    }

    #[Test]
    public function something_that_is_not_a_token_is_refused(): void
    {
        $encrypter = SodiumEncrypter::withSecrets('secret-a');

        foreach (['', 'user=42', 'se1.', 'se1.!!', 'se1.' . str_repeat('A', 10)] as $notAToken) {
            try {
                $encrypter->decrypt($notAToken);
                self::fail("'{$notAToken}' was accepted");
            } catch (DecryptionException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
