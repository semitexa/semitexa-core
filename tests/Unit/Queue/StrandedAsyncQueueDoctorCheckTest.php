<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Queue\StrandedAsyncQueueDoctorCheck;

/**
 * A container without discovery or queue inspection threw out of the check,
 * and the doctor showed a failed row instead of the check's own warning.
 */
final class StrandedAsyncQueueDoctorCheckTest extends TestCase
{
    #[Test]
    public function without_what_it_reads_it_warns_instead_of_throwing(): void
    {
        $slot = new \ReflectionProperty(ContainerFactory::class, 'container');
        $booted = $slot->getValue();
        $slot->setValue(null, new SemitexaContainer());
        try {
            $result = (new StrandedAsyncQueueDoctorCheck())->run();
        } finally {
            $slot->setValue(null, $booted);
        }

        self::assertSame(
            ['warn', 'Could not check the queues Async handlers used before the upgrade: the container has no attribute discovery or queue inspection.'],
            [$result->status->value, $result->message],
        );
    }
}
