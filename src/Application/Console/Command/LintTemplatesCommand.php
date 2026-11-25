<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates template directory structure across all modules.
 *
 * Errors (always blocking in --strict mode, blocks CI):
 *   - Legacy `themes/` directory inside Application/View/ (must migrate to templates/layouts/)
 *   - A POST form that carries no CSRF token ({@see postFormsWithoutCsrfToken()})
 *
 * Warnings (informational; blocking only with --strict):
 *   - Non-canonical subdirectory names inside templates/
 *
 * Canonical subdirectory names: pages/, layouts/, partials/, components/, deferred/
 */
#[AsCommand(
    name: 'lint:templates',
    description: 'Validate template directory conventions across all modules.',
)]
class LintTemplatesCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: templates resolve through canonical subdirectories (pages, layouts, partials, components, deferred); a legacy themes/ directory or an invented one is a layout nobody else looks for. Policy since 2026-04-30; no incident on record. A POST form without csrf_field() works for a new guest, who has no session yet and is not CSRF-checked, and answers 403 for every visitor who has one: since 2026-11-25 a guest gets a session only once a request uses it, so the page no longer catches this by itself (idea-anonymous-error-responses-mint-session-cookies).';

    /** What a POST form must hold, and what is not checked: one wording for the error and the tests. */
    public const CSRF_RULE = 'Put {{ csrf_field() }} directly inside the form, or an <input> named "' . CsrfField::NAME . '" holding {{ csrf_token() }}: '
        . 'a field printed by an included template or a macro is not seen here. '
        . 'Without it the form works for a new guest, who has no session yet and is not checked, and is refused with 403 for every visitor who has one. '
        . 'A form sent by script with the X-CSRF-Token header needs the field too: without the script it is posted as it stands. '
        . 'A button or input with formmethod="post" makes its form a POST form. '
        . 'Not checked: a GET form, a form without a method, a form whose action is an absolute URL of another site (it must not carry the token), '
        . 'and markup inside a comment or {% verbatim %}.';

    private const CANONICAL_SUBDIRS = ['pages', 'layouts', 'partials', 'components', 'deferred'];

    private const SUGGESTED_RENAMES = [
        'blocks'   => 'components',
        'block'    => 'partials',
        'frame'    => 'layouts',
        'frames'   => 'layouts',
        'views'    => 'pages',
        'page'     => 'pages',
        'layout'   => 'layouts',
        'partial'  => 'partials',
        'shared'   => 'partials',
        'includes' => 'partials',
        'sections' => 'partials',
        'slots'    => 'deferred',
    ];

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    protected function configure(): void
    {
        $this->setName('lint:templates')
            ->setDescription('Validate template directory conventions across all modules.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with failure code on warnings too')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict');
        $json   = (bool) $input->getOption('json');

        $errors   = [];
        $warnings = [];

        $this->scanLocalModules($errors, $warnings);
        $this->scanPackageModules($errors, $warnings);

        if ($json) {
            $this->outputJson($output, $errors, $warnings);
        } else {
            $this->outputTable($io, $errors, $warnings);
        }

        if (count($errors) > 0) {
            return Command::FAILURE;
        }

        if ($strict && count($warnings) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanLocalModules(array &$errors, array &$warnings): void
    {
        $modulesRoot = ProjectRoot::get() . '/src/modules';

        if (!is_dir($modulesRoot)) {
            return;
        }

        foreach (glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);
            // Local modules nest runtime PHP under `src/` (mirrors packages).
            $appDir = is_dir($moduleDir . '/src') ? $moduleDir . '/src' : $moduleDir;
            $this->scanModuleDir($module, $appDir, $errors, $warnings);
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanPackageModules(array &$errors, array &$warnings): void
    {
        $modules = $this->moduleRegistry->getModules();

        foreach ($modules as $module) {
            $moduleName = $module['name'];
            $modulePath = $module['path'];

            if ($module['type'] === 'local' || $moduleName === '' || $modulePath === '') {
                continue;
            }

            // Packages have src/ between module root and Application/
            $appDir = is_dir($modulePath . '/src') ? $modulePath . '/src' : $modulePath;
            $this->scanModuleDir($moduleName, $appDir, $errors, $warnings);
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanModuleDir(string $module, string $moduleDir, array &$errors, array &$warnings): void
    {
        $projectRoot = ProjectRoot::get();

        // Error: legacy themes/ inside Application/View/
        $legacyThemesDir = $moduleDir . '/Application/View/themes';
        if (is_dir($legacyThemesDir)) {
            $rel = $this->relativePath($legacyThemesDir, $projectRoot);
            $errors[] = [
                'module'  => $module,
                'path'    => $rel,
                'message' => 'Legacy themes/ directory found. Migrate to Application/View/templates/layouts/.',
            ];
        }

        // Check templates/ subdirectories for non-canonical names
        $templatesDir = $moduleDir . '/Application/View/templates';
        if (!is_dir($templatesDir)) {
            return;
        }

        $unreadable = [];
        $unanalysed = [];
        foreach (self::mixedEscapingIncludes($templatesDir, $unreadable, $unanalysed) as [$file, $included, $strategy]) {
            $warnings[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => "Includes '{$included}', which is escaped as {$strategy}: inside this HTML template its variables print unescaped by HTML rules. Render it apart, or give the HTML a template of its own.",
            ];
        }
        // An error, not a warning: what such a file includes went unchecked, and a lint
        // that skips a file must not report it clean, with or without --strict.
        foreach ($unreadable as $file) {
            $errors[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => 'Cannot be read, so what it includes was not checked. Make it readable.',
            ];
        }
        foreach ($unanalysed as $file) {
            $errors[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => 'Too large for the regex engine (pcre.backtrack_limit), so what it includes was not checked. Split it, or raise the limit.',
            ];
        }

        // Errors: the operator's decision of 2026-11-25 (d-20261125-guest-sessions-option-c).
        $formsUnreadable = [];
        $formsUnanalysed = [];
        foreach (self::postFormsWithoutCsrfToken($templatesDir, $formsUnreadable, $formsUnanalysed) as [$file, $line, $methodIsExpression]) {
            $errors[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => "POST form without a CSRF token, line {$line}. "
                    . ($methodIsExpression ? 'Its method is a Twig expression, which can be POST; if it can be GET too, print the field only for POST. ' : '')
                    . self::CSRF_RULE,
            ];
        }
        // A template the include check could not read or analyse is already an error above.
        foreach (array_diff($formsUnreadable, $unreadable) as $file) {
            $errors[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => 'Cannot be read, so its forms were not checked. Make it readable.',
            ];
        }
        foreach (array_diff($formsUnanalysed, $unanalysed) as $file) {
            $errors[] = [
                'module'  => $module,
                'path'    => $this->relativePath($file, $projectRoot),
                'message' => 'Too large for the regex engine (pcre.backtrack_limit), so its forms were not checked. Split it, or raise the limit.',
            ];
        }

        foreach (glob($templatesDir . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            $name = basename($subdir);
            if (in_array($name, self::CANONICAL_SUBDIRS, true)) {
                continue;
            }

            $rel       = $this->relativePath($subdir, $projectRoot);
            $suggested = self::SUGGESTED_RENAMES[$name] ?? null;
            $hint      = $suggested !== null
                ? " Did you mean templates/{$suggested}/?"
                : ' Canonical names: ' . implode('/, ', self::CANONICAL_SUBDIRS) . '/';

            $warnings[] = [
                'module'  => $module,
                'path'    => $rel,
                'message' => "Non-canonical subdirectory '{$name}'.{$hint}",
            ];
        }
    }

    /**
     * HTML templates that include, embed or import a template escaped by
     * another strategy: escaping follows each template's own extension
     * ('autoescape' => 'name'), so a .txt.twig inside a page prints its
     * variables raw into HTML. Literal names only, given alone or in a list
     * (`include ['a.txt.twig', 'b.html.twig']`, which renders the first that exists).
     *
     * @param list<string> $unreadable filled with the HTML templates that could not be read
     * @param list<string> $unanalysed filled with those the regex engine gave up on (backtrack limit)
     * @return list<array{string, string, string}> [file, included name, its strategy]
     */
    public static function mixedEscapingIncludes(string $dir, array &$unreadable = [], array &$unanalysed = []): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.twig') || self::escapingOf($path) !== 'html') {
                continue;
            }
            $source = @file_get_contents($path);
            if ($source === false) {
                $unreadable[] = $path;
                continue;
            }
            $names = self::includedNames($source);
            if ($names === null) {
                $unanalysed[] = $path;
                continue;
            }
            foreach ($names as $included) {
                $strategy = self::escapingOf($included);
                if ($strategy !== 'html') {
                    $found[] = [$path, $included, $strategy];
                }
            }
        }

        return $found;
    }

    /**
     * The literal names a template includes, embeds or imports. Only tags and
     * expressions run: a comment, plain text and the inside of a string include
     * nothing, so `{{ '{#' }}` hides no include after it and
     * `{{ "{% include 'a.txt.twig' %}" }}` is none.
     *
     * @return list<string>|null null when the regex engine gave up: no scan of
     *                           what is left can tell a template clean
     */
    private static function includedNames(string $source): ?array
    {
        $string = '\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"';
        if (preg_match_all('/\{[{%](?:' . $string . '|[^\'"])*?[}%]\}|\{#.*?#\}/s', $source, $regions) === false) {
            return null;
        }
        $names = [];
        foreach ($regions[0] as $region) {
            if (str_starts_with($region, '{#')) {
                continue;
            }
            // Each string becomes "<n>", so only the tag's own words are matched.
            $literals = [];
            $masked = preg_replace_callback('/' . $string . '/s', static function (array $m) use (&$literals): string {
                $literals[] = substr($m[0], 1, -1);

                return '"' . (count($literals) - 1) . '"';
            }, $region);
            if ($masked === null) {
                return null;
            }
            preg_match_all('/(?:\{%[-~]?\s*(?:include|embed|import|from)\s+|\b(?:include|source)\(\s*)(\[[^\]]*\]|"\d+")/', $masked, $arguments);
            preg_match_all('/"(\d+)"/', implode(' ', $arguments[1]), $indexes);
            foreach ($indexes[1] as $index) {
                if (str_ends_with($literals[(int) $index], '.twig')) {
                    $names[] = $literals[(int) $index];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The POST forms of the HTML templates under $dir that present no CSRF
     * token ({@see CSRF_RULE}).
     *
     * @param list<string> $unreadable filled with the HTML templates that could not be read
     * @param list<string> $unanalysed filled with those the regex engine gave up on (backtrack limit)
     * @return list<array{string, int, bool}> [file, line of the <form>, whether its method is a Twig expression]
     */
    public static function postFormsWithoutCsrfToken(string $dir, array &$unreadable = [], array &$unanalysed = []): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.twig') || self::escapingOf($path) !== 'html') {
                continue;
            }
            $source = @file_get_contents($path);
            if ($source === false) {
                $unreadable[] = $path;
                continue;
            }
            $forms = self::tokenlessPostForms($source);
            if ($forms === null) {
                $unanalysed[] = $path;
                continue;
            }
            foreach ($forms as [$line, $methodIsExpression]) {
                $found[] = [$path, $line, $methodIsExpression];
            }
        }

        return $found;
    }

    /**
     * The POST forms of one template that present no token, by the line of
     * their `<form>`.
     *
     * A form is checked when its method is POST, in any case and quoting, or a
     * Twig expression (`method="{{ form.method|default('POST') }}"` can be
     * POST), or when a button or input in it says formmethod="post". The token
     * is what CsrfListener reads from the body: the field csrf_field() prints,
     * or an `<input>` named CsrfField::NAME written by hand.
     * The X-CSRF-Token header is sent by script and is not in the markup.
     *
     * A form reaches to its `</form>`, or to the next `<form>` or the end of
     * the template when it has none: a token outside it is not submitted.
     *
     * @return list<array{int, bool}>|null [line, whether POST comes only from a
     *                                     Twig expression]; null when the regex
     *                                     engine gave up
     */
    public static function tokenlessPostForms(string $source): ?array
    {
        $markup = self::withoutCommentsAndVerbatim($source);
        if ($markup === null) {
            return null;
        }
        // A tag ends at the first > outside a quoted value and outside Twig: `{% if a > b %}` is inside it.
        $attributes = '(?:\{\{.*?\}\}|\{%.*?%\}|"[^"]*"|\'[^\']*\'|[^>"\'])*';
        if (preg_match_all('/<form\b(' . $attributes . ')>/is', $markup, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return null;
        }

        $found = [];
        foreach ($tags as $i => $tag) {
            $from = $tag[0][1] + strlen($tag[0][0]);
            $next = isset($tags[$i + 1]) ? $tags[$i + 1][0][1] : strlen($markup);
            $close = stripos($markup, '</form', $from);
            $body = substr($markup, $from, min($close === false ? $next : $close, $next) - $from);
            if (preg_match_all('/<(input|button)\b(' . $attributes . ')>/is', $body, $controls, PREG_SET_ORDER) === false) {
                return null;
            }

            // What the form posts with: its own method, or the formmethod of a
            // button or input in it, which overrides the form's for that submit.
            $action = self::attribute($tag[1][0], 'action');
            $posts = [];
            if (self::canBePost(self::attribute($tag[1][0], 'method'), $isExpression)) {
                $posts[] = [$action, $isExpression];
            }
            foreach ($controls as $control) {
                if (self::canBePost(self::attribute($control[2], 'formmethod'), $isExpression)) {
                    $posts[] = [self::attribute($control[2], 'formaction') ?? $action, $isExpression];
                }
            }
            // Another site's form must not carry this site's token.
            $posts = array_values(array_filter(
                $posts,
                static fn (array $post): bool => preg_match('~\A(?:https?:)?//~i', trim($post[0] ?? '')) !== 1,
            ));
            if ($posts === []) {
                continue;
            }

            $presentsToken = preg_match('/\bcsrf_field\s*\(/', $body) === 1;
            foreach ($controls as $control) {
                // Only an input is submitted: <meta name="_csrf"> in a form is not.
                $presentsToken = $presentsToken
                    || (strtolower($control[1]) === 'input' && self::attribute($control[2], 'name') === CsrfField::NAME);
            }
            if (!$presentsToken) {
                $found[] = [
                    substr_count($markup, "\n", 0, $tag[0][1]) + 1,
                    array_filter($posts, static fn (array $post): bool => !$post[1]) === [],
                ];
            }
        }

        return $found;
    }

    /**
     * Whether a method or formmethod value can make the browser POST: the
     * literal, in any case, or a Twig expression, which can print it.
     *
     * @param-out bool $isExpression
     */
    private static function canBePost(?string $method, ?bool &$isExpression = null): bool
    {
        $isExpression = $method !== null && (str_contains($method, '{{') || str_contains($method, '{%'));

        return $isExpression || ($method !== null && strtolower(trim($method)) === 'post');
    }

    /** The value of an attribute of an opening tag, quotes removed; null when the tag has none. */
    private static function attribute(string $attributes, string $name): ?string
    {
        if (preg_match('/(?<![\w-])' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/is', $attributes, $m) !== 1) {
            return null;
        }

        return $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
    }

    /**
     * The template with its Twig comments, its HTML comments and the inside of
     * its verbatim blocks blanked, line for line: a `<form>` there is text, not
     * a form, and Twig runs nothing inside verbatim. As in includedNames(), a
     * comment delimiter inside a Twig string opens no comment.
     */
    private static function withoutCommentsAndVerbatim(string $source): ?string
    {
        $string = '\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"';
        $blank = static fn (string $text): string => preg_replace('/[^\n]/', ' ', $text) ?? '';

        return preg_replace_callback(
            '/\{#.*?#\}'
            . '|\{%[-~]?\s*(verbatim|raw)\s*[-~]?%\}.*?\{%[-~]?\s*end\1\s*[-~]?%\}'
            . '|<!--.*?-->'
            . '|\{[{%](?:' . $string . '|[^\'"])*?[}%]\}/s',
            static fn (array $m): string => str_starts_with($m[0], '{{') || (str_starts_with($m[0], '{%') && ($m[1] ?? '') === '') ? $m[0] : $blank($m[0]),
            $source,
        );
    }

    /** The strategy ModuleTemplateCatalog's 'autoescape' => 'name' gives a template of this name. */
    private static function escapingOf(string $name): string
    {
        $name = preg_replace('/\.twig\z/', '', $name) ?? $name;

        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'txt' => 'plain text (not at all)',
            'js', 'json' => 'JavaScript',
            'css' => 'CSS',
            default => 'html',
        };
    }

    private function relativePath(string $path, string $root): string
    {
        $root = rtrim($root, '/\\');
        if ($path === $root) {
            return '.';
        }

        $prefix = $root . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function outputTable(SymfonyStyle $io, array $errors, array $warnings): void
    {
        $io->title('Template Directory Lint');

        if (count($errors) === 0 && count($warnings) === 0) {
            $io->success('All template directories follow canonical conventions.');
            return;
        }

        if (count($errors) > 0) {
            $rows = array_map(
                fn ($e) => [$e['module'], $e['path'], $e['message']],
                $errors
            );
            $io->section('Errors');
            $io->table(['Module', 'Path', 'Issue'], $rows);
        }

        if (count($warnings) > 0) {
            $rows = array_map(
                fn ($w) => [$w['module'], $w['path'], $w['message']],
                $warnings
            );
            $io->section('Warnings');
            $io->table(['Module', 'Path', 'Issue'], $rows);
        }

        if (count($errors) > 0) {
            $io->error(sprintf('%d error(s) found. Fix before merge.', count($errors)));
        } elseif (count($warnings) > 0) {
            $io->warning(sprintf('%d warning(s). Use --strict to block on warnings.', count($warnings)));
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function outputJson(OutputInterface $output, array $errors, array $warnings): void
    {
        $data = [
            'errors'   => $errors,
            'warnings' => $warnings,
            'clean'    => count($errors) === 0 && count($warnings) === 0,
        ];

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $fallback = [
                'clean' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'json_error' => json_last_error_msg(),
            ];
            $json = json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $output->writeln($json !== false ? $json : '{"clean":false,"errors":[],"warnings":[],"json_error":"Failed to encode lint output"}');
    }
}
