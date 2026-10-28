<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * This payload's route answers only a URL signed by {@see \Semitexa\Core\Security\UrlSigner}
 * and not yet expired; any other request gets 403. For links handed out by
 * mail or kept by a visitor: unsubscribe, confirm, a download for a day.
 *
 * It does not replace access attributes: a signed link to a page that needs
 * a signed-in user still needs one.
 *
 * The signature does not cover the method: a link handed out for a GET was
 * also good for a POST or DELETE to the same route. Only $methods are let
 * through (GET and HEAD unless the route says otherwise).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresSignedUrl
{
    /** @param list<string> $methods */
    public function __construct(
        public array $methods = ['GET', 'HEAD'],
    ) {
    }
}
