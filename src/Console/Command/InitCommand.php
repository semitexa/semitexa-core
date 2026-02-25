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
 * docker-compose.yml, Dockerfile, AI_ENTRY.md, docs/AI_CONTEXT.md, README.md, var/docs (AI working dir only), phpunit.xml.dist, autoload in composer.json.
 * Framework docs (CONVENTIONS, RUNNING, ADDING_ROUTES) live in vendor/semitexa/core/docs/ — not written into project.
 */
class InitCommand extends Command
{
    /** Default Swoole port (single source of truth for .env.example, docker-compose, docs). */
    private const DEFAULT_SWOOLE_PORT = 9502;

    private const PLACEHOLDER_PORT = '{{ default_swoole_port }}';

    /** Path to resources/init/ (templates for scaffold). */
    private function getInitResourcesDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/resources/init';
        return is_dir($dir) ? $dir : '';
    }

    private function readTemplate(string $filename): string
    {
        $dir = $this->getInitResourcesDir();
        $path = $dir !== '' ? $dir . '/' . $filename : '';
        if ($path !== '' && is_readable($path)) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Init template could not be read: {$filename}");
            }
            return str_replace(self::PLACEHOLDER_PORT, (string) self::DEFAULT_SWOOLE_PORT, $content);
        }
        throw new \RuntimeException("Init template missing: {$filename} (expected in resources/init/). Reinstall semitexa/core.");
    }

    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Create Semitexa project structure + AI_ENTRY, README, var/docs, phpunit.xml.dist')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Target directory (default: current working directory)', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('only-docs', null, InputOption::VALUE_NONE, 'Sync docs and scaffold (AI_ENTRY, README, server.php, .env.example, Dockerfile, docker-compose, phpunit, bin/semitexa, .gitignore) — for existing projects after composer update');
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
            'var/run',
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
        foreach (['var/cache', 'var/log', 'var/docs', 'var/run'] as $path) {
            $gitkeep = $root . '/' . $path . '/.gitkeep';
            if (!file_exists($gitkeep)) {
                file_put_contents($gitkeep, '');
            }
        }

        $created = [];
        $skipped = [];

        $files = [
            'AI_ENTRY.md' => $this->getAiEntryContent(),
            'docs/AI_CONTEXT.md' => $this->getAiContextContent(),
            'README.md' => $this->getReadmeContent(),
            '.env.example' => $this->getEnvExampleContent(),
            'server.php' => $this->getServerPhpContent(),
            'bin/semitexa' => $this->getBinSemitexaContent(),
            '.gitignore' => $this->getGitignoreContent(),
            'public/.htaccess' => $this->getHtaccessContent(),
            'docker-compose.yml' => $this->getDockerComposeContent(),
            'docker-compose.rabbitmq.yml' => $this->getDockerComposeRabbitMqContent(),
            'docker-compose.mysql.yml' => $this->getDockerComposeMysqlContent(),
            'docker-compose.redis.yml' => $this->getDockerComposeRedisContent(),
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
     * Sync docs and scaffold from framework template (for existing projects after composer update).
     * Updates: AI_ENTRY, docs/AI_CONTEXT, README, server.php, .env.example, Dockerfile, docker-compose.yml, phpunit.xml.dist, bin/semitexa, .gitignore.
     */
    private function executeOnlyDocs(string $root, SymfonyStyle $io, bool $force): int
    {
        $io->title('Semitexa docs & scaffold sync');
        $io->text('Project root: ' . $root);

        $syncFiles = [
            'AI_ENTRY.md' => $this->getAiEntryContent(),
            'docs/AI_CONTEXT.md' => $this->getAiContextContent(),
            'README.md' => $this->getReadmeContent(),
            'server.php' => $this->getServerPhpContent(),
            '.env.example' => $this->getEnvExampleContent(),
            'Dockerfile' => $this->getDockerfileContent(),
            'docker-compose.yml' => $this->getDockerComposeContent(),
            'docker-compose.rabbitmq.yml' => $this->getDockerComposeRabbitMqContent(),
            'docker-compose.mysql.yml' => $this->getDockerComposeMysqlContent(),
            'docker-compose.redis.yml' => $this->getDockerComposeRedisContent(),
            'phpunit.xml.dist' => $this->getPhpunitXmlContent(),
            'bin/semitexa' => $this->getBinSemitexaContent(),
            '.gitignore' => $this->getGitignoreContent(),
        ];

        $written = [];
        $skipped = [];
        foreach ($syncFiles as $relPath => $content) {
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
            $written[] = $relPath;
        }

        foreach ($written as $f) {
            $io->text('Written: ' . $f);
        }
        foreach ($skipped as $f) {
            $io->note('Skipped (exists): ' . $f . ' (use --force to overwrite)');
        }

        $io->success('Docs and scaffold (AI_ENTRY, docs/AI_CONTEXT, README, server.php, .env.example, Dockerfile, docker-compose (+ mysql, redis, rabbitmq overlays), phpunit, bin/semitexa, .gitignore) synced from framework.');
        $io->text('.env is never touched. Copy new vars from .env.example to .env if needed.');
        return Command::SUCCESS;
    }

    private function patchComposerAutoload(string $root, SymfonyStyle $io, bool $force): void
    {
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            return;
        }
        $fileContent = file_get_contents($path);
        if ($fileContent === false) {
            return;
        }
        $json = json_decode($fileContent, true);
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
        return $this->readTemplate('AI_ENTRY.md');
    }

    private function getAiContextContent(): string
    {
        return $this->readTemplate('docs/AI_CONTEXT.md');
    }

    private function getReadmeContent(): string
    {
        return $this->readTemplate('README.md');
    }

    private function getPhpunitXmlContent(): string
    {
        return $this->readTemplate('phpunit.xml.dist');
    }

    private function getEnvExampleContent(): string
    {
        return $this->readTemplate('env.example');
    }

    private function getServerPhpContent(): string
    {
        return $this->readTemplate('server.php');
    }

    private function getBinSemitexaContent(): string
    {
        return $this->readTemplate('bin-semitexa');
    }

    private function getGitignoreContent(): string
    {
        return $this->readTemplate('gitignore');
    }

    private function getHtaccessContent(): string
    {
        return $this->readTemplate('htaccess');
    }

    private function getDockerComposeRabbitMqContent(): string
    {
        $dir = $this->getInitResourcesDir();
        $path = $dir !== '' ? $dir . '/docker-compose.rabbitmq.yml' : '';
        if ($path !== '' && is_readable($path)) {
            $content = file_get_contents($path);
            return $content !== false ? $content : '';
        }
        return "# Optional RabbitMQ overlay. When EVENTS_ASYNC=1, use: docker compose -f docker-compose.yml -f docker-compose.rabbitmq.yml up -d\n"
            . "services:\n  app:\n    environment:\n      RABBITMQ_HOST: rabbitmq\n    depends_on:\n      rabbitmq:\n        condition: service_healthy\n"
            . "  rabbitmq:\n    image: rabbitmq:3-alpine\n    restart: unless-stopped\n    healthcheck:\n      test: [\"CMD\", \"rabbitmq-diagnostics\", \"-q\", \"ping\"]\n      interval: 30s\n      timeout: 10s\n      retries: 5\n      start_period: 30s\n";
    }

    private function getDockerComposeMysqlContent(): string
    {
        $dir = $this->getInitResourcesDir();
        $path = $dir !== '' ? $dir . '/docker-compose.mysql.yml' : '';
        if ($path !== '' && is_readable($path)) {
            $content = file_get_contents($path);
            return $content !== false ? $content : '';
        }
        return "# Optional: MySQL for ORM. Used automatically when DB_DRIVER is set in .env.\n"
            . "# Start: docker compose -f docker-compose.yml -f docker-compose.mysql.yml up -d\n"
            . "# Or: bin/semitexa server:start (uses this file automatically when DB_DRIVER is set).\n"
            . "services:\n  app:\n    environment:\n      DB_HOST: mysql\n    depends_on:\n      mysql:\n        condition: service_healthy\n"
            . "\n  mysql:\n    image: mysql:8.4\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: \${DB_PASSWORD:-root}\n      MYSQL_DATABASE: \${DB_DATABASE:-semitexa}\n"
            . "    ports:\n      - \"\${DB_PORT:-3307}:3306\"\n    volumes:\n      - mysql_data:/var/lib/mysql\n"
            . "    healthcheck:\n      test: [\"CMD\", \"mysqladmin\", \"ping\", \"-h\", \"localhost\"]\n      interval: 10s\n      timeout: 5s\n      retries: 5\n      start_period: 30s\n"
            . "\nvolumes:\n  mysql_data:\n";
    }

    private function getDockerComposeRedisContent(): string
    {
        $dir = $this->getInitResourcesDir();
        $path = $dir !== '' ? $dir . '/docker-compose.redis.yml' : '';
        if ($path !== '' && is_readable($path)) {
            $content = file_get_contents($path);
            return $content !== false ? $content : '';
        }
        return "# Optional: Redis for cache/sessions. Used when REDIS_HOST is set in .env.\n"
            . "# Start: docker compose -f docker-compose.yml -f docker-compose.redis.yml up -d\n"
            . "# Or: bin/semitexa server:start (uses this file automatically when REDIS_HOST is set).\n"
            . "services:\n  app:\n    environment:\n      REDIS_HOST: redis\n    depends_on:\n      redis:\n        condition: service_healthy\n"
            . "\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n"
            . "    ports:\n      - \"\${REDIS_PORT:-6379}:6379\"\n    volumes:\n      - redis_data:/data\n"
            . "    healthcheck:\n      test: [\"CMD\", \"redis-cli\", \"ping\"]\n      interval: 10s\n      timeout: 5s\n      retries: 5\n      start_period: 10s\n"
            . "\nvolumes:\n  redis_data:\n";
    }

    private function getDockerComposeContent(): string
    {
        $dir = $this->getInitResourcesDir();
        $templatePath = $dir !== '' ? $dir . '/docker-compose.yml' : '';
        if ($templatePath === '' || !is_readable($templatePath)) {
            $port = self::DEFAULT_SWOOLE_PORT;
            return "# Minimal Semitexa app: PHP + Swoole in Docker.\n"
                . "# Start: bin/semitexa server:start | Stop: bin/semitexa server:stop\n"
                . "# When EVENTS_ASYNC=1, use docker-compose.rabbitmq.yml (server:start does this automatically).\n"
                . "services:\n  app:\n    build: .\n    env_file: .env\n"
                . "    volumes:\n      - .:/var/www/html\n    ports:\n"
                . "      - \"\${SWOOLE_PORT:-{$port}}:\${SWOOLE_PORT:-{$port}}\"\n"
                . "    restart: unless-stopped\n    command: [\"php\", \"server.php\"]\n";
        }
        $content = file_get_contents($templatePath);
        return $content !== false 
            ? str_replace(self::PLACEHOLDER_PORT, (string) self::DEFAULT_SWOOLE_PORT, $content)
            : '';
    }

    private function getDockerfileContent(): string
    {
        return $this->readTemplate('Dockerfile');
    }

    private function getAiNotesStubContent(): string
    {
        return $this->readTemplate('AI_NOTES.md');
    }
}
