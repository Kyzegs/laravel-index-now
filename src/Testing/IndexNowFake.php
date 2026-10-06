<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Testing;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Kyzegs\IndexNow\IndexNow;
use Kyzegs\IndexNow\Support\SubmissionMode;
use Kyzegs\IndexNow\Support\UrlList;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records what the application asked to submit, whatever the mode, without
 * consulting enabled/key/host settings: tests assert on intent, not on the
 * environment they happen to run in.
 */
class IndexNowFake extends IndexNow
{
    /** @var list<array{urls: list<string>, mode: SubmissionMode, delay: int|null}> */
    protected array $recorded = [];

    public function submit(string|iterable $urls): array
    {
        $this->record($urls, SubmissionMode::Sync);

        return [];
    }

    public function queue(string|iterable $urls, ?int $delay = null): ?PendingDispatch
    {
        $this->record($urls, SubmissionMode::Queue, $delay);

        return null;
    }

    public function defer(string|iterable $urls): void
    {
        $this->record($urls, SubmissionMode::Defer);
    }

    public function flush(): ?PendingDispatch
    {
        return null;
    }

    /**
     * @param  string|(Closure(list<string>, SubmissionMode, int|null): bool)  $url
     */
    public function assertSubmitted(string|Closure $url, ?SubmissionMode $mode = null): void
    {
        PHPUnit::assertTrue(
            $this->matching($url, $mode) !== [],
            is_string($url) ? "[{$url}] was not submitted to IndexNow." : 'No matching IndexNow submission was recorded.',
        );
    }

    /**
     * @param  string|(Closure(list<string>, SubmissionMode, int|null): bool)  $url
     */
    public function assertNotSubmitted(string|Closure $url, ?SubmissionMode $mode = null): void
    {
        PHPUnit::assertSame(
            [],
            $this->matching($url, $mode),
            is_string($url) ? "[{$url}] was submitted to IndexNow." : 'An unexpected IndexNow submission was recorded.',
        );
    }

    public function assertNothingSubmitted(): void
    {
        PHPUnit::assertSame([], $this->recorded, sprintf('%d IndexNow submission(s) were recorded.', count($this->recorded)));
    }

    /**
     * Number of submit/queue/defer calls, not of URLs.
     */
    public function assertSubmittedCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->recorded, sprintf('Expected %d IndexNow submission(s), %d recorded.', $count, count($this->recorded)));
    }

    /**
     * @return list<array{urls: list<string>, mode: SubmissionMode, delay: int|null}>
     */
    public function submissions(): array
    {
        return $this->recorded;
    }

    /**
     * Every URL recorded, in order, without duplicates.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        return array_values(array_unique(array_merge(...array_column($this->recorded, 'urls'))));
    }

    /**
     * @param  string|iterable<string>  $urls
     */
    protected function record(string|iterable $urls, SubmissionMode $mode, ?int $delay = null): void
    {
        $urls = UrlList::normalise($urls, $this->settings->root());

        if ($urls !== []) {
            $this->recorded[] = ['urls' => $urls, 'mode' => $mode, 'delay' => $delay];
        }
    }

    /**
     * @param  string|(Closure(list<string>, SubmissionMode, int|null): bool)  $url
     * @return list<array{urls: list<string>, mode: SubmissionMode, delay: int|null}>
     */
    protected function matching(string|Closure $url, ?SubmissionMode $mode): array
    {
        if (is_string($url)) {
            $url = UrlList::normalise($url, $this->settings->root())[0] ?? $url;
        }

        return array_values(array_filter($this->recorded, fn(array $submission): bool => ($mode === null || $submission['mode'] === $mode)
            && ($url instanceof Closure
                ? (bool) $url($submission['urls'], $submission['mode'], $submission['delay'])
                : in_array($url, $submission['urls'], true))));
    }
}
