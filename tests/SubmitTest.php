<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kyzegs\IndexNow\Events\SubmissionFailed;
use Kyzegs\IndexNow\Events\SubmissionSkipped;
use Kyzegs\IndexNow\Events\UrlsSubmitted;
use Kyzegs\IndexNow\Exceptions\ForeignUrls;
use Kyzegs\IndexNow\Exceptions\InvalidConfiguration;
use Kyzegs\IndexNow\Exceptions\InvalidKey;
use Kyzegs\IndexNow\Exceptions\RateLimited;
use Kyzegs\IndexNow\Exceptions\RequestFailed;
use Kyzegs\IndexNow\Exceptions\UrlsRejected;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\IndexNow as IndexNowService;
use Kyzegs\IndexNow\Support\SkipReason;
use Kyzegs\IndexNow\Tests\TestCase;

afterEach(fn() => IndexNowService::configureHttpUsing(null));

it('posts the protocol payload', function () {
    Http::fake();

    $responses = IndexNow::submit(['https://example.com/a', '/b']);

    expect($responses)->toHaveCount(1);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.indexnow.org/indexnow'
        && $request->isJson()
        && $request->data() === [
            'host' => 'example.com',
            'key' => TestCase::KEY,
            'keyLocation' => 'https://example.com/' . TestCase::KEY . '.txt',
            'urlList' => ['https://example.com/a', 'https://example.com/b'],
        ]);
});

it('sends a single url the same way', function () {
    Http::fake();

    IndexNow::submit('https://example.com/a');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && $request['urlList'] === ['https://example.com/a']);
});

it('derives the key location from key_location and the url scheme', function () {
    config(['index-now.key_location' => '/indexnow/']);
    Http::fake();

    IndexNow::submit('http://example.com/a');

    Http::assertSent(fn(Request $request): bool => $request['keyLocation'] === 'http://example.com/indexnow/' . TestCase::KEY . '.txt');
});

it('makes one request per host', function () {
    config(['index-now.hosts' => ['blog.example.com']]);
    Http::fake();

    IndexNow::submit(['https://example.com/a', 'https://blog.example.com/b', 'https://example.com/c']);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request): bool => $request['host'] === 'example.com' && $request['urlList'] === ['https://example.com/a', 'https://example.com/c']);
    Http::assertSent(fn(Request $request): bool => $request['host'] === 'blog.example.com' && $request['keyLocation'] === 'https://blog.example.com/' . TestCase::KEY . '.txt');
});

it('uses the configured host over app.url', function () {
    config(['index-now.host' => 'www.example.org']);
    Http::fake();

    IndexNow::submit('https://www.example.org/a');

    Http::assertSent(fn(Request $request): bool => $request['host'] === 'www.example.org');
});

it('chunks at the batch size', function () {
    config(['index-now.batch_size' => 2]);
    Http::fake();

    IndexNow::submit(['https://example.com/1', 'https://example.com/2', 'https://example.com/3']);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request): bool => $request['urlList'] === ['https://example.com/3']);
});

it('never exceeds the protocol cap', function () {
    config(['index-now.batch_size' => 50_000]);
    Http::fake();

    IndexNow::submit(array_map(fn(int $i): string => "https://example.com/{$i}", range(1, 10_001)));

    Http::assertSentCount(2);
});

it('posts to every endpoint', function () {
    config(['index-now.endpoints' => ['https://www.bing.com/indexnow', 'https://yandex.com/indexnow']]);
    Http::fake();

    expect(IndexNow::submit('https://example.com/a'))->toHaveCount(2);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://www.bing.com/indexnow');
    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://yandex.com/indexnow');
});

it('treats 202 as accepted', function () {
    Event::fake();
    Http::fake(['*' => Http::response('', 202)]);

    expect(IndexNow::submit('https://example.com/a'))->toHaveCount(1);

    Event::assertDispatched(UrlsSubmitted::class, fn(UrlsSubmitted $event): bool => $event->host === 'example.com'
        && $event->endpoint === 'https://api.indexnow.org/indexnow'
        && $event->urls === ['https://example.com/a']
        && $event->response->status() === 202);
});

it('maps protocol errors to exceptions', function (int $status, string $exception) {
    Http::fake(['*' => Http::response('nope', $status)]);

    expect(fn() => IndexNow::submit('https://example.com/a'))->toThrow($exception);
})->with([
    [400, RequestFailed::class],
    [403, InvalidKey::class],
    [422, UrlsRejected::class],
    [429, RateLimited::class],
    [500, RequestFailed::class],
]);

it('carries the request details on the exception', function () {
    Http::fake(['*' => Http::response('bad', 400)]);

    try {
        IndexNow::submit('https://example.com/a');
    } catch (RequestFailed $exception) {
        expect($exception->endpoint)->toBe('https://api.indexnow.org/indexnow')
            ->and($exception->urls)->toBe(['https://example.com/a'])
            ->and($exception->response?->status())->toBe(400)
            ->and($exception->getCode())->toBe(400)
            ->and($exception->getMessage())->toContain('400')->toContain('bad');

        return;
    }

    test()->fail('No exception thrown.');
});

it('reads Retry-After from a 429', function (string $header, ?int $expected) {
    Http::fake(['*' => Http::response('', 429, $header === '' ? [] : ['Retry-After' => $header])]);

    try {
        IndexNow::submit('https://example.com/a');
    } catch (RateLimited $exception) {
        expect($exception->retryAfter())->toBe($expected);

        return;
    }

    test()->fail('No exception thrown.');
})->with([
    'seconds' => ['120', 120],
    'missing' => ['', null],
    'past date' => ['Wed, 21 Oct 2015 07:28:00 GMT', 0],
]);

it('tries every endpoint before throwing the first failure', function () {
    config(['index-now.endpoints' => ['https://bad.test/indexnow', 'https://good.test/indexnow']]);
    Event::fake();
    Http::fake(['bad.test/*' => Http::response('', 403), 'good.test/*' => Http::response()]);

    expect(fn() => IndexNow::submit('https://example.com/a'))->toThrow(InvalidKey::class);

    Http::assertSentCount(2);
    Event::assertDispatched(SubmissionFailed::class, fn(SubmissionFailed $event): bool => $event->endpoint === 'https://bad.test/indexnow' && $event->exception instanceof InvalidKey);
    Event::assertDispatched(UrlsSubmitted::class, fn(UrlsSubmitted $event): bool => $event->endpoint === 'https://good.test/indexnow');
});

it('wraps connection errors', function () {
    Http::fake(fn() => throw new Illuminate\Http\Client\ConnectionException('timed out'));

    expect(fn() => IndexNow::submit('https://example.com/a'))->toThrow(RequestFailed::class, 'could not be reached: timed out');
});

it('logs failures to the configured channel', function () {
    config(['index-now.log.channel' => 'stack']);
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(fn(string $message, array $context): bool => str_contains($message, '500') && $context === ['host' => 'example.com', 'urls' => 1]);
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn() => IndexNow::submit('https://example.com/a'))->toThrow(RequestFailed::class);
});

it('can stay quiet about failures', function () {
    config(['index-now.log.failed' => false]);
    Log::shouldReceive('channel')->never();
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn() => IndexNow::submit('https://example.com/a'))->toThrow(RequestFailed::class);
});

it('skips when disabled', function () {
    config(['index-now.enabled' => false]);
    Event::fake();
    Http::fake();

    expect(IndexNow::submit('https://example.com/a'))->toBe([]);

    Http::assertNothingSent();
    Event::assertDispatched(SubmissionSkipped::class, fn(SubmissionSkipped $event): bool => $event->reason === SkipReason::Disabled && $event->urls === ['https://example.com/a']);
});

it('skips without a key instead of sending an empty one', function (?string $key) {
    config(['index-now.key' => $key]);
    Event::fake();
    Http::fake();

    expect(IndexNow::submit('https://example.com/a'))->toBe([]);

    Http::assertNothingSent();
    Event::assertDispatched(SubmissionSkipped::class, fn(SubmissionSkipped $event): bool => $event->reason === SkipReason::MissingKey);
})->with([null, '', '  ']);

it('skips an empty list', function () {
    Event::fake();
    Http::fake();

    expect(IndexNow::submit([]))->toBe([]);

    Http::assertNothingSent();
    Event::assertDispatched(SubmissionSkipped::class, fn(SubmissionSkipped $event): bool => $event->reason === SkipReason::NoUrls);
});

it('logs skips at debug level unless told not to', function (bool $enabled) {
    config(['index-now.enabled' => false, 'index-now.log.skipped' => $enabled]);
    Log::shouldReceive('channel')->times((int) $enabled)->andReturnSelf();
    Log::shouldReceive('debug')->times((int) $enabled)->with('IndexNow: skipped disabled.', ['urls' => ['https://example.com/a']]);

    IndexNow::submit('https://example.com/a');
})->with([true, false]);

it('rejects a malformed key', function (string $key) {
    config(['index-now.key' => $key]);
    Http::fake();

    IndexNow::submit('https://example.com/a');
})->with(['short', 'has_underscore', str_repeat('a', 129)])->throws(InvalidConfiguration::class);

it('drops urls for other hosts', function () {
    Event::fake();
    Http::fake();

    IndexNow::submit(['https://example.com/a', 'https://elsewhere.test/b']);

    Http::assertSent(fn(Request $request): bool => $request['urlList'] === ['https://example.com/a']);
    Event::assertDispatched(SubmissionSkipped::class, fn(SubmissionSkipped $event): bool => $event->reason === SkipReason::ForeignHost && $event->urls === ['https://elsewhere.test/b']);
});

it('can throw on urls for other hosts', function () {
    config(['index-now.foreign_urls' => 'throw']);
    Http::fake();

    expect(fn() => IndexNow::submit(['https://example.com/a', 'https://elsewhere.test/b']))->toThrow(ForeignUrls::class);

    Http::assertNothingSent();
});

it('rejects an unknown foreign_urls mode', function () {
    config(['index-now.foreign_urls' => 'ignore']);

    IndexNow::submit('https://elsewhere.test/b');
})->throws(InvalidConfiguration::class);

it('needs a host and an endpoint', function (array $config) {
    config($config);
    Http::fake();

    IndexNow::submit('https://example.com/a');
})->with([
    'no host' => [['app.url' => '', 'index-now.host' => null]],
    'no endpoints' => [['index-now.endpoints' => []]],
])->throws(InvalidConfiguration::class);

it('applies the http settings', function () {
    config(['index-now.http.user_agent' => 'MyBot/1.0', 'index-now.http.timeout' => 3]);
    Http::fake();

    IndexNow::submit('https://example.com/a');

    Http::assertSent(fn(Request $request): bool => $request->header('User-Agent') === ['MyBot/1.0']);
});

it('retries server errors in-request when configured', function () {
    config(['index-now.http.retry' => ['times' => 2, 'sleep' => 0]]);
    Http::fakeSequence()->push('', 503)->push('', 200);

    expect(IndexNow::submit('https://example.com/a'))->toHaveCount(1);

    Http::assertSentCount(2);
});

it('lets the application customise the client', function () {
    IndexNowService::configureHttpUsing(fn(PendingRequest $request): PendingRequest => $request->withHeaders(['X-Test' => 'yes']));
    Http::fake();

    IndexNow::submit('https://example.com/a');

    Http::assertSent(fn(Request $request): bool => $request->header('X-Test') === ['yes']);
});

it('exposes the hosts it submits for', function () {
    config(['index-now.host' => 'Example.com', 'index-now.hosts' => 'blog.example.com, Shop.example.com']);

    expect(IndexNow::host())->toBe('example.com')
        ->and(IndexNow::hosts())->toBe(['example.com', 'blog.example.com', 'shop.example.com']);
});
