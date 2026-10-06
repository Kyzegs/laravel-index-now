<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * An endpoint did not accept a batch. Subclasses name the statuses the
 * protocol defines; anything else (400, 5xx, connection errors) is this class.
 */
class RequestFailed extends IndexNowException
{
    /**
     * @param  list<string>  $urls
     */
    final public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly array $urls,
        public readonly ?Response $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $response?->status() ?? 0, $previous);
    }

    /**
     * @param  list<string>  $urls
     */
    public static function fromResponse(Response $response, string $endpoint, array $urls): self
    {
        $class = match ($response->status()) {
            403 => InvalidKey::class,
            422 => UrlsRejected::class,
            429 => RateLimited::class,
            default => self::class,
        };

        return new $class(
            sprintf('IndexNow endpoint [%s] answered %d: %s', $endpoint, $response->status(), $class::reason($response)),
            $endpoint,
            $urls,
            $response,
        );
    }

    /**
     * @param  list<string>  $urls
     */
    public static function connection(Throwable $previous, string $endpoint, array $urls): self
    {
        return new self(
            sprintf('IndexNow endpoint [%s] could not be reached: %s', $endpoint, $previous->getMessage()),
            $endpoint,
            $urls,
            previous: $previous,
        );
    }

    protected static function reason(Response $response): string
    {
        return trim($response->body()) !== '' ? mb_strimwidth(trim($response->body()), 0, 200, '…') : 'no reason given.';
    }
}
