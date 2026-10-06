<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

enum SkipReason: string
{
    case Disabled = 'disabled';
    case MissingKey = 'missing_key';
    case ForeignHost = 'foreign_host';
    case NoUrls = 'no_urls';
}
