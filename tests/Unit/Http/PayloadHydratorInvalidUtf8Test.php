<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Exception\TypeMismatchException;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\RequestFactory;
use Semitexa\Core\Tests\Unit\Http\Fixtures\HydratorTextFixturePayload;

/**
 * A byte that is not text passed the hydrator and failed later, as a 500 from
 * the database or the JSON encoder. It stops here, as a client's error.
 */
final class PayloadHydratorInvalidUtf8Test extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badInput(): iterable
    {
        yield 'a string field' => [['name' => "bad\xFF"], 'name'];
        yield 'a truncated sequence' => [['name' => "\xC3("], 'name'];
        yield 'inside an array' => [['tags' => ['ok', "bad\xFF"]], 'tags'];
        yield 'an array key' => [['tags' => ["bad\xFF" => 'ok']], 'tags'];
    }

    /** @param array<string, mixed> $post */
    #[Test]
    #[DataProvider('badInput')]
    public function it_is_refused_as_the_client_s_error(array $post, string $field): void
    {
        try {
            PayloadHydrator::hydrate(new HydratorTextFixturePayload(), RequestFactory::fromArray(['method' => 'POST', 'uri' => '/x', 'post' => $post]));
            self::fail('the bytes reached the payload');
        } catch (TypeMismatchException $refused) {
            self::assertSame($field, $refused->field);
            self::assertNotFalse(json_encode($refused->getMessage()), 'the message goes back as JSON');
            self::assertNotFalse(json_encode($refused->receivedValue), 'so does the value');
        }
    }

    #[Test]
    public function real_text_passes_untouched(): void
    {
        $payload = new HydratorTextFixturePayload();

        PayloadHydrator::hydrate($payload, RequestFactory::fromArray(['method' => 'POST', 'uri' => '/x', 'post' => ['name' => 'Тарас 🙂', 'tags' => ['ñ']]]));

        self::assertSame('Тарас 🙂', $payload->name);
        self::assertSame(['ñ'], $payload->tags);
    }
}
