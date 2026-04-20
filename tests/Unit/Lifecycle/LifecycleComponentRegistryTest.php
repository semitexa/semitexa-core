<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\AuthenticationMode;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Lifecycle\LifecycleComponentRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;

final class LifecycleComponentRegistryTest extends TestCase
{
    #[Test]
    public function tenancy_adapter_accepts_legacy_bootstrapper_handlers(): void
    {
        $registry = $this->makeRegistry();
        /** @var TenancyBootstrapperInterface|null $adapter */
        $adapter = $this->invokePrivate(
            $registry,
            'adaptTenancyBootstrapper',
            new class {
                public function isEnabled(): bool
                {
                    return true;
                }

                public function getHandler(): object
                {
                    return new class {
                        public function handle(Request $request): \Semitexa\Core\HttpResponse
                        {
                            return \Semitexa\Core\HttpResponse::html('legacy:' . $request->getPath(), 202);
                        }
                    };
                }
            },
        );

        self::assertNotNull($adapter);
        self::assertTrue($adapter->isEnabled());

        $response = $adapter->resolve(new Request('GET', '/tenant', [], [], [], [], []));

        self::assertNotNull($response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('legacy:/tenant', $response->getContent());
    }

    #[Test]
    public function auth_adapter_calls_legacy_handle_without_mode_argument(): void
    {
        $registry = $this->makeRegistry();
        /** @var AuthBootstrapperInterface|null $adapter */
        $adapter = $this->invokePrivate(
            $registry,
            'adaptAuthBootstrapper',
            new class {
                public object $lastPayload;

                public function isEnabled(): bool
                {
                    return true;
                }

                public function handle(object $payload): AuthResult
                {
                    $this->lastPayload = $payload;

                    return AuthResult::failed('legacy');
                }
            },
        );

        self::assertNotNull($adapter);
        $payload = new \stdClass();
        $result = $adapter->handle($payload);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame('legacy', $result->reason);
    }

    #[Test]
    public function auth_adapter_translates_optional_string_mode_parameter(): void
    {
        $registry = $this->makeRegistry();
        /** @var AuthBootstrapperInterface|null $adapter */
        $adapter = $this->invokePrivate(
            $registry,
            'adaptAuthBootstrapper',
            new class {
                public function isEnabled(): bool
                {
                    return true;
                }

                public function handle(object $payload, string $mode = 'legacy'): AuthResult
                {
                    return AuthResult::failed($mode);
                }
            },
        );

        self::assertNotNull($adapter);

        $result = $adapter->handle(new \stdClass(), AuthenticationMode::BestEffort);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame('BestEffort', $result->reason);
    }

    #[Test]
    public function auth_adapter_translates_required_string_mode_parameter(): void
    {
        $registry = $this->makeRegistry();
        /** @var AuthBootstrapperInterface|null $adapter */
        $adapter = $this->invokePrivate(
            $registry,
            'adaptAuthBootstrapper',
            new class {
                public function isEnabled(): bool
                {
                    return true;
                }

                public function handle(object $payload, string $mode): AuthResult
                {
                    return AuthResult::failed($mode);
                }
            },
        );

        self::assertNotNull($adapter);

        $result = $adapter->handle(new \stdClass(), AuthenticationMode::BestEffort);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame('BestEffort', $result->reason);
    }

    #[Test]
    public function auth_adapter_translates_required_legacy_enum_mode_parameter(): void
    {
        $registry = $this->makeRegistry();
        /** @var AuthBootstrapperInterface|null $adapter */
        $adapter = $this->invokePrivate(
            $registry,
            'adaptAuthBootstrapper',
            new class {
                public function isEnabled(): bool
                {
                    return true;
                }

                public function handle(object $payload, LegacyAuthenticationMode $mode): AuthResult
                {
                    return AuthResult::failed($mode->name);
                }
            },
        );

        self::assertNotNull($adapter);

        $result = $adapter->handle(new \stdClass(), AuthenticationMode::BestEffort);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame('BestEffort', $result->reason);
    }

    private function makeRegistry(): LifecycleComponentRegistry
    {
        $moduleRegistry = $this->createMock(ModuleRegistry::class);
        $moduleRegistry->method('isActive')->willReturn(true);

        return new LifecycleComponentRegistry($moduleRegistry);
    }

    private function invokePrivate(object $target, string $method, object $argument): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, $argument);
    }
}

enum LegacyAuthenticationMode
{
    case Mandatory;
    case BestEffort;
}
