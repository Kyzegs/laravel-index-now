<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Events;

use Illuminate\Http\Client\Response;

class UrlsSubmitted
{
    /**
     * @param  list<string>  $urls
     */
    public function __construct(
        public readonly string $host,
        public readonly string $endpoint,
        public readonly array $urls,
        public readonly Response $response,
    ) {}
}
