<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\RoutesListCommand;
use Semitexa\Core\Application\Console\Command\RoutesShowCommand;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * routes:list and routes:show over a fixed set of discovered routes
 * (tk-parity-proof-gaps): what each prints, how routes:show finds a route,
 * and that the two agree on which route an index means. Discovery itself is
 * proven elsewhere; here it is a stand-in returning what it would.
 */
final class RoutesCommandsTest extends TestCase
{
    #[Test]
    public function routes_list_json_names_every_route_with_its_access_and_module(): void
    {
        $rows = $this->json(new RoutesListCommand(), []);

        self::assertCount(3, $rows);
        self::assertSame([
            'index' => 0,
            'path' => '/orders',
            'methods' => ['GET'],
            'name' => 'orders.list',
            'class' => RoutesFixtureListOrders::class,
            'module' => 'Shop',
            'access' => 'protected',
        ], $rows[0]);
        self::assertSame(['POST'], $rows[1]['methods']);
        self::assertSame(['/', 'public', 'project'], [$rows[2]['path'], $rows[2]['access'], $rows[2]['module']], 'a class no module owns belongs to the project');
    }

    /**
     * routes:show takes "the index from routes:list" — and the JSON a script
     * or an agent reads carried no index, so the one way to reach the second
     * of two routes on one path was to read the human table.
     */
    #[Test]
    public function every_index_routes_list_prints_is_the_route_routes_show_shows(): void
    {
        $rows = $this->json(new RoutesListCommand(), []);
        self::assertCount(3, $rows, 'an empty list would compare nothing');
        foreach ($rows as $row) {
            $shown = $this->json(new RoutesShowCommand(), ['id' => (string) $row['index']]);
            self::assertSame([$row['path'], $row['methods'], $row['class']], [$shown['path'], $shown['methods'], $shown['payload']['class']]);
        }
    }

    #[Test]
    public function the_table_is_sorted_by_path_and_keeps_each_routes_index(): void
    {
        $tester = $this->runCommand(new RoutesListCommand(), []);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/\b2\s+GET\s+\/\s.*\n.*\b0\s+GET\s+\/orders\s/s', $display, 'sorted by path, each with the index routes:show takes');
        self::assertStringContainsString('3 route(s) discovered.', $display);
    }

    #[Test]
    public function routes_show_finds_a_route_by_path_or_name_and_lists_its_handlers_by_priority(): void
    {
        $byName = $this->json(new RoutesShowCommand(), ['id' => 'orders.create']);
        $byPath = $this->json(new RoutesShowCommand(), ['id' => '/orders']);

        self::assertSame(RoutesFixtureCreateOrder::class, $byName['payload']['class']);
        self::assertSame(['Shop', 'protected'], [$byName['payload']['module'], $byName['accessType']]);
        self::assertSame([RoutesFixtureAuditHandler::class, RoutesFixtureCreateHandler::class], array_column($byName['handlers'], 'class'), 'highest priority first');
        self::assertSame(['queued', 'audit', 5], [$byName['handlers'][0]['execution'], $byName['handlers'][0]['queue'], $byName['handlers'][0]['maxRetries']]);
        self::assertSame(['class' => RoutesFixtureOrderResource::class, 'handle' => 'shop.order'], array_intersect_key((array) $byName['resource'], ['class' => 1, 'handle' => 1]));
        self::assertSame(RoutesFixtureListOrders::class, $byPath['payload']['class']);
        self::assertSame(__FILE__, $byPath['payload']['file']);
    }

    #[Test]
    public function routes_show_says_so_when_nothing_matches(): void
    {
        $tester = $this->runCommand(new RoutesShowCommand(), ['id' => '/nowhere']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Route not found: /nowhere', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<mixed>
     */
    private function json(Command $command, array $input): array
    {
        $tester = $this->runCommand($command, $input + ['--json' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'not JSON: ' . $tester->getDisplay());

        return $decoded;
    }

    /** @param array<string, mixed> $input */
    private function runCommand(Command $command, array $input): CommandTester
    {
        (new \ReflectionProperty($command, 'attributeDiscovery'))->setValue($command, new RoutesFixtureDiscovery());
        (new \ReflectionProperty($command, 'moduleRegistry'))->setValue($command, new RoutesFixtureModules());
        $tester = new CommandTester($command);
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }
}

/** What discovery returns for three routes; two share a path. */
final class RoutesFixtureDiscovery extends AttributeDiscovery
{
    public function __construct()
    {
    }

    public function getRoutes(): array
    {
        return [
            ['path' => '/orders', 'methods' => ['GET'], 'name' => 'orders.list', 'class' => RoutesFixtureListOrders::class, 'accessType' => PayloadAccessType::Protected],
            ['path' => '/orders', 'methods' => ['POST'], 'name' => 'orders.create', 'class' => RoutesFixtureCreateOrder::class, 'accessType' => PayloadAccessType::Protected],
            ['path' => '/', 'methods' => ['GET'], 'name' => null, 'class' => 'App\\Home\\HomePayload', 'accessType' => 'public'],
        ];
    }

    public function findRoute(string $path, string $method = 'GET'): ?array
    {
        if ($path === '/orders' && $method === 'POST') {
            return [
                'responseClass' => RoutesFixtureOrderResource::class,
                'handlers' => [
                    ['class' => RoutesFixtureCreateHandler::class, 'priority' => 0],
                    ['class' => RoutesFixtureAuditHandler::class, 'priority' => 10, 'execution' => 'queued', 'queue' => 'audit', 'maxRetries' => 5],
                ],
            ];
        }

        return null;
    }

    public function getResolvedResponseAttributes(string $class): ?array
    {
        return $class === RoutesFixtureOrderResource::class ? ['handle' => 'shop.order'] : null;
    }
}

final class RoutesFixtureModules extends ModuleRegistry
{
    public function __construct()
    {
    }

    public function getModuleNameForClass(string $className): ?string
    {
        return str_starts_with($className, __NAMESPACE__ . '\\RoutesFixture') ? 'Shop' : null;
    }
}

final class RoutesFixtureListOrders {}
final class RoutesFixtureCreateOrder {}
final class RoutesFixtureOrderResource {}
final class RoutesFixtureCreateHandler {}
final class RoutesFixtureAuditHandler {}
