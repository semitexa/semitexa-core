<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\PayloadSerializer;
use ValueError;

final class PayloadSerializerTest extends TestCase
{
    #[Test]
    public function plain_payload_serializes_exactly_as_before(): void
    {
        $dto = new SerializerPlainFixture();
        $dto->setTitle('Report');
        $dto->setCount(3);

        self::assertSame(['title' => 'Report', 'count' => 3], PayloadSerializer::toArray($dto));
    }

    #[Test]
    public function backed_enum_and_dates_serialize_to_scalars(): void
    {
        $data = PayloadSerializer::toArray(self::filledFixture());

        self::assertSame('high', $data['priority']);
        self::assertSame('2026-09-25T10:15:30+02:00', $data['dueAt']);
        self::assertSame('2026-09-24T08:00:00+00:00', $data['createdAt']);
        self::assertSame('2026-09-23T07:00:00+00:00', $data['seenAt']);
    }

    #[Test]
    public function bool_is_getter_with_matching_setter_is_serialized(): void
    {
        $data = PayloadSerializer::toArray(self::filledFixture());

        self::assertTrue($data['urgent']);
        // No setDraft(): a read-only is*() is not part of the payload.
        self::assertArrayNotHasKey('draft', $data);
    }

    #[Test]
    public function enum_dates_and_bool_flag_survive_a_json_round_trip(): void
    {
        // Queue and session payloads travel as JSON between toArray() and hydrate().
        $wire = json_decode(json_encode(PayloadSerializer::toArray(self::filledFixture()), JSON_THROW_ON_ERROR), true);

        $dto = PayloadSerializer::hydrate(new SerializerTypedFixture(), $wire);

        self::assertSame(SerializerPriority::High, $dto->getPriority());
        self::assertInstanceOf(DateTimeImmutable::class, $dto->getDueAt());
        self::assertSame('2026-09-25T10:15:30+02:00', $dto->getDueAt()->format(DATE_ATOM));
        self::assertInstanceOf(DateTime::class, $dto->getCreatedAt());
        self::assertSame('2026-09-24T08:00:00+00:00', $dto->getCreatedAt()->format(DATE_ATOM));
        self::assertSame('2026-09-23T07:00:00+00:00', $dto->getSeenAt()->format(DATE_ATOM));
        self::assertTrue($dto->isUrgent());
    }

    #[Test]
    public function null_for_a_nullable_enum_setter_stays_null(): void
    {
        $dto = new SerializerTypedFixture();
        $dto->setPriority(SerializerPriority::High);

        PayloadSerializer::hydrate($dto, ['priority' => null]);

        self::assertNull($dto->getPriority());
    }

    #[Test]
    public function unknown_enum_value_fails_loudly(): void
    {
        $this->expectException(ValueError::class);
        PayloadSerializer::hydrate(new SerializerTypedFixture(), ['priority' => 'bogus']);
    }

    #[Test]
    public function fractional_seconds_survive_a_round_trip(): void
    {
        $dto = self::filledFixture();
        $dto->setDueAt(new DateTimeImmutable('2026-09-25T10:15:30.123456+02:00'));

        $data = PayloadSerializer::toArray($dto);
        self::assertSame('2026-09-25T10:15:30.123456+02:00', $data['dueAt']);

        $back = PayloadSerializer::hydrate(new SerializerTypedFixture(), json_decode((string) json_encode($data), true));
        self::assertSame('123456', $back->getDueAt()->format('u'));
    }

    #[Test]
    public function a_union_typed_setter_gets_its_date_or_enum_back(): void
    {
        $dto = new SerializerUnionFixture();
        $dto->setWhen(new DateTime('2026-09-25T10:15:30+00:00'));
        $dto->setLevel(SerializerPriority::High);
        $dto->setLabel('plain');

        $back = PayloadSerializer::hydrate(new SerializerUnionFixture(), json_decode((string) json_encode(PayloadSerializer::toArray($dto)), true));

        self::assertInstanceOf(\DateTimeInterface::class, $back->getWhen());
        self::assertSame('2026-09-25T10:15:30+00:00', $back->getWhen()->format(DATE_ATOM));
        self::assertSame(SerializerPriority::High, $back->getLevel());
        self::assertSame('plain', $back->getLabel(), 'a scalar arm that takes the value keeps it as-is');
    }

    private static function filledFixture(): SerializerTypedFixture
    {
        $dto = new SerializerTypedFixture();
        $dto->setPriority(SerializerPriority::High);
        $dto->setDueAt(new DateTimeImmutable('2026-09-25T10:15:30+02:00'));
        $dto->setCreatedAt(new DateTime('2026-09-24T08:00:00+00:00'));
        $dto->setSeenAt(new DateTimeImmutable('2026-09-23T07:00:00+00:00'));
        $dto->setUrgent(true);

        return $dto;
    }
}

enum SerializerPriority: string
{
    case Low = 'low';
    case High = 'high';
}

final class SerializerPlainFixture
{
    private string $title = '';
    private int $count = 0;

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getCount(): int { return $this->count; }
    public function setCount(int $count): void { $this->count = $count; }
}

final class SerializerTypedFixture
{
    private ?SerializerPriority $priority = null;
    private ?DateTimeImmutable $dueAt = null;
    private ?DateTime $createdAt = null;
    private ?DateTimeInterface $seenAt = null;
    private bool $urgent = false;

    public function getPriority(): ?SerializerPriority { return $this->priority; }
    public function setPriority(?SerializerPriority $priority): void { $this->priority = $priority; }
    public function getDueAt(): ?DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(DateTimeImmutable $dueAt): void { $this->dueAt = $dueAt; }
    public function getCreatedAt(): ?DateTime { return $this->createdAt; }
    public function setCreatedAt(DateTime $createdAt): void { $this->createdAt = $createdAt; }
    public function getSeenAt(): ?DateTimeInterface { return $this->seenAt; }
    public function setSeenAt(DateTimeInterface $seenAt): void { $this->seenAt = $seenAt; }
    public function isUrgent(): bool { return $this->urgent; }
    public function setUrgent(bool $urgent): void { $this->urgent = $urgent; }
    public function isDraft(): bool { return false; }
}

final class SerializerUnionFixture
{
    private DateTimeImmutable|DateTime|null $when = null;
    private SerializerPriority|int|null $level = null;
    private DateTimeImmutable|string $label = '';

    public function getWhen(): DateTimeImmutable|DateTime|null { return $this->when; }
    public function setWhen(DateTimeImmutable|DateTime $when): void { $this->when = $when; }
    public function getLevel(): SerializerPriority|int|null { return $this->level; }
    public function setLevel(SerializerPriority|int $level): void { $this->level = $level; }
    public function getLabel(): DateTimeImmutable|string { return $this->label; }
    public function setLabel(DateTimeImmutable|string $label): void { $this->label = $label; }
}
