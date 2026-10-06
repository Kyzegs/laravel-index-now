<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

enum SubmissionMode: string
{
    /** Send now, in this process. */
    case Sync = 'sync';

    /** Queue a job right away. */
    case Queue = 'queue';

    /** Collect, then queue one job for everything once the request, job or command ends. */
    case Defer = 'defer';
}
