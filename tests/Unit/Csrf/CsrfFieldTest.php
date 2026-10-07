<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Csrf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * tk-la-csrf-field-helper: `{{ csrf_field() }}` gives a plain HTML form the
 * session's token, so no handler has to read it and thread it down.
 */
final class CsrfFieldTest extends TestCase
{
    private const KEY = 'core.csrf_session';

    /** The binding a previous test left, put back afterwards: CLI keeps one process-wide store. */
    private ?SessionInterface $previous = null;

    protected function setUp(): void
    {
        $previous = CoroutineLocal::get(self::KEY);
        $this->previous = $previous instanceof SessionInterface ? $previous : null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            CsrfField::clear();

            return;
        }
        CsrfField::bind($this->previous);
    }

    #[Test]
    public function outside_a_request_there_is_no_field(): void
    {
        CsrfField::clear();
        self::assertSame('', CsrfField::token());
        self::assertSame('', CsrfField::hiddenInput());
    }

    #[Test]
    public function the_field_carries_the_sessions_token_escaped(): void
    {
        $token = new CsrfToken();
        $token->setValue('abc"<x>');
        CsrfField::bind($this->session($token));

        self::assertSame('<input type="hidden" name="_csrf" value="abc&quot;&lt;x&gt;">', CsrfField::hiddenInput());
    }

    #[Test]
    public function a_token_rotated_mid_request_is_the_one_rendered(): void
    {
        $token = CsrfToken::generate();
        CsrfField::bind($this->session($token));
        $before = CsrfField::token();

        $token->setValue('rotated-at-sign-in'); // what regenerate() does to the same session
        self::assertNotSame($before, CsrfField::token());
        self::assertSame('rotated-at-sign-in', CsrfField::token());
    }

    private function session(CsrfToken $token): SessionInterface
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getPayload')->willReturnCallback(static fn (string $class): object => $class === CsrfToken::class ? $token : new \stdClass());

        return $session;
    }
}
