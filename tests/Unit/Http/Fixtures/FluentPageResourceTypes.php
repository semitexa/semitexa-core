<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Fixtures;

use Semitexa\Core\Http\Response\ResponseFormat;

use function PHPStan\Testing\assertType;

/**
 * Read by phpstan only (ResourceResponseFluentTypeTest): what each fluent
 * setter of ResourceResponse returns when it is called on an application's
 * resource. A handler typed to its resource returns the call, as the
 * fresh-project make-it run did with setRedirect() on 19.11.
 */
final class FluentPageResourceTypes
{
    public function redirect(FluentPageResource $resource): FluentPageResource
    {
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setRedirect('/done'));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setStatusCode(201));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setContent('<p>done</p>'));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setHeader('Cache-Control', 'no-store'));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->markAsAlreadySent());
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setRenderHandle('done'));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setLayoutFrame('one-column'));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setRenderContext(['step' => 2]));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setContext(['step' => 2]));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setRenderFormat(ResponseFormat::Layout));
        assertType('Semitexa\Core\Tests\Unit\Http\Fixtures\FluentPageResource', $resource->setRendererClass(null));

        return $resource->setStatusCode(303)->setRedirect('/done', 303);
    }
}
