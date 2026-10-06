<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Facades;

use Illuminate\Support\Facades\Facade;
use Kyzegs\IndexNow\IndexNow as IndexNowService;
use Kyzegs\IndexNow\Testing\IndexNowFake;

/**
 * @method static list<\Illuminate\Http\Client\Response> submit(string|iterable<string> $urls)
 * @method static \Illuminate\Foundation\Bus\PendingDispatch|null queue(string|iterable<string> $urls, int|null $delay = null)
 * @method static void defer(string|iterable<string> $urls)
 * @method static \Illuminate\Foundation\Bus\PendingDispatch|null flush()
 * @method static void submitUsing(\Kyzegs\IndexNow\Support\SubmissionMode $mode, string|iterable<string> $urls)
 * @method static bool isEnabled()
 * @method static string|null key()
 * @method static bool isValidKey(string $key)
 * @method static string|null keyFilePath(string|null $key = null)
 * @method static string|null keyLocation(string $host, string $scheme = 'https')
 * @method static string host()
 * @method static list<string> hosts()
 * @method static list<string> endpoints()
 * @method static void configureHttpUsing(\Closure|null $callback)
 *
 * @see IndexNowService
 */
class IndexNow extends Facade
{
    /**
     * Record submissions instead of sending them.
     */
    public static function fake(): IndexNowFake
    {
        $fake = static::getFacadeApplication()?->make(IndexNowFake::class);

        assert($fake instanceof IndexNowFake);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return IndexNowService::class;
    }
}
