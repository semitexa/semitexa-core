<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Discovery\AttributeDiscovery;
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
        self::assertSame(self::WARNING, $this->runWith(new SemitexaContainer()));
    }

    /** The second lookup throws too: discovery is there (the first one answers), queue inspection is not. */
    #[Test]
    public function without_queue_inspection_alone_it_warns_instead_of_throwing(): void
    {
        $container = new SemitexaContainer();
        $container->set(AttributeDiscovery::class, new \stdClass());

        self::assertSame(self::WARNING, $this->runWith($container));
    }

    private const WARNING = ['warn', 'Could not check the queues Async handlers used before the upgrade: the container has no attribute discovery or queue inspection.'];

    /** @return array{string, string} */
    private function runWith(SemitexaContainer $container): array
    {
        $slot = new \ReflectionProperty(ContainerFactory::class, 'container');
        $booted = $slot->getValue();
        $slot->setValue(null, $container);
        try {
            $result = (new StrandedAsyncQueueDoctorCheck())->run();
        } finally {
            $slot->setValue(null, $booted);
        }

        return [$result->status->value, $result->message];
    }
}
