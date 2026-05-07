<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\LintResourcesCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\BotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedHrefTemplateResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UserResource;
use Symfony\Component\Console\Tester\CommandTester;

final class LintResourcesCommandTest extends TestCase
{
    #[Test]
    public function returns_success_when_all_resources_are_valid(): void
    {
        $discovery = new InMemoryClassDiscovery([
            AddressResource::class,
            PreferencesResource::class,
            ProfileResource::class,
            CustomerResource::class,
            UserResource::class,
            BotResource::class,
            CommentResource::class,
        ]);

        $command = new LintResourcesCommand($discovery);
        $tester  = new CommandTester($command);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Discovered 7', $output);
        self::assertStringContainsString('All 7 Resource DTO declarations are valid', $output);
    }

    #[Test]
    public function returns_failure_with_actionable_message_when_metadata_is_invalid(): void
    {
        $discovery = new InMemoryClassDiscovery([
            AddressResource::class,
            MalformedHrefTemplateResource::class,
        ]);

        $command = new LintResourcesCommand($discovery);
        $tester  = new CommandTester($command);

        $exit = $tester->execute([]);

        self::assertSame(1, $exit, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Found 1 error', $output);
        self::assertStringContainsString('{tenantId}', $output);
    }

    #[Test]
    public function reports_zero_resources_cleanly_when_classmap_is_empty(): void
    {
        $command = new LintResourcesCommand(new InMemoryClassDiscovery([]));
        $tester  = new CommandTester($command);
        $exit    = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Discovered 0', $tester->getDisplay());
    }
}

/**
 * Stub ClassDiscovery that returns a fixed list when asked for ResourceObject classes.
 * The lint command never reads from the live composer classmap during this test.
 */
final class InMemoryClassDiscovery extends ClassDiscovery
{
    /**
     * @param list<class-string> $classes
     */
    public function __construct(private readonly array $classes)
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}
