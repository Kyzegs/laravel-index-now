<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

use Illuminate\Http\Client\Response;

/** 403: the key file could not be fetched from the host or does not match. */
class InvalidKey extends RequestFailed
{
    protected static function reason(Response $response): string
    {
        return 'the key is not valid (key file missing, unreachable or different from the key sent).';
    }
}
