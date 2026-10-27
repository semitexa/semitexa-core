<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\LintTemplatesCommand;

/** Escaping follows each template's extension: a .txt.twig inside a page printed its variables raw into HTML. */
final class LintTemplatesMixedEscapingTest extends TestCase
{
    #[Test]
    public function an_html_template_including_one_escaped_otherwise_is_reported(): void
    {
        $dir = sys_get_temp_dir() . '/lint-escaping-' . bin2hex(random_bytes(4));
        mkdir($dir . '/pages', 0775, true);
        file_put_contents($dir . '/pages/home.html.twig', "{% include '@x/partials/note.txt.twig' %}\n{{ include(\"@x/partials/card.html.twig\") }}\n{% embed 'data.json.twig' %}{% endembed %}");
        file_put_contents($dir . '/pages/mail.txt.twig', "{% include '@x/partials/note.txt.twig' %}");
        try {
            $found = LintTemplatesCommand::mixedEscapingIncludes($dir);
        } finally {
            array_map('unlink', glob($dir . '/pages/*') ?: []);
            rmdir($dir . '/pages');
            rmdir($dir);
        }

        $pairs = array_map(static fn (array $f): string => basename($f[0]) . ' -> ' . $f[1], $found);
        sort($pairs);
        self::assertSame(['home.html.twig -> @x/partials/note.txt.twig', 'home.html.twig -> data.json.twig'], $pairs, 'a text template including text is fine, an HTML one including HTML too');
    }
}
