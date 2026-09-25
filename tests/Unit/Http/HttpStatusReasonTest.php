<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\HttpStatus;

/**
 * reason() is a match with no default arm: HttpStatus::MultipleChoices (300)
 * was declared as a case but missing from the match, so calling reason() on
 * it threw \UnhandledMatchError instead of returning a phrase.
 */
final class HttpStatusReasonTest extends TestCase
{
    #[Test]
    public function every_case_has_a_reason_phrase(): void
    {
        foreach (HttpStatus::cases() as $status) {
            self::assertNotSame('', $status->reason(), $status->name . ' has no reason phrase');
        }
    }

    #[Test]
    public function multiple_choices_reads_multiple_choices(): void
    {
        self::assertSame('Multiple Choices', HttpStatus::MultipleChoices->reason());
    }
}
