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
        yield 'only an input is submitted: a meta, a button or a select of that name is not the token' => [
            "<form method=\"post\"><meta name=\"_csrf\" content=\"{{ csrf_token() }}\"></form>\n"
            . "<form method=\"post\"><button name=\"_csrf\" value=\"x\">Go</button></form>\n"
            . "<form method=\"post\"><select name=\"_csrf\"></select></form>\n"
            . "<form method=\"post\"><INPUT value=\"{{ csrf_token() }}\" NAME=\"_csrf\" type=\"hidden\"></form>",
            [1, 2, 3],
        ];
        yield 'a button with formmethod="post" makes a GET form a POST form' => [
            "<form method=\"get\" action=\"/items\"><button formmethod=\"post\" formaction=\"/items/delete\">Delete</button></form>\n"
            . "<form action=\"/items\"><input type=\"submit\" FORMMETHOD='POST' value=\"Delete\"></form>\n"
            . "<form action=\"/items\"><button formmethod=\"{{ m }}\">Go</button></form>\n"
            . "<form method=\"get\"><button formmethod=\"post\">Delete</button>{{ csrf_field() }}</form>\n"
            . "<form method=\"get\"><button formmethod=\"get\">Search</button><button data-formmethod=\"post\">x</button></form>",
            [1, 2, 3],
        ];
        yield 'a formmethod button that posts to another site carries no token; one that posts here does' => [
            "<form action=\"/items\"><button formmethod=\"post\" formaction=\"https://pay.example.com/\">Pay</button></form>\n"
            . "<form action=\"https://pay.example.com/\" method=\"post\"><button formmethod=\"post\" formaction=\"/items/delete\">Delete</button></form>",
            [2],
        ];
        yield 'a submit button with its own formaction posts there with the method of the form' => [
            "<form method=\"post\" action=\"https://pay.example.com/\"><button formaction=\"/orders/delete\">Delete</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><button type=\"submit\" formaction=\"/orders/delete\">Delete</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><input type=\"submit\" formaction=\"/orders/delete\"></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><input type=\"IMAGE\" formaction=\"/orders/delete\"></form>\n"
            . "<form method=\"{{ m }}\" action=\"https://pay.example.com/\"><button formaction=\"/orders/delete\">Delete</button></form>",
            [1, 2, 3, 4, 5],
        ];
        yield 'a control that does not submit, or that overrides the method with GET, posts nothing here' => [
            "<form method=\"post\" action=\"https://pay.example.com/\"><button type=\"button\" formaction=\"/orders/delete\">x</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><button type=\"reset\" formaction=\"/orders/delete\">x</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><input type=\"text\" formaction=\"/orders/delete\"></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><input formaction=\"/orders/delete\" formmethod=\"post\"></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><button formmethod=\"get\" formaction=\"/orders\">Search</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><button formaction=\"//pay.example.com/refund\">Refund</button></form>\n"
            . "<form method=\"get\" action=\"/orders\"><button formaction=\"/orders/archive\">Archive</button></form>",
            [],
        ];
        yield 'csrf_field() in the text of the page, in an attribute or in a Twig string is not the field' => [
            "<form method=\"post\"><p>Use csrf_field() here</p></form>\n"
            . "<form method=\"post\"><input placeholder=\"csrf_field()\"></form>\n"
            . "<form method=\"post\">{{ 'csrf_field()' }}</form>\n"
            . "<form method=\"post\">{% if hint %}csrf_field(){% endif %}</form>",
            [1, 2, 3, 4],
        ];
        yield 'csrf_field() in a Twig output expression, with whitespace control or inside a longer one' => [
            "<form method=\"post\">{{ csrf_field() }}</form>\n"
            . "<form method=\"post\">{{- csrf_field() -}}</form>\n"
            . "<form method=\"post\">{{~ csrf_field() ~}}</form>\n"
            . "<form method=\"post\">{{ csrf_field()|raw }}</form>\n"
            . "<form method=\"post\">{{ '}}' ~ csrf_field() }}</form>\n"
            . "<form method=\"post\"><p>Use csrf_field() here</p><input type=\"hidden\" name=\"_csrf\" value=\"{{ csrf_token() }}\"></form>",
            [],
        ];
        yield 'an attribute is not read out of the quoted value of another' => [
            "<form data-example=\"method='get'\" method=\"post\"></form>\n"
            . "<form data-example=\"method='post'\" method=\"get\"></form>\n"
            . "<form data-example='action=\"https://pay.example.com/\"' method=\"post\" action=\"/orders\"></form>\n"
            . "<form method=\"post\"><input data-example=\"name='_csrf'\" name=\"email\"></form>\n"
            . "<form method=\"post\"><input data-example=\"name='email'\" name=\"_csrf\"></form>\n"
            . "<form method=\"get\"><button title=\"formmethod='post'\">Go</button></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><button title=\"type='button'\" formaction=\"/orders/delete\">Go</button></form>",
            [1, 3, 4, 7],
        ];
        yield 'a quoted value keeps the Twig inside it whole, quotes and all' => [
            "<form action=\"{{ path(\"method\") }}\" method=\"post\"></form>\n"
            . "<form method=\"post\"><input value=\"{{ old(\"name\") }}\" name=\"_csrf\"></form>\n"
            . "<form action='{{ path('method') }}' method='post'></form>\n"
            . "<form class=\"{% if a > b %}wide{% endif %}\" action=\"{{ path(\"x\") }}\" method=\"post\"><input value=\"{{ old(\"name\") }}\" name=\"email\"></form>",
            [1, 3, 4],
        ];
        yield 'a control whose type is a Twig expression can be a submit control' => [
            "<form method=\"get\"><input type=\"{{ t }}\" formmethod=\"post\"></form>\n"
            . "<form method=\"post\" action=\"https://pay.example.com/\"><input type=\"{{ t }}\" formaction=\"/orders/delete\"></form>",
            [1, 2],
        ];
        yield 'csrf_field() in a Twig tag, printed later, is the field; in a Twig comment it is not' => [
            "<form method=\"post\">{% set f = csrf_field() %}{{ f }}</form>\n"
            . "<form method=\"post\">{# {% set f = csrf_field() %} #}</form>\n"
            . "<form method=\"post\">{% set f = 'csrf_field()' %}{{ f }}</form>",
            [2, 3],
        ];
        yield 'of a repeated attribute the first counts, as in the browser' => [
            "<form method=\"post\" method=\"get\"></form>\n"
            . "<form method=\"get\" method=\"post\"></form>\n"
            . "<form method=\"post\"><input name=\"email\" name=\"_csrf\"></form>",
            [1, 3],
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
            // verify:accept-test-change each row gained a third value, whether the form posts to an absolute URL too (review semitexa-core#272); the first two are as they were
            [[1, true, false], [2, false, false], [3, false, false]],
            LintTemplatesCommand::tokenlessPostForms(
                "<form method=\"{{ m }}\"></form>\n<form method=\"post\"></form>\n<form method=\"{{ m }}\"><button formmethod=\"post\">Go</button></form>",
            ),
        );
    }

    /** A token placed in such a form would go to the other site with the submit that posts there. */
    #[Test]
    public function a_form_that_posts_here_and_to_another_site_is_told_to_split(): void
    {
        self::assertSame(
            [[1, false, true], [2, false, true], [3, false, false]],
            LintTemplatesCommand::tokenlessPostForms(
                "<form method=\"post\" action=\"https://pay.example.com/\"><button formaction=\"/orders/delete\">Delete</button></form>\n"
                . "<form method=\"post\" action=\"/orders\"><button formaction=\"https://pay.example.com/\">Pay</button></form>\n"
                . "<form method=\"post\" action=\"/orders\"><button formmethod=\"get\" formaction=\"https://pay.example.com/\">Look</button></form>",
            ),
        );
        self::assertSame(
            'This form also posts to an absolute URL, which is taken for another site: split it in two, a token placed in it would be sent to the other site as well. ',
            LintTemplatesCommand::CSRF_SPLIT,
        );
        self::assertSame(
            'POST form without a CSRF token, line 4. ' . LintTemplatesCommand::CSRF_SPLIT . LintTemplatesCommand::CSRF_RULE,
            LintTemplatesCommand::postFormMessage(4, false, true),
        );
        self::assertSame(
            'POST form without a CSRF token, line 4. ' . LintTemplatesCommand::CSRF_RULE,
            LintTemplatesCommand::postFormMessage(4, false, false),
        );
        self::assertSame(
            'POST form without a CSRF token, line 4. Its method is a Twig expression, which can be POST; if it can be GET too, print the field only for POST. ' . LintTemplatesCommand::CSRF_RULE,
            LintTemplatesCommand::postFormMessage(4, true, false),
        );
    }

    /** The lint has no origin to compare an action with, so the rule says what it takes for another site. */
    #[Test]
    public function the_rule_says_which_action_is_taken_for_another_site(): void
    {
        self::assertStringEndsWith(
            'Not checked: a GET form, a form without a method, a form that posts to an absolute URL written in the template (http://, https:// or //), '
            . 'and markup inside a comment or {% verbatim %}. '
            . 'A literal absolute URL is taken for another site, whose form must not carry the token: one template is rendered for every host that serves the app, so the lint has no origin to compare with. '
            . 'A form that posts to this app uses a path, or a Twig expression, which is checked.',
            LintTemplatesCommand::CSRF_RULE,
        );
    }

    /** The hand-written input is accepted under the name the listener reads, whatever that is. */
    #[Test]
    public function the_rule_names_what_it_accepts(): void
    {
        self::assertSame('_csrf', CsrfField::NAME);
        foreach (['{{ csrf_field() }}', 'directly inside the form', 'included template or a macro', '<input> named "_csrf"', 'formmethod="post"', '{{ csrf_token() }}', 'X-CSRF-Token', 'GET form', 'without a method', 'another site', '{% verbatim %}'] as $said) {
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

        // verify:accept-test-change the row gained a fourth value, whether the form posts to an absolute URL too (review semitexa-core#272)
        self::assertSame([[$dir . '/pages/login.html.twig', 2, false, false]], $found);
        self::assertSame([], $unreadable);
        self::assertSame([], $unanalysed);
    }
}
