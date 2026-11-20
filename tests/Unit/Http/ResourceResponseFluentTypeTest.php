<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A fluent setter of ResourceResponse returns the resource it was called on,
 * as its own class: a handler typed to its resource returns the call.
 *
 * The fresh-project make-it run of 19.11 wrote `return $resource->setRedirect(...)`
 * in a handler typed to its resource; phpstan saw ResourceResponse and refused
 * it (return.type), and the agent spent a verify on it
 * (tk-resource-response-setters-return-self).
 */
final class ResourceResponseFluentTypeTest extends TypeInferenceTestCase
{
    private const TYPES = __DIR__ . '/Fixtures/FluentPageResourceTypes.php';

    /** @return iterable<mixed> */
    public static function fluentSetters(): iterable
    {
        yield from self::gatherAssertTypes(self::TYPES);
    }

    #[Test]
    #[DataProvider('fluentSetters')]
    public function a_fluent_setter_returns_the_resource_it_was_called_on(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    #[Test]
    public function every_fluent_setter_is_asserted(): void
    {
        // A fixture that lost its assertions would leave the data provider
        // above checking fewer setters, not failing.
        $setters = [];
        foreach ((new \ReflectionClass(\Semitexa\Core\Http\Response\ResourceResponse::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'self') {
                $setters[] = $method->getName();
            }
        }
        sort($setters);

        $asserted = [];
        foreach (self::gatherAssertTypes(self::TYPES) as $assert) {
            $asserted[] = $assert;
        }
        self::assertCount(count($setters), $asserted, 'one assertType per fluent setter: ' . implode(', ', $setters));
    }
}
