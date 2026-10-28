<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\SignedUrlDemo\Payload;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPublicPayload(
    path: self::PATH,
    // POST and DELETE too: the route takes them, the signed link must not.
    methods: ['GET', 'POST', 'DELETE'],
    responseWith: ResourceResponse::class,
)]
#[RequiresSignedUrl]
final class SignedDownloadPayload
{
    public const PATH = '/core-fixture/signed/download';
}
