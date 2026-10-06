<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

class ForeignUrls extends IndexNowException
{
    /**
     * @param  list<string>  $urls
     */
    public function __construct(public readonly array $urls)
    {
        parent::__construct(sprintf(
            'IndexNow can only submit URLs for the configured hosts; %d URL(s) are for other hosts, e.g. [%s].',
            count($urls),
            $urls[0] ?? '',
        ));
    }
}
