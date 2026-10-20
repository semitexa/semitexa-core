<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Console\Runtime\StartRuntimeAction;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The PHP server:start (vendor/bin/semitexa on the host) chooses the same
 * compose files as bin/semitexa: the worker's own file whenever it is there
 * (tk-pip-no-queue-worker, tk-worker-runs-mail-work), and the values the overlays give
 * the app, written for the worker to var/run/worker.env.
 */
final class StartRuntimeActionTest extends TestCase
{
    private const ENV = ['EVENTS_ASYNC', 'DB_DRIVER', 'REDIS_HOST', 'LLM_PROVIDER'];

    private string $root;

    /** @var array<string, string|false> */
    private array $env = [];

    private ?string $previousRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/start-runtime-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        foreach (['docker-compose.yml', 'docker-compose.nats.yml', 'docker-compose.mysql.yml', 'docker-compose.redis.yml', 'docker-compose.ollama.yml', 'docker-compose.worker.yml'] as $file) {
            touch($this->root . '/' . $file);
        }
        $previous = (new \ReflectionProperty(ProjectRoot::class, 'root'))->getValue();
        $this->previousRoot = is_string($previous) ? $previous : null;
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->root);
        foreach (self::ENV as $key) {
            $this->env[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->previousRoot);
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function with_async_events_the_worker_file_is_loaded_and_given_what_the_overlays_give_the_app(): void
    {
        $this->environment(['EVENTS_ASYNC' => '1', 'DB_DRIVER' => 'mysql', 'REDIS_HOST' => 'redis', 'LLM_PROVIDER' => 'ollama']);
        $action = $this->action();

        self::assertSame(
            ['-f', 'docker-compose.yml', '-f', 'docker-compose.worker.yml', '-f', 'docker-compose.nats.yml', '-f', 'docker-compose.mysql.yml', '-f', 'docker-compose.redis.yml', '-f', 'docker-compose.ollama.yml'],
            $action->getComposeArgs(),
        );

        $action->writeWorkerEnvFile();

        self::assertSame(
            "# Written by semitexa server:start: the services the overlays give the app.\nDB_HOST=mysql\nDB_PORT=3306\nREDIS_HOST=redis\nREDIS_PORT=6379\nNATS_PRIMARY_URL=nats://nats:4222\nLLM_BASE_URL=http://ollama:11434\n",
            file_get_contents($this->root . '/var/run/worker.env'),
        );
    }

    #[Test]
    public function without_async_events_the_worker_still_runs_for_the_scheduler_and_webhooks(): void
    {
        $this->environment(['EVENTS_ASYNC' => '0', 'DB_DRIVER' => 'mysql', 'REDIS_HOST' => '', 'LLM_PROVIDER' => '']);
        $action = $this->action();

        self::assertSame(['-f', 'docker-compose.yml', '-f', 'docker-compose.worker.yml', '-f', 'docker-compose.mysql.yml'], $action->getComposeArgs());
        self::assertSame(['DB_HOST' => 'mysql', 'DB_PORT' => '3306'], $action->workerEnvironment());
    }

    #[Test]
    public function a_project_without_the_worker_file_starts_as_before(): void
    {
        unlink($this->root . '/docker-compose.worker.yml');
        $this->environment(['EVENTS_ASYNC' => '1', 'DB_DRIVER' => 'sqlite', 'REDIS_HOST' => '', 'LLM_PROVIDER' => '']);

        self::assertSame(['-f', 'docker-compose.yml', '-f', 'docker-compose.nats.yml'], $this->action()->getComposeArgs());
    }

    /** @param array<string, string> $values */
    private function environment(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    private function action(): StartRuntimeAction
    {
        return new StartRuntimeAction(new SymfonyStyle(new ArrayInput([]), new NullOutput()));
    }
}
