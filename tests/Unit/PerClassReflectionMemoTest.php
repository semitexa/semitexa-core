<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Attribute\SessionSegment;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Support\PayloadSerializer;

/**
 * The request path reads class attributes and setter signatures through
 * per-class memos (PayloadHydrator path-param plan, Session segment name,
 * PayloadSerializer setter handles). They hold metadata only, keyed by class:
 * every call must still produce the answer for ITS OWN input, and a class
 * the memo has seen must not bleed into another class.
 */
final class PerClassReflectionMemoTest extends TestCase
{
    #[Test]
    public function path_param_plan_is_reused_but_each_request_gets_its_own_values(): void
    {
        $first = PayloadHydrator::hydrate(new MemoItemPayload(), $this->get('/memo/items/12/abc'));
        $second = PayloadHydrator::hydrate(new MemoItemPayload(), $this->get('/memo/items/7/x%2Fy'));
        $miss = PayloadHydrator::hydrate(new MemoItemPayload(), $this->get('/memo/items/not-a-number/z'));

        self::assertSame(['12', 'abc'], [$first->id, $first->slug]);
        self::assertSame(['7', 'x/y'], [$second->id, $second->slug]);
        // The `id` requirement (\d+) is part of the cached regex.
        self::assertSame([null, null], [$miss->id, $miss->slug]);
    }

    #[Test]
    public function path_param_plan_is_per_class(): void
    {
        PayloadHydrator::hydrate(new MemoItemPayload(), $this->get('/memo/items/1/a'));
        $other = PayloadHydrator::hydrate(new MemoOtherPayload(), $this->get('/memo/other/b'));
        $plain = PayloadHydrator::hydrate(new MemoPlainPayload(), $this->get('/memo/items/1/a'));

        self::assertSame('b', $other->slug);
        self::assertNull($plain->slug, 'a route without parameters hydrates no path value');
    }

    #[Test]
    public function session_segment_name_is_per_class_and_a_missing_attribute_still_throws_every_time(): void
    {
        $session = new Session('0123456789abcdef0123456789abcdef', new MemoArraySessionHandler(), 'sid');

        $a = new MemoSegmentA();
        $a->setValue('one');
        $session->setPayload($a);
        $b = new MemoSegmentB();
        $b->setValue('two');
        $session->setPayload($b);

        self::assertSame('one', $session->getPayload(MemoSegmentA::class)->getValue());
        self::assertSame('two', $session->getPayload(MemoSegmentB::class)->getValue());
        self::assertSame('one', $session->get('memo_a')['value'] ?? null);
        self::assertSame('two', $session->get('memo_b')['value'] ?? null);

        for ($i = 0; $i < 2; $i++) {
            try {
                $session->getPayload(MemoPlainPayload::class);
                self::fail('a class without #[SessionSegment] must be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function serializer_setter_handles_are_reused_but_values_are_not(): void
    {
        $first = PayloadSerializer::hydrate(new MemoSegmentA(), ['value' => 'first', 'unknown' => 'x']);
        $second = PayloadSerializer::hydrate(new MemoSegmentA(), ['value' => 'second', 'unknown' => 'y']);

        self::assertSame('first', $first->getValue());
        self::assertSame('second', $second->getValue());
    }

    #[Test]
    public function serializer_memo_stays_bounded_under_unknown_keys(): void
    {
        $payload = [];
        for ($i = 0; $i < 1000; $i++) {
            $payload['junk_' . $i] = $i;
        }
        $payload['value'] = 'kept';

        $dto = PayloadSerializer::hydrate(new MemoSegmentB(), $payload);

        self::assertSame('kept', $dto->getValue(), 'a key past the memo bound still hydrates');
        $setters = (new \ReflectionProperty(PayloadSerializer::class, 'setters'))->getValue();
        self::assertLessThanOrEqual(256, count($setters[MemoSegmentB::class] ?? []));
    }

    private function get(string $uri): Request
    {
        return new Request(method: 'GET', uri: $uri, headers: [], query: [], post: [], server: [], cookies: []);
    }
}

/** @internal */
#[AsPublicPayload(path: '/memo/items/{id}/{slug}', requirements: ['id' => '\d+'])]
final class MemoItemPayload
{
    public ?string $id = null;
    public ?string $slug = null;

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
}

/** @internal */
#[AsPublicPayload(path: '/memo/other/{slug}')]
final class MemoOtherPayload
{
    public ?string $slug = null;

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
}

/** @internal */
#[AsPublicPayload(path: '/memo/plain')]
final class MemoPlainPayload
{
    public ?string $slug = null;

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
}

/** @internal */
#[SessionSegment('memo_a')]
final class MemoSegmentA
{
    private string $value = '';

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}

/** @internal */
#[SessionSegment('memo_b')]
final class MemoSegmentB
{
    private string $value = '';

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}

/** @internal */
final class MemoArraySessionHandler implements SessionHandlerInterface
{
    public function read(string $sessionId): array
    {
        return [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
    }

    public function destroy(string $sessionId): void
    {
    }
}
