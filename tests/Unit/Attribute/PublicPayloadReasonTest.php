<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Auth\PayloadAccessType;

/**
 * A public route can say why it is public (`reason`), which the lints that
 * question a public page reading identity, or one on a private-sounding path,
 * accept. The parameter is the last one, so no existing argument moves.
 */
final class PublicPayloadReasonTest extends TestCase
{
    #[Test]
    public function a_public_route_carries_its_reason_and_everything_else_as_before(): void
    {
        $route = $this->route(PublicPayloadReasonFixture::class);

        self::assertSame('sign-in page: greets a returning visitor by name', $route->reason);
        self::assertSame('/sign-in', $route->path);
        self::assertSame(['GET'], $route->methods);
        self::assertSame(PayloadAccessType::Public, $route->getAccessType());
    }

    #[Test]
    public function a_route_without_one_has_none(): void
    {
        self::assertNull($this->route(PublicPayloadWithoutReasonFixture::class)->reason);
    }

    #[Test]
    public function reason_is_the_last_constructor_parameter(): void
    {
        $parameters = (new \ReflectionMethod(AbstractPayloadRoute::class, '__construct'))->getParameters();
        $last = end($parameters);
        self::assertInstanceOf(\ReflectionParameter::class, $last, 'the constructor has parameters');

        self::assertSame('reason', $last->getName());
        self::assertSame('doc', $parameters[0]->getName(), 'positional callers keep their order');
    }

    /** @param class-string $class */
    private function route(string $class): AbstractPayloadRoute
    {
        $attribute = (new \ReflectionClass($class))->getAttributes(AbstractPayloadRoute::class, \ReflectionAttribute::IS_INSTANCEOF)[0];

        return $attribute->newInstance();
    }
}

#[AsPublicPayload(path: '/sign-in', methods: ['GET'], reason: 'sign-in page: greets a returning visitor by name')]
final class PublicPayloadReasonFixture
{
}

#[AsPublicPayload(path: '/about', methods: ['GET'])]
final class PublicPayloadWithoutReasonFixture
{
}
