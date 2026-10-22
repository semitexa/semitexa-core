<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueInspection;

/** `queue:failed --queue='*'` listed every queue's dead letters under the name "*". */
final class QueueInspectionNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function notQueueNames(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'tail wildcard' => ['>'];
        yield 'token wildcard' => ['orders.*'];
        yield 'blank token' => ['orders..failed'];
        yield 'space' => ['my queue'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('notQueueNames')]
    public function a_name_that_is_not_one_queue_is_refused(string $queue): void
    {
        $inspection = (new \ReflectionClass(QueueInspection::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $inspection->targets(null, $queue);
    }
}
