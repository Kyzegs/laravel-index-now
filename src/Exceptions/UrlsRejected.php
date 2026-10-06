<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

use Illuminate\Http\Client\Response;

/** 422: a URL does not belong to the host, or the key does not match the schema. */
class UrlsRejected extends RequestFailed
{
    protected static function reason(Response $response): string
    {
        return 'URLs do not belong to the host or the key does not match the protocol schema.';
    }
}
