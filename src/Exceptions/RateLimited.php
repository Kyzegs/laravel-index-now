<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

use Illuminate\Http\Client\Response;

/** 429: too many requests, potential spam. */
class RateLimited extends RequestFailed
{
    /** Seconds the endpoint asked us to wait, when it sent Retry-After. */
    public function retryAfter(): ?int
    {
        $header = $this->response?->header('Retry-After') ?? '';

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = $header !== '' ? strtotime($header) : false;

        return $date !== false ? max(0, $date - time()) : null;
    }

    protected static function reason(Response $response): string
    {
        return 'too many requests.';
    }
}
