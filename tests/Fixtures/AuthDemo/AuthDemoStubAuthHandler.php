<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Auth\Attribute\AsAuthHandler;
use Semitexa\Auth\Domain\Contract\AuthHandlerInterface;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;

/**
 * Authenticates from one request header: `user:<id>` as a user, `service:<id>`
 * as a service principal. No header, no opinion.
 *
 * It stays out of webhook receivers entirely: those authenticate by signature
 * only, and a generic service token must not get past WebhookAuthHandler.
 */
#[AsAuthHandler(priority: 1)]
final class AuthDemoStubAuthHandler implements AuthHandlerInterface
{
    public const HEADER = 'X-Core-Fixture-Auth';
    public const USER_PREFIX = 'user:';
    public const SERVICE_PREFIX = 'service:';

    public function handle(object $payload): ?AuthResult
    {
        if ((new \ReflectionClass($payload))->getAttributes(AsWebhookReceiver::class) !== []) {
            return null;
        }

        $token = trim((string) CurrentRequestStore::get()?->getHeader(self::HEADER));
        if (str_starts_with($token, self::USER_PREFIX) && strlen($token) > strlen(self::USER_PREFIX)) {
            return AuthResult::successAsUser(new AuthDemoUser(substr($token, strlen(self::USER_PREFIX))));
        }
        if (str_starts_with($token, self::SERVICE_PREFIX) && strlen($token) > strlen(self::SERVICE_PREFIX)) {
            return AuthResult::successAsService(new AuthDemoServicePrincipal(substr($token, strlen(self::SERVICE_PREFIX))));
        }

        return null;
    }
}
