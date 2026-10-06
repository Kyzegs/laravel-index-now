<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kyzegs\IndexNow\Exceptions\ForeignUrls;
use Kyzegs\IndexNow\Exceptions\InvalidConfiguration;
use Kyzegs\IndexNow\Exceptions\RequestFailed;
use Kyzegs\IndexNow\Jobs\SubmitUrls;
use Kyzegs\IndexNow\Support\Sender;
use Kyzegs\IndexNow\Support\Settings;
use Kyzegs\IndexNow\Support\SkipReason;
use Kyzegs\IndexNow\Support\SubmissionMode;
use Kyzegs\IndexNow\Support\UrlList;

use function Illuminate\Support\defer;

/**
 * Bound as a scoped instance: the defer() buffer belongs to one request, job
 * or command and is dropped with it, which keeps Octane workers clean.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class IndexNow
{
    /** @var array<string, true> */
    protected array $deferred = [];

    public function __construct(
        protected Settings $settings,
        protected Sender $sender,
    ) {}

    /**
     * Customise the HTTP client used for every submission (headers, proxy,
     * middleware…). Call once from a service provider's boot().
     *
     * @param  (Closure(PendingRequest): (PendingRequest|null))|null  $callback
     */
    public static function configureHttpUsing(?Closure $callback): void
    {
        Sender::configureHttpUsing($callback);
    }

    /**
     * Send the URLs now. Returns one response per request made (hosts ×
     * batches × endpoints) and an empty list when the submission was skipped.
     *
     * @param  string|iterable<string>  $urls
     * @return list<Response>
     *
     * @throws RequestFailed when an endpoint refuses a batch (after every endpoint was tried)
     */
    public function submit(string|iterable $urls): array
    {
        $responses = [];
        $failure = null;
        $urls = $this->sendable(UrlList::normalise($urls, $this->settings->root()));

        foreach (UrlList::groupByHost($urls) as $host => $hostUrls) {
            foreach (array_chunk($hostUrls, $this->settings->batchSize()) as $batch) {
                foreach ($this->settings->endpoints() as $endpoint) {
                    try {
                        $responses[] = $this->sender->send($endpoint, $host, UrlList::scheme($batch[0]), $batch);
                    } catch (RequestFailed $exception) {
                        $failure ??= $exception;
                    }
                }
            }
        }

        return $failure === null ? $responses : throw $failure;
    }

    /**
     * Queue the URLs. Null when the submission is skipped up front (disabled,
     * no key, nothing left to send), so no job is queued for nothing.
     *
     * @param  string|iterable<string>  $urls
     * @param  int|null  $delay  seconds; null uses index-now.debounce
     */
    public function queue(string|iterable $urls, ?int $delay = null): ?PendingDispatch
    {
        $urls = $this->sendable(UrlList::normalise($urls, $this->settings->root()));

        if ($urls === []) {
            return null;
        }

        $delay = max(0, $delay ?? $this->settings->int('index-now.debounce', 0));
        $backoff = array_map(intval(...), $this->settings->strings('index-now.queue.backoff'));

        $pending = SubmitUrls::dispatch($urls, $this->settings->int('index-now.queue.tries', 3), $backoff, $delay)
            ->onConnection($this->settings->string('index-now.queue.connection'))
            ->onQueue($this->settings->string('index-now.queue.name'));

        if ($delay > 0) {
            $pending->delay($delay);
        }

        return $this->settings->bool('index-now.queue.after_commit', true)
            ? $pending->afterCommit()
            : $pending->beforeCommit();
    }

    /**
     * Collect the URLs and queue a single job for everything collected once
     * the current request, job or command has finished.
     *
     * @param  string|iterable<string>  $urls
     */
    public function defer(string|iterable $urls): void
    {
        foreach (UrlList::normalise($urls, $this->settings->root()) as $url) {
            $this->deferred[$url] = true;
        }

        if ($this->deferred !== []) {
            defer(fn() => $this->flush(), 'index-now:' . spl_object_id($this), always: true);
        }
    }

    /**
     * Queue whatever defer() collected so far. Runs on its own after the
     * request; call it directly where no deferred callbacks run.
     */
    public function flush(): ?PendingDispatch
    {
        $urls = array_keys($this->deferred);
        $this->deferred = [];

        return $urls === [] ? null : $this->queue($urls);
    }

    /**
     * @param  string|iterable<string>  $urls
     */
    public function submitUsing(SubmissionMode $mode, string|iterable $urls): void
    {
        match ($mode) {
            SubmissionMode::Sync => $this->submit($urls),
            SubmissionMode::Queue => $this->queue($urls),
            SubmissionMode::Defer => $this->defer($urls),
        };
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled();
    }

    public function key(): ?string
    {
        return $this->settings->key();
    }

    public static function isValidKey(string $key): bool
    {
        return Settings::isValidKey($key);
    }

    /**
     * Path of the key file below the public root, e.g. `indexnow/{key}.txt`.
     */
    public function keyFilePath(?string $key = null): ?string
    {
        return $this->settings->keyFilePath($key);
    }

    public function keyLocation(string $host, string $scheme = 'https'): ?string
    {
        $path = $this->keyFilePath();

        return $path === null ? null : "{$scheme}://{$host}/{$path}";
    }

    public function host(): string
    {
        return $this->settings->host();
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        return $this->settings->hosts();
    }

    /**
     * @return list<string>
     */
    public function endpoints(): array
    {
        return $this->settings->endpoints();
    }

    /**
     * Drops everything that must not be sent and reports why.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function sendable(array $urls): array
    {
        $reason = match (true) {
            $urls === [] => SkipReason::NoUrls,
            !$this->settings->enabled() => SkipReason::Disabled,
            $this->settings->key() === null => SkipReason::MissingKey,
            default => null,
        };

        if ($reason !== null) {
            $this->sender->skipped($urls, $reason);

            return [];
        }

        if (!Settings::isValidKey((string) $this->settings->key())) {
            throw InvalidConfiguration::invalidKey();
        }

        $hosts = $this->settings->hosts();
        $foreign = array_values(array_filter($urls, fn(string $url): bool => !in_array(UrlList::host($url), $hosts, true)));

        if ($foreign === []) {
            return $urls;
        }

        if ($this->settings->throwOnForeignUrls()) {
            throw new ForeignUrls($foreign);
        }

        $this->sender->skipped($foreign, SkipReason::ForeignHost);

        return array_values(array_diff($urls, $foreign));
    }
}
