<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Exception\HttpTransportException;

/** The message is logged: a failed call to a Telegram bot or a Slack webhook logged its token. */
final class HttpTransportExceptionUrlTest extends TestCase
{
    #[Test]
    public function a_credential_in_the_path_is_masked_and_the_rest_kept(): void
    {
        self::assertSame('https://api.telegram.org/…/sendMessage', HttpTransportException::safeUrl('https://api.telegram.org/bot123456:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage?chat_id=1'));
        self::assertSame('https://hooks.slack.com/services/T00000000/B00000000/…', HttpTransportException::safeUrl('https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXX1XXXXXXXXXXXX'));
        self::assertSame('https://api.example.com/v1/rates', HttpTransportException::safeUrl('https://user:pw@api.example.com/v1/rates?key=secret'));
    }
}
