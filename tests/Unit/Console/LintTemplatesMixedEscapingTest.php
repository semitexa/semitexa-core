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

    /** Twig renders the first name of a list that exists, so a text template in it reached the page unseen. */
    #[Test]
    public function a_name_inside_an_include_list_is_reported(): void
    {
        $dir = sys_get_temp_dir() . '/lint-escaping-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/home.html.twig', "{% include ['partials/note.txt.twig', 'partials/card.html.twig'] %}\n{{ include([\"a.html.twig\", \"b.js.twig\"]) }}\n{# {% include 'commented.txt.twig' %} #}\n{#\n{{ include('also.txt.twig') }}\n#}");
        try {
            $found = LintTemplatesCommand::mixedEscapingIncludes($dir);
        } finally {
            unlink($dir . '/home.html.twig');
            rmdir($dir);
        }

        self::assertSame(['partials/note.txt.twig', 'b.js.twig'], array_column($found, 1), 'an include inside a comment is not one');
    }

    /** Comment delimiters printed as strings opened no comment, but the lint stripped the include between them. */
    #[Test]
    public function a_comment_delimiter_inside_a_string_hides_no_include(): void
    {
        $dir = sys_get_temp_dir() . '/lint-escaping-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/home.html.twig', "{{ '{#' }}\n{% include 'partials/note.txt.twig' %}\n{{ \"#}\" }}\n{% set s = 'a %} b' %}{{ include('c.js.twig') }}\n{# {{ '#}' }} #}");
        try {
            $found = LintTemplatesCommand::mixedEscapingIncludes($dir);
        } finally {
            unlink($dir . '/home.html.twig');
            rmdir($dir);
        }

        self::assertSame(['partials/note.txt.twig', 'c.js.twig'], array_column($found, 1));
    }

    /** A file it could not read came back as an empty template, and --strict reported it clean. */
    #[Test]
    public function an_html_template_that_cannot_be_read_is_reported_unread(): void
    {
        $module = sys_get_temp_dir() . '/lint-escaping-' . bin2hex(random_bytes(4));
        $dir = $module . '/Application/View/templates';
        mkdir($dir, 0775, true);
        // A dangling link: listed by the directory, unreadable even to root.
        symlink($dir . '/gone.txt', $dir . '/broken.html.twig');
        $unreadable = [];
        $errors = [];
        $warnings = [];
        try {
            $found = LintTemplatesCommand::mixedEscapingIncludes($dir, $unreadable);
            (function () use ($module, &$errors, &$warnings): void {
                $this->scanModuleDir('Probe', $module, $errors, $warnings);
            })->call(new LintTemplatesCommand());
        } finally {
            unlink($dir . '/broken.html.twig');
            exec('rm -rf ' . escapeshellarg($module));
        }

        self::assertSame([], $found);
        self::assertSame([$dir . '/broken.html.twig'], $unreadable);
        self::assertSame([['module' => 'Probe', 'path' => $dir . '/broken.html.twig', 'message' => 'Cannot be read, so what it includes was not checked. Make it readable.']], $errors, 'an error fails the lint without --strict');
        self::assertSame([], $warnings);
    }
}
