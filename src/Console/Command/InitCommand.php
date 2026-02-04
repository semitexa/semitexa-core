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
 * docker-compose.yml, Dockerfile, AI_ENTRY.md, README.md, docs, example Request/Handler, autoload in composer.json, one test.
 */
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Create Semitexa project structure + AI_ENTRY, README, docs, example code, test')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Target directory (default: current working directory)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('only-docs', null, InputOption::VALUE_NONE, 'Only update AI_ENTRY.md and project docs (CONVENTIONS, ADDING_ROUTES, etc.) from template — for existing projects after upgrading semitexa/core');
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
            'docs',
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
            'docs/CONVENTIONS.md' => $this->getConventionsContent(),
            'docs/DEPENDENCIES.md' => $this->getDependenciesContent(),
            'docs/RUNNING.md' => $this->getRunningDocContent(),
            'docs/ADDING_ROUTES.md' => $this->getAddingRoutesStubContent(),
            'src/Request/HomeRequest.php' => $this->getHomeRequestContent(),
            'src/Handler/HomeHandler.php' => $this->getHomeHandlerContent(),
            'tests/HomeTest.php' => $this->getHomeTestContent(),
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
     * Update only AI_ENTRY.md and project docs from framework template (for existing projects after upgrading semitexa/core).
     */
    private function executeOnlyDocs(string $root, SymfonyStyle $io, bool $force): int
    {
        $io->title('Semitexa docs sync');
        $io->text('Project root: ' . $root);

        if (!is_dir($root . '/docs')) {
            if (!@mkdir($root . '/docs', 0755, true)) {
                $io->error('Failed to create directory: docs/');
                return Command::FAILURE;
            }
            $io->text('Created: docs/');
        }

        $docFiles = [
            'AI_ENTRY.md' => $this->getAiEntryContent(),
            'README.md' => $this->getReadmeContent(),
            'server.php' => $this->getServerPhpContent(),
            '.env.example' => $this->getEnvExampleContent(),
            'docs/CONVENTIONS.md' => $this->getConventionsContent(),
            'docs/DEPENDENCIES.md' => $this->getDependenciesContent(),
            'docs/RUNNING.md' => $this->getRunningDocContent(),
            'docs/ADDING_ROUTES.md' => $this->getAddingRoutesStubContent(),
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

        $io->success('Docs and scaffold updated. AI_ENTRY, README, docs/, server.php, .env.example refreshed from framework template.');
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
        $json['autoload'] = array_merge($autoload, ['psr-4' => $psr4]);
        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return;
        }
        if (file_put_contents($path, $encoded) !== false) {
            $io->text('Updated composer.json: autoload.psr-4 "App\\": "src/", "App\\Tests\\": "tests/"');
        }
    }

    private function getAiEntryContent(): string
    {
        return <<<'MD'
# Semitexa Framework - AI Assistant Entry Point

> **Guiding principle:** Make it work → Make it right → Make it fast.

## Project structure (standalone app)

- **bin/semitexa** – CLI
- **public/** – web root
- **src/** – application code; **new routes** go in **modules** (src/modules/), not in src/Request or src/Handler (App\ is not discovered for routes). See docs/CONVENTIONS.md and docs/ADDING_ROUTES.md.
- **src/modules/** – application modules (where to add new pages and endpoints)
- **var/log**, **var/cache** – runtime
- **var/docs/** – working directory for AI: temporary notes, refactor plans, draft docs. Content not committed (`.gitignore`).
- **AI_NOTES.md** – your own notes for AI (created once, never overwritten by the framework).
- **vendor/semitexa/** – framework packages

## Framework docs (in vendor)

- **vendor/semitexa/docs/AI_REFERENCE.md** – main reference for AI; **before creating or changing module structure** read it → section **Module Structure** (Standard Module Layout).
- **vendor/semitexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/semitexa/core/docs/attributes/README.md** – attribute index
- **vendor/semitexa/core/docs/ADDING_ROUTES.md** – how to add new pages/routes (modules only)

## Conventions & dependencies

- **docs/CONVENTIONS.md** – namespace, Request/Handler only in modules, semitexa commands
- **docs/ADDING_ROUTES.md** – new pages/routes = only via modules
- **docs/DEPENDENCIES.md** – semitexa/core version, where to find official docs

## Quick start

1. Read this file and docs/CONVENTIONS.md
2. Run: `cp .env.example .env` then `bin/semitexa server:start` (Docker)
3. Default URL: http://0.0.0.0:9501 (see .env SWOOLE_PORT). See docs/RUNNING.md for details.
MD;
    }

    private function getReadmeContent(): string
    {
        return <<<'MD'
# Semitexa App

Minimal Semitexa Framework application.

## Requirements

- Docker and Docker Compose
- Composer (on host for install)

## Install

```bash
composer install
cp .env.example .env
```

## Run (Docker — supported way)

```bash
bin/semitexa server:start
```

To stop: `bin/semitexa server:stop`.

Default URL: **http://0.0.0.0:9501** (configurable via `.env` `SWOOLE_PORT`). See **docs/RUNNING.md** for details.

## Structure

- `src/Request/`, `src/Handler/` – example code only; **not** used for route discovery. New pages/routes go in **modules** (see docs/ADDING_ROUTES.md).
- `src/modules/` – your modules (add new pages and endpoints here).
- `var/docs/` – working directory for temporary/intermediate files (e.g. AI notes, plans, drafts); not committed.
- `docs/CONVENTIONS.md` – coding conventions
- `docs/ADDING_ROUTES.md` – how to add new pages/routes (modules only)
- `docs/DEPENDENCIES.md` – Semitexa version and docs
- `AI_ENTRY.md` – entry point for AI assistants
- `AI_NOTES.md` – your own notes for AI (never overwritten by framework)

## Tests

`composer require --dev phpunit/phpunit` then `vendor/bin/phpunit`. See `tests/HomeTest.php` and `phpunit.xml.dist`.
MD;
    }

    private function getConventionsContent(): string
    {
        return <<<'MD'
# Conventions

- **Application namespace:** `App\` (for tests, helpers; **not** for routes — see below).
- **Routes (Request/Handler):** **Only in modules** (`src/modules/`, `packages/`, or `vendor/`). Do **not** put new Request/Handler for pages or endpoints in project `src/Request/` or `src/Handler/` — they are **not** discovered. See **docs/ADDING_ROUTES.md** (or `vendor/semitexa/core/docs/ADDING_ROUTES.md`).
- **Request/Handler in modules:** use `#[AsRequest(path: '...', methods: ['GET'])]` and `#[AsRequestHandler(for: SomeRequest::class)]`; handler method `handle(RequestInterface $request, ResponseInterface $response): ResponseInterface`.
- **Response DTOs:** optional; or return `\Semitexa\Core\Response::json([...])` from handler.
- **CLI:** `vendor/bin/semitexa` or `bin/semitexa` – `init`, `server:start`, `server:stop`, etc.
- **Running:** Only via Docker: `bin/semitexa server:start` / `server:stop`. See docs/RUNNING.md.
MD;
    }

    private function getDependenciesContent(): string
    {
        return <<<'MD'
# Semitexa dependencies

- **semitexa/core** – see `composer.json` for version; framework docs in `vendor/semitexa/core/docs/` (attributes, RUNNING.md, ADDING_ROUTES.md)
- Running: Docker only – see `docs/RUNNING.md` or `vendor/semitexa/core/docs/RUNNING.md`
- New routes: only via modules – see `docs/ADDING_ROUTES.md` or `vendor/semitexa/core/docs/ADDING_ROUTES.md`
- Official repo: check Packagist or GitHub for `semitexa/core`
MD;
    }

    private function getHomeRequestContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Request;

use Semitexa\Core\Attributes\AsRequest;
use Semitexa\Core\Contract\RequestInterface;

#[AsRequest(path: '/', methods: ['GET'])]
class HomeRequest implements RequestInterface
{
}
PHP;
    }

    private function getHomeHandlerContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Handler;

use Semitexa\Core\Attributes\AsRequestHandler;
use Semitexa\Core\Contract\RequestInterface;
use Semitexa\Core\Contract\ResponseInterface;
use Semitexa\Core\Response;

#[AsRequestHandler(for: \App\Request\HomeRequest::class)]
class HomeHandler
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Response::json([
            'message' => 'Hello from Semitexa!',
            'path' => '/',
        ]);
    }
}
PHP;
    }

    private function getHomeTestContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

class HomeTest extends TestCase
{
    public function test_home_returns_expected_structure(): void
    {
        $this->assertTrue(true, 'Bootstrap test; replace with real HTTP test when server is available');
    }
}
PHP;
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
        return <<<'ENV'
# Environment: dev, test, prod
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Semitexa App"

# Swoole server
SWOOLE_HOST=0.0.0.0
SWOOLE_PORT=9501
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
        return <<<'YAML'
# Minimal Semitexa app: PHP + Swoole in Docker.
# Start: bin/semitexa server:start | Stop: bin/semitexa server:stop
services:
  app:
    build: .
    container_name: semitexa-app
    env_file: .env
    volumes:
      - .:/var/www/html
    ports:
      - "${SWOOLE_PORT:-9501}:9501"
    restart: unless-stopped
    command: ["php", "server.php"]

YAML;
    }

    private function getDockerfileContent(): string
    {
        return <<<'DOCKER'
# Minimal PHP + Swoole for Semitexa (project is mounted at runtime)
FROM php:8.2-cli-alpine

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

    private function getRunningDocContent(): string
    {
        return <<<'MD'
# Running the application

The **only supported way** to run a Semitexa app is via Docker.

- **Start:** `bin/semitexa server:start` (runs `docker compose up -d`)
- **Stop:** `bin/semitexa server:stop` (runs `docker compose down`)
- **Logs:** `docker compose logs -f`

The app container runs `php server.php` inside Docker; the Swoole server listens on port 9501 (configurable via `.env` `SWOOLE_PORT`). Do not run `php server.php` on the host as the primary way to run the app.
MD;
    }

    private function getAddingRoutesStubContent(): string
    {
        return <<<'MD'
# Adding new pages and routes

**New routes (pages, endpoints) in Semitexa are added only via modules.**  
Request/Handler classes in project `src/Request/` or `src/Handler/` (namespace `App\`) are **not discovered** — do not add new routes there.

- Create or use a **module** under `src/modules/{ModuleName}/` with a `composer.json` (`"type": "semitexa-module"` and PSR-4, e.g. `Semitexa\Modules\Website\` → `src/`).
- Put Request and Handler classes in that module namespace; use `#[AsRequest]` and `#[AsRequestHandler]`.
- Run `composer dump-autoload` in the project root after adding/changing a module.

**Full guide:** `vendor/semitexa/core/docs/ADDING_ROUTES.md`
MD;
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
