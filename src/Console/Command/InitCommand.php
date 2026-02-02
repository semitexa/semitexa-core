<?php

declare(strict_types=1);

namespace Syntexa\Core\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds Syntexa project structure: bin/, public/, src/, var/, .env.example, server.php, bin/syntexa,
 * docker-compose.yml, Dockerfile, AI_ENTRY.md, README.md, docs, example Request/Handler, autoload in composer.json, one test.
 */
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Create Syntexa project structure + AI_ENTRY, README, docs, example code, test')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Target directory (default: current working directory)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $force = (bool) $input->getOption('force');

        $root = $dir !== null ? realpath($dir) : getcwd();
        if ($root === false || !is_dir($root)) {
            $io->error('Target directory does not exist or is not readable: ' . ($dir ?? getcwd()));
            return Command::FAILURE;
        }

        $io->title('Syntexa project init');
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
            'bin/syntexa' => $this->getBinSyntexaContent(),
            '.gitignore' => $this->getGitignoreContent(),
            'public/.htaccess' => $this->getHtaccessContent(),
            'docker-compose.yml' => $this->getDockerComposeContent(),
            'Dockerfile' => $this->getDockerfileContent(),
            'docs/CONVENTIONS.md' => $this->getConventionsContent(),
            'docs/DEPENDENCIES.md' => $this->getDependenciesContent(),
            'docs/RUNNING.md' => $this->getRunningDocContent(),
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
            if ($relPath === 'bin/syntexa') {
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

        $this->patchComposerAutoload($root, $io, $force);

        $io->success('Project structure created.');
        $io->text([
            'Next steps:',
            '  1. cp .env.example .env',
            '  2. Edit .env (SWOOLE_PORT, etc.) if needed',
            '  3. composer dump-autoload (if autoload was added)',
            '  4. Add your modules under src/modules/',
            '  5. Run: bin/syntexa server:start (Docker)',
        ]);

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
# Syntexa Framework - AI Assistant Entry Point

> **Guiding principle:** Make it work → Make it right → Make it fast.

## Project structure (standalone app)

- **bin/syntexa** – CLI
- **public/** – web root
- **src/** – application code (Request, Handler, Response; see docs/CONVENTIONS.md)
- **src/modules/** – application modules
- **var/log**, **var/cache** – runtime
- **vendor/syntexa/** – framework packages

## Framework docs (in vendor)

- **vendor/syntexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/syntexa/core/docs/attributes/README.md** – attribute index

## Conventions & dependencies

- **docs/CONVENTIONS.md** – namespace (App\), Request/Handler/Response placement, syntexa commands
- **docs/DEPENDENCIES.md** – syntexa/core version, where to find official docs

## Quick start

1. Read this file and docs/CONVENTIONS.md
2. Run: `cp .env.example .env` then `bin/syntexa server:start` (Docker)
3. Default URL: http://0.0.0.0:9501 (see .env SWOOLE_PORT). See docs/RUNNING.md for details.
MD;
    }

    private function getReadmeContent(): string
    {
        return <<<'MD'
# Syntexa App

Minimal Syntexa Framework application.

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
bin/syntexa server:start
```

To stop: `bin/syntexa server:stop`.

Default URL: **http://0.0.0.0:9501** (configurable via `.env` `SWOOLE_PORT`). See **docs/RUNNING.md** for details.

## Structure

- `src/Request/`, `src/Handler/` – example Request→Handler (GET /)
- `src/modules/` – your modules
- `docs/CONVENTIONS.md` – coding conventions
- `docs/DEPENDENCIES.md` – Syntexa version and docs
- `AI_ENTRY.md` – entry point for AI assistants

## Tests

`composer require --dev phpunit/phpunit` then `vendor/bin/phpunit`. See `tests/HomeTest.php` and `phpunit.xml.dist`.
MD;
    }

    private function getConventionsContent(): string
    {
        return <<<'MD'
# Conventions

- **Application namespace:** `App\`
- **Request DTOs:** `src/Request/` or per-module under `src/modules/`; use `#[AsRequest(path: '...', methods: ['GET'])]`
- **Handlers:** `src/Handler/` or per-module; use `#[AsRequestHandler(for: SomeRequest::class)]`; method `handle(RequestInterface $request, ResponseInterface $response): ResponseInterface`
- **Response DTOs:** optional; or return `\Syntexa\Core\Response::json([...])` from handler
- **CLI:** `vendor/bin/syntexa` or `bin/syntexa` – `init`, `server:start`, `server:stop`, etc.
- **Running:** Only via Docker: `bin/syntexa server:start` / `server:stop`. See docs/RUNNING.md.
MD;
    }

    private function getDependenciesContent(): string
    {
        return <<<'MD'
# Syntexa dependencies

- **syntexa/core** – see `composer.json` for version; framework docs in `vendor/syntexa/core/docs/` (attributes, RUNNING.md)
- Running: Docker only – see `docs/RUNNING.md` or `vendor/syntexa/core/docs/RUNNING.md`
- Official repo: check Packagist or GitHub for `syntexa/core`
MD;
    }

    private function getHomeRequestContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Request;

use Syntexa\Core\Attributes\AsRequest;
use Syntexa\Core\Contract\RequestInterface;

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

use Syntexa\Core\Attributes\AsRequestHandler;
use Syntexa\Core\Contract\RequestInterface;
use Syntexa\Core\Contract\ResponseInterface;
use Syntexa\Core\Response;

#[AsRequestHandler(for: \App\Request\HomeRequest::class)]
class HomeHandler
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Response::json([
            'message' => 'Hello from Syntexa!',
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
APP_NAME="Syntexa App"

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

define('SYNTEXA_SWOOLE', true);

require_once __DIR__ . '/vendor/autoload.php';

if (!extension_loaded('swoole')) {
    die("Swoole extension is required.\n");
}

use Swoole\Http\Server;
use Syntexa\Core\Application;
use Syntexa\Core\ErrorHandler;
use Syntexa\Core\Request;

\Syntexa\Core\Container\ContainerFactory::create();
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
        $syntexaRequest = Request::create($request);
        $syntexaResponse = $app->handleRequest($syntexaRequest);
        $response->status($syntexaResponse->getStatusCode());
        foreach ($syntexaResponse->getHeaders() as $name => $value) {
            $response->header($name, $value);
        }
        $response->end($syntexaResponse->getContent());
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

echo "Syntexa server: http://{$env->swooleHost}:{$env->swoolePort}\n";
$server->start();

PHP;
    }

    private function getBinSyntexaContent(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Syntexa\Core\Discovery\AttributeDiscovery;
use Syntexa\Core\Console\Application;

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
# Minimal Syntexa app: PHP + Swoole in Docker.
# Start: bin/syntexa server:start | Stop: bin/syntexa server:stop
services:
  app:
    build: .
    container_name: syntexa-app
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
# Minimal PHP + Swoole for Syntexa (project is mounted at runtime)
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

The **only supported way** to run a Syntexa app is via Docker.

- **Start:** `bin/syntexa server:start` (runs `docker compose up -d`)
- **Stop:** `bin/syntexa server:stop` (runs `docker compose down`)
- **Logs:** `docker compose logs -f`

The app container runs `php server.php` inside Docker; the Swoole server listens on port 9501 (configurable via `.env` `SWOOLE_PORT`). Do not run `php server.php` on the host as the primary way to run the app.
MD;
    }
}
