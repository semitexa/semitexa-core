<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

enum ResourceFieldKind: string
{
    case Scalar       = 'scalar';
    case EmbeddedOne  = 'embedded_one';
    case EmbeddedMany = 'embedded_many';
    case RefOne       = 'ref_one';
    case RefMany      = 'ref_many';
    case Union        = 'union';
}
