<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Log\LogManager;
use Kyzegs\IndexNow\Events\SubmissionFailed;
use Kyzegs\IndexNow\Events\SubmissionSkipped;
use Kyzegs\IndexNow\Events\UrlsSubmitted;
use Kyzegs\IndexNow\Exceptions\RequestFailed;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes the requests and reports every outcome (events + log). The event
 * dispatcher and logger are resolved per use, so Event::fake() and
 * Log::spy() reach an instance created before them.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class Sender
{
    /** @var (Closure(PendingRequest): (PendingRequest|null))|null */
    protected static ?Closure $httpCallback = null;

    public function __construct(
        protected Container $container,
        protected Http $http,
        protected Settings $settings,
    ) {}

    /**
     * @param  (Closure(PendingRequest): (PendingRequest|null))|null  $callback
     */
    public static function configureHttpUsing(?Closure $callback): void
    {
        static::$httpCallback = $callback;
    }

    /**
     * @param  list<string>  $urls
     *
     * @throws RequestFailed
     */
    public function send(string $endpoint, string $host, string $scheme, array $urls): Response
    {
        $key = (string) $this->settings->key();

        try {
            $response = $this->request()->post($endpoint, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => "{$scheme}://{$host}/" . $this->settings->keyFilePath($key),
                'urlList' => $urls,
            ]);

            // 200 = received, 202 = received while the key is still being verified.
            if ($response->successful()) {
                $this->events()->dispatch(new UrlsSubmitted($host, $endpoint, $urls, $response));

                return $response;
            }

            $exception = RequestFailed::fromResponse($response, $endpoint, $urls);
        } catch (ConnectionException $previous) {
            $exception = RequestFailed::connection($previous, $endpoint, $urls);
        }

        $this->events()->dispatch(new SubmissionFailed($host, $endpoint, $urls, $exception));

        if ($this->settings->bool('index-now.log.failed', true)) {
            $this->logger()->error($exception->getMessage(), ['host' => $host, 'urls' => count($urls)]);
        }

        throw $exception;
    }

    /**
     * @param  list<string>  $urls
     */
    public function skipped(array $urls, SkipReason $reason): void
    {
        $this->events()->dispatch(new SubmissionSkipped($urls, $reason));

        if ($urls !== [] && $this->settings->bool('index-now.log.skipped', true)) {
            $this->logger()->debug("IndexNow: skipped {$reason->value}.", ['urls' => $urls]);
        }
    }

    protected function request(): PendingRequest
    {
        $request = $this->http->asJson()
            ->acceptJson()
            ->timeout($this->settings->int('index-now.http.timeout', 10))
            ->connectTimeout($this->settings->int('index-now.http.connect_timeout', 5));

        $times = $this->settings->int('index-now.http.retry.times', 0);

        if ($times > 0) {
            $request->retry(
                $times,
                $this->settings->int('index-now.http.retry.sleep', 100),
                fn(Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
        }

        $agent = $this->settings->string('index-now.http.user_agent');

        if ($agent !== null) {
            $request->withUserAgent($agent);
        }

        return static::$httpCallback !== null ? ((static::$httpCallback)($request) ?? $request) : $request;
    }

    protected function events(): Events
    {
        return $this->container->make(Events::class);
    }

    protected function logger(): LoggerInterface
    {
        return $this->container->make(LogManager::class)->channel($this->settings->string('index-now.log.channel'));
    }
}
