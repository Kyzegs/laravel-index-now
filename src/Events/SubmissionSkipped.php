<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Events;

use Kyzegs\IndexNow\Support\SkipReason;

class SubmissionSkipped
{
    /**
     * @param  list<string>  $urls
     */
    public function __construct(
        public readonly array $urls,
        public readonly SkipReason $reason,
    ) {}
}
