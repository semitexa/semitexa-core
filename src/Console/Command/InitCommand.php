<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds Semitexa project structure: bin/, public/, src/, var/, .env.example, server.php, bin/semitexa,
 * docker-compose.yml, Dockerfile, AI_ENTRY.md, README.md, var/docs (AI working dir only), phpunit.xml.dist, autoload in composer.json.
 * Framework docs (CONVENTIONS, RUNNING, ADDING_ROUTES) live in vendor/semitexa/core/docs/ — not written into project.
 */
class InitCommand extends Command
{
    /** Default Swoole port (single source of truth for .env.example, docker-compose, docs). */
    private const DEFAULT_SWOOLE_PORT = 9502;

    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Create Semitexa project structure + AI_ENTRY, README, var/docs, phpunit.xml.dist')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Target directory (default: current working directory)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('only-docs', null, InputOption::VALUE_NONE, 'Only update AI_ENTRY.md, README, server.php, .env.example from template — for existing projects after upgrading semitexa/core');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $force = (bool) $input->getOption('force');
        $onlyDocs = (bool) $input->getOption('only-docs');

        $root = $dir !== null ? realpath($dir) : getcwd();
        if ($root === false || !is_dir($root)) {
            $io->error('Target directory does not exist or is not readable: ' . ($dir ?? getcwd()));
            return Command::FAILURE;
        }

        if ($onlyDocs) {
            return $this->executeOnlyDocs($root, $io, $force);
        }

        $io->title('Semitexa project init');
        $io->text('Project root: ' . $root);

        $dirs = [
            'bin',
            'public',
            'src/modules',
            'src/infrastructure/database',
            'src/infrastructure/migrations',
            'tests',
            'var/cache',
            'var/log',
            'var/docs',
        ];

        foreach ($dirs as $path) {
            $full = $root . '/' . $path;
            if (!is_dir($full)) {
                if (!@mkdir($full, 0755, true)) {
                    $io->error('Failed to create directory: ' . $path);
                    return Command::FAILURE;
                }
                $io->text('Created: ' . $path . '/');
            }
        }

        // Keep empty var subdirs in git
        foreach (['var/cache', 'var/log', 'var/docs'] as $path) {
            $gitkeep = $root . '/' . $path . '/.gitkeep';
            if (!file_exists($gitkeep)) {
                file_put_contents($gitkeep, '');
            }
        }

        $created = [];
        $skipped = [];

        $files = [
            'AI_ENTRY.md' => $this->getAiEntryContent(),
            'README.md' => $this->getReadmeContent(),
            '.env.example' => $this->getEnvExampleContent(),
            'server.php' => $this->getServerPhpContent(),
            'bin/semitexa' => $this->getBinSemitexaContent(),
            '.gitignore' => $this->getGitignoreContent(),
            'public/.htaccess' => $this->getHtaccessContent(),
            'docker-compose.yml' => $this->getDockerComposeContent(),
            'Dockerfile' => $this->getDockerfileContent(),
            'phpunit.xml.dist' => $this->getPhpunitXmlContent(),
        ];

        foreach ($files as $relPath => $content) {
            $full = $root . '/' . $relPath;
            if (file_exists($full) && !$force) {
                $skipped[] = $relPath;
                continue;
            }
            $dir = dirname($full);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (file_put_contents($full, $content) === false) {
                $io->error('Failed to write: ' . $relPath);
                return Command::FAILURE;
            }
            if ($relPath === 'bin/semitexa') {
                @chmod($full, 0755);
            }
            $created[] = $relPath;
        }

        foreach ($created as $f) {
            $io->text('Written: ' . $f);
        }
        foreach ($skipped as $f) {
            $io->note('Skipped (exists): ' . $f . ' (use --force to overwrite)');
        }

        // AI_NOTES.md: create only if missing; never overwrite (so developer can keep own notes)
        $aiNotesPath = $root . '/AI_NOTES.md';
        if (!file_exists($aiNotesPath)) {
            if (file_put_contents($aiNotesPath, $this->getAiNotesStubContent()) !== false) {
                $io->text('Written: AI_NOTES.md (your notes; never overwritten by framework)');
            }
        }

        $this->patchComposerAutoload($root, $io, $force);

        $io->success('Project structure created.');
        $io->text([
            'Next steps:',
            '  1. cp .env.example .env',
            '  2. Edit .env (SWOOLE_PORT, etc.) if needed',
            '  3. composer dump-autoload (if autoload was added)',
            '  4. Add your modules under src/modules/',
            '  5. Run: bin/semitexa server:start (Docker)',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Update only AI_ENTRY.md, README, server.php, .env.example from framework template (for existing projects after upgrading semitexa/core).
     */
    private function executeOnlyDocs(string $root, SymfonyStyle $io, bool $force): int
    {
        $io->title('Semitexa docs sync');
        $io->text('Project root: ' . $root);

        $docFiles = [
            'AI_ENTRY.md' => $this->getAiEntryContent(),
            'README.md' => $this->getReadmeContent(),
            'server.php' => $this->getServerPhpContent(),
            '.env.example' => $this->getEnvExampleContent(),
        ];

        $created = [];
        $skipped = [];
        foreach ($docFiles as $relPath => $content) {
            $full = $root . '/' . $relPath;
            if (file_exists($full) && !$force) {
                $skipped[] = $relPath;
                continue;
            }
            $dir = dirname($full);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (file_put_contents($full, $content) === false) {
                $io->error('Failed to write: ' . $relPath);
                return Command::FAILURE;
            }
            $created[] = $relPath;
        }

        foreach ($created as $f) {
            $io->text('Written: ' . $f);
        }
        foreach ($skipped as $f) {
            $io->note('Skipped (exists): ' . $f . ' (use --force to overwrite)');
        }

        $io->success('AI_ENTRY.md, README.md, server.php, .env.example updated from framework template.');
        $io->text('.env is never touched. Copy new vars from .env.example to .env if needed.');
        return Command::SUCCESS;
    }

    private function patchComposerAutoload(string $root, SymfonyStyle $io, bool $force): void
    {
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            return;
        }
        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            return;
        }
        $autoload = $json['autoload'] ?? [];
        $psr4 = $autoload['psr-4'] ?? [];
        if (isset($psr4['App\\']) && !$force) {
            return;
        }
        $psr4['App\\'] = 'src/';
        $psr4['App\\Tests\\'] = 'tests/';
        if (!isset($psr4['Semitexa\\Modules\\'])) {
            $psr4['Semitexa\\Modules\\'] = 'src/modules/';
        }
        $json['autoload'] = array_merge($autoload, ['psr-4' => $psr4]);
        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return;
        }
        if (file_put_contents($path, $encoded) !== false) {
            $io->text('Updated composer.json: autoload.psr-4 "App\\": "src/", "App\\Tests\\": "tests/", "Semitexa\\Modules\\": "src/modules/"');
        }
    }

    private function getAiEntryContent(): string
    {
        $port = self::DEFAULT_SWOOLE_PORT;
        return <<<MD
# Semitexa Framework - AI Assistant Entry Point

> **Guiding principle:** Make it work → Make it right → Make it fast.

## Foundational context (stack versions)

Use these versions so you don't assume outdated syntax or APIs:

- **PHP:** ^8.4 (see `composer.json` / `composer.lock`)
- **semitexa/core:** dev-main or v1.x (path packages: `pakages/semitexa-core` or `vendor/semitexa/core`)
- **semitexa/docs:** ^1.0 (AI_REFERENCE, guides)
- **Key dependencies:** Symfony 7.x (console, process, etc.), Twig ^3.10, PHP-DI ^7.1

Exact versions are in `composer.lock`. Do not assume Laravel, Illuminate, or Kernel-style middleware — Semitexa has its own module and route discovery.

## Rules and guards

- **Do not** add root-level directories or change module discovery without explicit user approval.
- **Do not** add Composer dependencies without explicit user approval.
- **Do not** create documentation files (README, guides, extra `.md` in the project) unless the user explicitly asks for them.
- **Do not** create or use a `docs/` folder in the project root; use `var/docs/` for AI working files only.

## Read before you change (mandatory)

| Before you… | Read first |
|-------------|------------|
| Create or change **module structure** (folders, Application/…) | **vendor/semitexa/docs/AI_REFERENCE.md** → section **Module Structure** |
| Change **service contracts** or DI bindings | **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md**; run `bin/semitexa contracts:list --json` to see current bindings |
| Add **new pages or routes** | **vendor/semitexa/core/docs/ADDING_ROUTES.md** |

## Before you generate code (checklist)

- **Modules:** only in `src/modules/`; standard layout: `Application/Payload/`, `Application/Resource/`, `Application/Handler/Request/`, `Application/View/templates/`.
- **Routes:** only via modules (Request + Handler with attributes). Do not add routes in project `src/` (App\ is not discovered).
- **Module autoload:** do not add per-module PSR-4 entries to project root `composer.json`; the framework autoloads from `src/modules/` at runtime.
- **Contracts/DI:** before changing a contract or adding an override, run `bin/semitexa contracts:list` or `contracts:list --json` to see current implementations and active binding.

## Project structure (standalone app)

- **bin/semitexa** – CLI
- **public/** – web root
- **src/** – application code; **new routes** go in **modules** (src/modules/), not in src/Request or src/Handler (App\ is not discovered for routes).
- **src/modules/** – application modules (where to add new pages and endpoints). **Do not add per-module PSR-4 entries to composer.json** – the framework autoloads all modules from src/modules/ via IntelligentAutoloader at runtime.
- **var/log**, **var/cache** – runtime
- **var/docs/** – working directory for AI only: temporary notes, plans, drafts. Content not committed (`.gitignore`). **Do not create or use a docs/ folder in the project root.**
- **AI_NOTES.md** – your own notes for AI (created once, never overwritten by the framework).
- **vendor/semitexa/** – framework packages

## Framework docs (in vendor — read these, do not copy into project)

- **vendor/semitexa/docs/AI_REFERENCE.md** – main reference for AI; **before creating or changing module structure** read it → section **Module Structure** (Standard Module Layout).
- **vendor/semitexa/core/docs/ADDING_ROUTES.md** – how to add new pages/routes (modules only)
- **vendor/semitexa/core/docs/RUNNING.md** – how to run the app (Docker)
- **vendor/semitexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** – service contracts, active implementation, and **contracts:list** command
- **vendor/semitexa/docs/README.md** – package map; **vendor/semitexa/docs/guides/CONVENTIONS.md** – conventions (when semitexa/docs is installed)

## Machine-readable commands (for AI agents and scripts)

These commands produce **stable, parseable output** — use them instead of scraping human-oriented tables:

| Command | Output | Use when |
|---------|--------|----------|
| `bin/semitexa contracts:list --json` | JSON: `contracts[]` with `contract`, `active`, `implementations` | Debugging DI, checking bindings before/after changing contracts or modules. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md. |

(More commands may be added here with `--json` or similar; check `bin/semitexa list` and framework docs.)

## Debugging: service contracts (for AI agents and developers)

To see **which interface is bound to which implementation** (and which implementation is active when several modules provide one):

```bash
bin/semitexa contracts:list
```

Table: Contract (interface) | Implementations (module → class) | Active. Use when debugging DI or after adding/removing modules.

**For AI agents:** use `bin/semitexa contracts:list --json` for stable, parseable output. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md for details.

## Quick start

1. Read this file; follow **Read before you change** above when modifying modules, contracts, or routes.
2. For new routes: read vendor/semitexa/core/docs/ADDING_ROUTES.md
3. Run (Docker):

```bash
cp .env.example .env
```

```bash
bin/semitexa server:start
```

4. Default URL: http://0.0.0.0:{$port} (see .env SWOOLE_PORT). See vendor/semitexa/core/docs/RUNNING.md for details.
MD;
    }

    /**
     * README template. Do not reference project-root docs/. Point to vendor docs; include a clear Documentation section for humans.
     */
    private function getReadmeContent(): string
    {
        $port = self::DEFAULT_SWOOLE_PORT;
        return <<<MD
# About Semitexa

"Make it work, make it right, make it fast." — Kent Beck

Semitexa isn't just a framework; it's a philosophy of efficiency.
Engineered for the high-performance Swoole ecosystem and built with an AI-first mindset,
it allows you to stop fighting the infrastructure and start building the future.

Simple by design. Powerful by nature.

## Requirements

- Docker and Docker Compose
- Composer (on host for install)

## Install

From an empty folder (get the framework and install dependencies):

```bash
composer require semitexa/core
```

From a clone or existing project (dependencies already in `composer.json`):

```bash
composer install
```

Then:

```bash
cp .env.example .env
```

## Run (Docker — supported way)

```bash
bin/semitexa server:start
```

To stop:

```bash
bin/semitexa server:stop
```

Default URL: **http://0.0.0.0:{$port}** (configurable via `.env` `SWOOLE_PORT`).

## Documentation

All framework documentation lives in `vendor/` (installed with Composer). Open these from the project root:

| Topic | File or folder |
|-------|----------------|
| **Running the app** — Docker, ports, logs | [vendor/semitexa/core/docs/RUNNING.md](vendor/semitexa/core/docs/RUNNING.md) |
| **Adding pages and routes** — modules, Request/Handler | [vendor/semitexa/core/docs/ADDING_ROUTES.md](vendor/semitexa/core/docs/ADDING_ROUTES.md) |
| **Attributes** — AsPayload, AsPayloadHandler, AsResource, etc. | [vendor/semitexa/core/docs/attributes/README.md](vendor/semitexa/core/docs/attributes/README.md) |
| **Service contracts** — contracts:list, active implementation | [vendor/semitexa/core/docs/SERVICE_CONTRACTS.md](vendor/semitexa/core/docs/SERVICE_CONTRACTS.md) |
| **Package map & conventions** (if semitexa/docs is installed) | [vendor/semitexa/docs/README.md](vendor/semitexa/docs/README.md) · [vendor/semitexa/docs/guides/CONVENTIONS.md](vendor/semitexa/docs/guides/CONVENTIONS.md) |

In your editor you can open these paths directly (e.g. Ctrl+P → paste path). No `docs/` folder in the project root — everything is in vendor.

## Structure

- `src/modules/` – your application modules (add new pages and endpoints here). New routes only in modules.
- `var/docs/` – working directory for notes and drafts; not committed.
- `AI_ENTRY.md` – entry point for AI assistants; `AI_NOTES.md` – your notes (never overwritten).

## Tests

```bash
composer require --dev phpunit/phpunit
```

```bash
vendor/bin/phpunit
```

Use `phpunit.xml.dist`; add tests in `tests/`.
MD;
    }

    private function getPhpunitXmlContent(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.0/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
XML;
    }

    private function getEnvExampleContent(): string
    {
        $port = self::DEFAULT_SWOOLE_PORT;
        return <<<ENV
# Environment: dev, test, prod
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Semitexa App"

# Swoole server
SWOOLE_HOST=0.0.0.0
SWOOLE_PORT={$port}
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_REQUEST=10000
SWOOLE_MAX_COROUTINE=100000
SWOOLE_LOG_FILE=var/log/swoole.log
SWOOLE_LOG_LEVEL=1

# CORS
CORS_ALLOW_ORIGIN=*
CORS_ALLOW_METHODS=GET, POST, PUT, DELETE, OPTIONS
CORS_ALLOW_HEADERS=Content-Type, Authorization
CORS_ALLOW_CREDENTIALS=false

ENV;
    }

    private function getServerPhpContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

define('SEMITEXA_SWOOLE', true);

require_once __DIR__ . '/vendor/autoload.php';

if (!extension_loaded('swoole')) {
    die("Swoole extension is required.\n");
}

use Swoole\Http\Server;
use Semitexa\Core\Application;
use Semitexa\Core\ErrorHandler;
use Semitexa\Core\Request;

\Semitexa\Core\Container\ContainerFactory::create();
$app = new Application();
$env = $app->getEnvironment();
ErrorHandler::configure($env);

$server = new Server($env->swooleHost, $env->swoolePort);
$server->set([
    'worker_num' => $env->swooleWorkerNum,
    'max_request' => $env->swooleMaxRequest,
    'enable_coroutine' => true,
]);

$server->on('request', function ($request, $response) use ($app, $env) {
    $response->header('Access-Control-Allow-Origin', $env->corsAllowOrigin);
    $response->header('Access-Control-Allow-Methods', $env->corsAllowMethods);
    $response->header('Access-Control-Allow-Headers', $env->corsAllowHeaders);

    if (($request->server['request_method'] ?? 'GET') === 'OPTIONS') {
        $response->status(200);
        $response->end();
        return;
    }

    try {
        $semitexaRequest = Request::create($request);
        $semitexaResponse = $app->handleRequest($semitexaRequest);
        $response->status($semitexaResponse->getStatusCode());
        foreach ($semitexaResponse->getHeaders() as $name => $value) {
            $response->header($name, $value);
        }
        $response->end($semitexaResponse->getContent());
    } catch (\Throwable $e) {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
        ]));
    } finally {
        $app->getRequestScopedContainer()->reset();
    }
});

echo "Semitexa server: http://{$env->swooleHost}:{$env->swoolePort}\n";
$server->start();

PHP;
    }

    private function getBinSemitexaContent(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Console\Application;

AttributeDiscovery::initialize();
$application = new Application();
$application->run();

PHP;
    }

    private function getGitignoreContent(): string
    {
        return <<<'GIT'
/vendor/
.env
var/cache/*
var/log/*
var/docs/*
!.gitkeep

GIT;
    }

    private function getHtaccessContent(): string
    {
        return <<<'HTA'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

HTA;
    }

    private function getDockerComposeContent(): string
    {
        $templatePath = dirname(__DIR__, 3) . '/resources/init/docker-compose.yml';
        if (!is_readable($templatePath)) {
            // Fallback if package is not installed as source (e.g. from vendor)
            $port = self::DEFAULT_SWOOLE_PORT;
            return "# Minimal Semitexa app: PHP + Swoole in Docker.\n"
                . "# Start: bin/semitexa server:start | Stop: bin/semitexa server:stop\n"
                . "services:\n  app:\n    build: .\n    container_name: semitexa-app\n    env_file: .env\n"
                . "    volumes:\n      - .:/var/www/html\n    ports:\n"
                . "      - \"\${SWOOLE_PORT:-{$port}}:\${SWOOLE_PORT:-{$port}}\"\n"
                . "    restart: unless-stopped\n    command: [\"php\", \"server.php\"]\n";
        }
        $content = file_get_contents($templatePath);
        return str_replace('{{ default_swoole_port }}', (string) self::DEFAULT_SWOOLE_PORT, $content);
    }

    private function getDockerfileContent(): string
    {
        return <<<'DOCKER'
# Minimal PHP + Swoole for Semitexa (project is mounted at runtime)
FROM php:8.4-cli-alpine

RUN apk add --no-cache autoconf g++ make linux-headers openssl-dev \
    && pecl install --nobuild swoole \
    && cd "$(pecl config-get temp_dir)/swoole" \
    && phpize && ./configure --enable-openssl --disable-brotli --disable-zstd \
    && make -j$(nproc) && make install \
    && docker-php-ext-enable swoole

WORKDIR /var/www/html

CMD ["php", "server.php"]

DOCKER;
    }

    /**
     * Stub for AI_NOTES.md — created once, never overwritten by the framework (developer's own notes).
     */
    private function getAiNotesStubContent(): string
    {
        return <<<'MD'
# Your notes for AI

Add your own context, instructions, or notes for AI here. This file is never overwritten by the framework.

MD;
    }
}
