<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tests\Support\PhpSource;

final class PhpSourceTest extends TestCase
{
    #[Test]
    public function comments_go_and_the_code_stays(): void
    {
        self::assertSame(
            "<?php\n\n\$a = 1; \nfoo();\n",
            PhpSource::withoutComments("<?php\n/** doc */\n\$a = 1; // bar();\n/* baz(); */foo();\n"),
        );
    }

    /** review: an unreadable file, cast to '', passed every forbidden-call check unread. */
    #[Test]
    public function an_empty_source_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('An empty source cannot be cleared: the file was unreadable or empty.');
        PhpSource::withoutComments((string) @file_get_contents('/nowhere/at/all.php'));
    }
}
