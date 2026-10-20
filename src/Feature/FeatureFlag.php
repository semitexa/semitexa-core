<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

/** One declared flag: what #[AsFeatureFlag] said, and who said it. */
final readonly class FeatureFlag
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,127}$/';

    public function __construct(
        public string $name,
        public bool $default,
        public string $description,
        public ?int $percent,
        /** @var class-string */
        public string $declaredBy,
    ) {}

    /**
     * Whether a percentage rollout includes $subject: the same subject is in or
     * out every time, and raising the share only adds subjects.
     */
    public static function rollsOutTo(string $flag, int $percent, string $subject): bool
    {
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        return crc32($flag . '|' . $subject) % 100 < $percent;
    }

    /** The declared value for a subject: the percentage when one is declared and a subject is known. */
    public function defaultFor(?string $subject): bool
    {
        if ($this->percent !== null && $subject !== null && $subject !== '') {
            return self::rollsOutTo($this->name, $this->percent, $subject);
        }

        return $this->default;
    }
}
