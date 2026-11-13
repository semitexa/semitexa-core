<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Response;

/**
 * The formats a response can be rendered in.
 *
 * @api
 */
enum ResponseFormat: string
{
    case Layout = 'layout';
    case Json = 'json';
    case Raw = 'raw';
    case Xml = 'xml';
    case Text = 'text';
}


