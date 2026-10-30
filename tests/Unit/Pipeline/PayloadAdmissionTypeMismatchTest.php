<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\PayloadValidationException;
use Semitexa\Core\Pipeline\PayloadAdmission;
use Semitexa\Core\Request;

/**
 * A payload with several badly typed fields answers with all of them in one
 * 422: the admission mapped only the first, one field per round trip.
 */
final class PayloadAdmissionTypeMismatchTest extends TestCase
{
    #[Test]
    public function every_badly_typed_field_is_in_the_one_validation_error(): void
    {
        $payload = new class {
            public function setCount(int $value): void {}

            public function setPrice(float $value): void {}
        };
        $request = new Request(
            method: 'POST',
            uri: '/demo',
            headers: ['Content-Type' => 'application/json'],
            query: [],
            post: [],
            server: [],
            cookies: [],
            content: (string) json_encode(['count' => 'many', 'price' => 'cheap']),
            strictHydration: true,
        );
        $admission = (new \ReflectionClass(PayloadAdmission::class))->newInstanceWithoutConstructor();

        $result = (new \ReflectionMethod(PayloadAdmission::class, 'fillAndValidatePayload'))->invoke($admission, $payload, $request);
        self::assertIsArray($result);
        $error = $result[1] ?? null;

        self::assertInstanceOf(PayloadValidationException::class, $error);
        self::assertSame([
            'count' => ["Field 'count': expected int, received string."],
            'price' => ["Field 'price': expected float, received string."],
        ], $error->getErrors());
    }
}
