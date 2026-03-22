<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

enum HttpStatus: int
{
    // 2xx Success
    case Ok                  = 200;
    case Created             = 201;
    case Accepted            = 202;
    case NoContent           = 204;

    // 3xx Redirection
    case MovedPermanently    = 301;
    case Found               = 302;
    case SeeOther            = 303;
    case NotModified         = 304;
    case TemporaryRedirect   = 307;
    case PermanentRedirect   = 308;

    // 4xx Client Error
    case BadRequest          = 400;
    case Unauthorized        = 401;
    case Forbidden           = 403;
    case NotFound            = 404;
    case MethodNotAllowed    = 405;
    case NotAcceptable       = 406;
    case Conflict            = 409;
    case Gone                = 410;
    case UnsupportedMediaType = 415;
    case UnprocessableEntity = 422;
    case TooManyRequests     = 429;

    // 5xx Server Error
    case InternalServerError = 500;
    case NotImplemented      = 501;
    case BadGateway          = 502;
    case ServiceUnavailable  = 503;
    case GatewayTimeout      = 504;

    public function reason(): string
    {
        return match ($this) {
            self::Ok                  => 'OK',
            self::Created             => 'Created',
            self::Accepted            => 'Accepted',
            self::NoContent           => 'No Content',
            self::MovedPermanently    => 'Moved Permanently',
            self::Found               => 'Found',
            self::SeeOther            => 'See Other',
            self::NotModified         => 'Not Modified',
            self::TemporaryRedirect   => 'Temporary Redirect',
            self::PermanentRedirect   => 'Permanent Redirect',
            self::BadRequest          => 'Bad Request',
            self::Unauthorized        => 'Unauthorized',
            self::Forbidden           => 'Forbidden',
            self::NotFound            => 'Not Found',
            self::MethodNotAllowed    => 'Method Not Allowed',
            self::NotAcceptable       => 'Not Acceptable',
            self::Conflict            => 'Conflict',
            self::Gone                => 'Gone',
            self::UnsupportedMediaType => 'Unsupported Media Type',
            self::UnprocessableEntity => 'Unprocessable Entity',
            self::TooManyRequests     => 'Too Many Requests',
            self::InternalServerError => 'Internal Server Error',
            self::NotImplemented      => 'Not Implemented',
            self::BadGateway          => 'Bad Gateway',
            self::ServiceUnavailable  => 'Service Unavailable',
            self::GatewayTimeout      => 'Gateway Timeout',
        };
    }

    public function isSuccess(): bool
    {
        return $this->value >= 200 && $this->value < 300;
    }

    public function isRedirect(): bool
    {
        return $this->value >= 300 && $this->value < 400;
    }

    public function isClientError(): bool
    {
        return $this->value >= 400 && $this->value < 500;
    }

    public function isServerError(): bool
    {
        return $this->value >= 500 && $this->value < 600;
    }
}
