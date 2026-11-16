<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;

/** Carries the package's attribute and no #[AsService]. */
#[MarksAService]
final class MarkedJob
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $discovery;

    public function discovery(): AttributeDiscovery
    {
        return $this->discovery;
    }
}
