<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kyzegs\IndexNow\Exceptions\RateLimited;
use Kyzegs\IndexNow\IndexNow;

/**
 * Unique per URL set (order-independent), so while one is waiting out its
 * debounce delay the same set is not queued again.
 */
class SubmitUrls implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<string>  $urls
     * @param  list<int>  $backoff
     */
    public function __construct(
        public readonly array $urls,
        public int $tries = 3,
        public array $backoff = [],
        public readonly int $debounce = 0,
    ) {}

    public function handle(IndexNow $indexNow): void
    {
        try {
            $indexNow->submit($this->urls);
        } catch (RateLimited $exception) {
            if ($this->attempts() >= $this->tries) {
                throw $exception;
            }

            $this->release($exception->retryAfter() ?? $this->backoffFor($this->attempts()));
        }
    }

    public function uniqueId(): string
    {
        $urls = $this->urls;
        sort($urls);

        return sha1(implode("\n", $urls));
    }

    /**
     * The lock outlives the debounce delay by an hour, so a job a dead worker
     * never finishes cannot block the same URLs forever.
     */
    public function uniqueFor(): int
    {
        return $this->debounce + 3600;
    }

    private function backoffFor(int $attempt): int
    {
        return $this->backoff[$attempt - 1] ?? ($this->backoff === [] ? 60 : $this->backoff[array_key_last($this->backoff)]);
    }
}
