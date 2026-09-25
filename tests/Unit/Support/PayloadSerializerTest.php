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
