<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;

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
