<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * The route answers in its declared type whatever the client's Accept says.
 *
 * A handle-less route whose `produces` the client refused gets 406. Some
 * files must be served anyway, because the client reading them does not
 * negotiate and a refusal is read as an answer: a 4xx robots.txt means "no
 * rules" to Google, and llms.txt or a sitemap is fetched by agents and tools
 * whose Accept nobody controls. `produces` stays declared, for the route
 * contract and the AI sitemap; only the refusal is waived.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ServedRegardlessOfAccept
{
}
