<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\LintTemplatesCommand;
use Semitexa\Core\Csrf\CsrfField;

/**
 * A POST form without a CSRF token is an error of lint:templates
 * (d-20261125-guest-sessions-option-c).
 *
 * Such a form works for a new guest, who has no session yet and is not
 * checked, and answers 403 for every visitor who has one. While every
 * response minted a session the page caught it by itself; since a guest gets
 * a session only once a request uses it
 * (idea-anonymous-error-responses-mint-session-cookies), the lint does.
 */
final class LintTemplatesPostFormCsrfTest extends TestCase
{
    /** @return iterable<string, array{string, list<int>}> template, the lines of the forms reported */
    public static function templates(): iterable
    {
        yield 'a POST form without the field' => [
            "<h1>Sign in</h1>\n<form method=\"post\" action=\"/login\">\n  <input name=\"email\">\n</form>",
            [2],
        ];
        yield 'with csrf_field()' => [
            "<form method=\"post\" action=\"/login\">\n  {{ csrf_field() }}\n  <input name=\"email\">\n</form>",
            [],
        ];
        yield 'with csrf_field() and whitespace control' => [
            "<form method=\"post\">{{- csrf_field( ) -}}</form>",
            [],
        ];
        yield 'with a hidden input of the field name, written by hand' => [
            "<form method=\"post\"><input type=\"hidden\" name=\"_csrf\" value=\"{{ csrf_token() }}\"></form>\n"
            . "<form method=\"post\"><input type='hidden' name='_csrf' value='{{ token }}'></form>",
            [],
        ];
        yield 'an input of another name is not the token' => [
            "<form method=\"post\"><input type=\"hidden\" name=\"_csrf_old\" value=\"x\"><input data-name=\"_csrf\"></form>",
            [1],
        ];
        yield 'csrf_token() alone, in an attribute a script reads, is not submitted' => [
            "<form method=\"post\" data-token=\"{{ csrf_token() }}\"><button>Go</button></form>",
            [1],
        ];
        yield 'a GET form and a form without a method' => [
            "<form method=\"get\" action=\"/search\"><input name=\"q\"></form>\n<form action=\"/search\"><input name=\"q\"></form>\n<form method=\"dialog\"></form>",
            [],
        ];
        yield 'uppercase METHOD and value' => [
            "<FORM ACTION=\"/a\" METHOD=\"POST\">\n</FORM>\n<form METHOD=\"Post\">{{ csrf_field() }}</form>",
            [1],
        ];
        yield 'single quotes, no quotes, spaces around the equals sign' => [
            "<form method='post'></form>\n<form method=post></form>\n<form method = \"post\" ></form>\n<form method=' POST '></form>",
            [1, 2, 3, 4],
        ];
        yield 'the method after other attributes, on its own line' => [
            "<form\n  class=\"card\"\n  action=\"/orders\"\n  method=\"post\"\n>\n</form>",
            [1],
        ];
        yield 'data-method and formmethod-like names are not the method' => [
            "<form data-method=\"post\" action=\"/a\"></form>",
            [],
        ];
        yield 'the field inside a nested Twig block' => [
            "<form method=\"post\">\n{% block fields %}\n  {% if user %}\n    {{ csrf_field() }}\n  {% endif %}\n{% endblock %}\n</form>",
            [],
        ];
        yield 'a form inside a Twig block, without the field' => [
            "{% block body %}\n  {% for item in items %}\n    <form method=\"post\" action=\"/items/{{ item.id }}/delete\"><button>Delete</button></form>\n  {% endfor %}\n{% endblock %}",
            [3],
        ];
        yield 'a Twig comparison inside the tag does not end it' => [
            "<form {% if items|length > 3 %}class=\"wide\"{% endif %} method=\"post\">{{ csrf_field() }}</form>\n"
            . "<form {% if items|length > 3 %}class=\"wide\"{% endif %} method=\"post\"></form>",
            [2],
        ];
        yield 'a method written as a Twig expression can be POST' => [
            "<form method=\"{{ form.method|default('POST') }}\" action=\"{{ form.action }}\"></form>\n"
            . "<form method=\"{{ form.method|default('POST') }}\">{% if form.method != 'GET' %}{{ csrf_field() }}{% endif %}</form>",
            [1],
        ];
        yield 'the form of another site must not carry the token' => [
            "<form method=\"post\" action=\"https://pay.example.com/checkout\"></form>\n"
            . "<form method=\"post\" action=\"//pay.example.com/checkout\"></form>\n"
            . "<form method=\"post\" action=\"HTTP://pay.example.com/\"></form>",
            [],
        ];
        yield 'a path that only looks like a host is this site' => [
            "<form method=\"post\" action=\"/https://x\"></form>\n<form method=\"post\" action=\"{{ url }}\"></form>",
            [1, 2],
        ];
        yield 'the field of one form does not cover the next' => [
            "<form method=\"post\">{{ csrf_field() }}</form>\n<form method=\"post\"></form>\n{{ csrf_field() }}",
            [2],
        ];
        yield 'a form that is never closed ends at the next one' => [
            "<form method=\"post\">\n<form method=\"post\">{{ csrf_field() }}</form>",
            [1],
        ];
        yield 'a form in a comment or in verbatim is text' => [
            "{# <form method=\"post\"></form> #}\n<!-- <form method=\"post\"></form> -->\n"
            . "{% verbatim %}\n<form method=\"post\" action=\"/orders\"></form>\n{% endverbatim %}\n"
            . "{%- raw -%}<form method=\"post\"></form>{%- endraw -%}",
            [],
        ];
        yield 'a field in a comment is none' => [
            "<form method=\"post\">\n{# {{ csrf_field() }} #}\n<!-- {{ csrf_field() }} -->\n</form>",
            [1],
        ];
        yield 'a comment delimiter inside a Twig string opens no comment' => [
            "{{ '{#' }}\n<form method=\"post\"></form>\n{{ '#}' }}",
            [2],
        ];
    }

    /** @param list<int> $lines */
    #[Test]
    #[DataProvider('templates')]
    public function a_post_form_must_present_the_token(string $template, array $lines): void
    {
        $found = LintTemplatesCommand::tokenlessPostForms($template);

        self::assertIsArray($found);
        self::assertSame($lines, array_column($found, 0));
    }

    #[Test]
    public function a_method_that_is_an_expression_is_said_to_be_one(): void
    {
        self::assertSame(
            [[1, true], [2, false]],
            LintTemplatesCommand::tokenlessPostForms("<form method=\"{{ m }}\"></form>\n<form method=\"post\"></form>"),
        );
    }

    /** The hand-written input is accepted under the name the listener reads, whatever that is. */
    #[Test]
    public function the_rule_names_what_it_accepts(): void
    {
        self::assertSame('_csrf', CsrfField::NAME);
        foreach (['{{ csrf_field() }}', '"_csrf"', '{{ csrf_token() }}', 'X-CSRF-Token', 'GET form', 'without a method', 'another site', '{% verbatim %}'] as $said) {
            self::assertStringContainsString($said, LintTemplatesCommand::CSRF_RULE);
        }
    }

    /** Fixture templates on disk, as the command scans them: HTML templates only, with file and line. */
    #[Test]
    public function the_templates_of_a_directory_are_scanned(): void
    {
        $dir = sys_get_temp_dir() . '/lint-post-form-' . bin2hex(random_bytes(4));
        mkdir($dir . '/pages', 0775, true);
        $files = [
            '/pages/login.html.twig' => "<main>\n  <form method=\"post\" action=\"/login\">\n    <input name=\"email\">\n  </form>\n</main>",
            '/pages/register.html.twig' => "<form method=\"post\" action=\"/register\">\n  {{ csrf_field() }}\n</form>",
            '/pages/search.html.twig' => "<form method=\"get\" action=\"/search\"><input name=\"q\"></form>",
            '/pages/pay.html.twig' => "<form METHOD='POST' action=\"https://pay.example.com/checkout\"></form>",
            '/pages/mail.txt.twig' => "To reply, send <form method=\"post\"> back to us.",
            '/pages/notes.md' => "<form method=\"post\"></form>",
        ];
        foreach ($files as $name => $content) {
            file_put_contents($dir . $name, $content);
        }
        try {
            $unreadable = [];
            $unanalysed = [];
            $found = LintTemplatesCommand::postFormsWithoutCsrfToken($dir, $unreadable, $unanalysed);
        } finally {
            array_map('unlink', glob($dir . '/pages/*') ?: []);
            rmdir($dir . '/pages');
            rmdir($dir);
        }

        self::assertSame([[$dir . '/pages/login.html.twig', 2, false]], $found);
        self::assertSame([], $unreadable);
        self::assertSame([], $unanalysed);
    }
}
