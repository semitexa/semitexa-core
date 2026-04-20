<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Request;

final class PayloadHydratorTest extends TestCase
{
    #[Test]
    public function hydrate_ignores_non_string_query_keys(): void
    {
        $dto = new class {
            public ?string $name = null;

            public function setName(string $name): void
            {
                $this->name = $name;
            }
        };

        $request = new Request(
            'GET',
            '/demo',
            [],
            [0 => 'bad', 'name' => 'alice'],
            [],
            [],
            [],
        );

        $hydrated = PayloadHydrator::hydrate($dto, $request);

        self::assertSame('alice', $hydrated->name);
    }
}
