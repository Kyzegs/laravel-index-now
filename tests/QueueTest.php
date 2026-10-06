<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kyzegs\IndexNow\Exceptions\InvalidKey;
use Kyzegs\IndexNow\Exceptions\RateLimited;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\IndexNow as IndexNowService;
use Kyzegs\IndexNow\Jobs\SubmitUrls;

it('queues a job with the normalised urls', function () {
    Queue::fake();

    expect(IndexNow::queue(['/a', 'https://example.com/a#x']))->not->toBeNull();

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->urls === ['https://example.com/a']
        && $job->tries === 3
        && $job->backoff === [60, 300, 900]
        && $job->afterCommit === true
        && $job->delay === null);
});

it('applies the queue settings', function () {
    config(['index-now.queue' => ['connection' => 'redis', 'name' => 'seo', 'tries' => 5, 'backoff' => [10], 'after_commit' => false]]);
    Queue::fake();

    IndexNow::queue('https://example.com/a');

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->connection === 'redis'
        && $job->queue === 'seo'
        && $job->tries === 5
        && $job->backoff === [10]
        && $job->afterCommit === false);
});

it('delays by the debounce, or by an explicit delay', function (?int $delay, int $expected) {
    config(['index-now.debounce' => 600]);
    Queue::fake();

    IndexNow::queue('https://example.com/a', $delay);

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->delay === $expected && $job->debounce === $expected);
})->with([[null, 600], [30, 30]]);

it('queues nothing when the submission would be skipped', function (array $config) {
    config($config);
    Queue::fake();

    expect(IndexNow::queue('https://example.com/a'))->toBeNull();

    Queue::assertNothingPushed();
})->with([
    'disabled' => [['index-now.enabled' => false]],
    'no key' => [['index-now.key' => null]],
    'foreign only' => [['index-now.host' => 'other.test']],
]);

it('is unique per url set, whatever the order', function () {
    $job = new SubmitUrls(['https://example.com/a', 'https://example.com/b']);

    expect($job->uniqueId())->toBe((new SubmitUrls(['https://example.com/b', 'https://example.com/a']))->uniqueId())
        ->not->toBe((new SubmitUrls(['https://example.com/a']))->uniqueId())
        ->and((new SubmitUrls(['https://example.com/a'], debounce: 600))->uniqueFor())->toBe(4200);
});

it('collapses identical submissions while one is pending', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    // Queue::fake() does not take unique locks, so exercise the lock directly.
    $lock = app(Illuminate\Bus\UniqueLock::class);
    $job = new SubmitUrls(['https://example.com/a']);

    expect($lock->acquire($job))->toBeTrue()
        ->and($lock->acquire(new SubmitUrls(['https://example.com/a'])))->toBeFalse();
});

it('submits when handled', function () {
    Http::fake();

    (new SubmitUrls(['https://example.com/a']))->handle(app(IndexNowService::class));

    Http::assertSent(fn($request): bool => $request['urlList'] === ['https://example.com/a']);
});

it('releases a rate-limited job for as long as asked', function (array $headers, int $expected) {
    Http::fake(['*' => Http::response('', 429, $headers)]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with($expected);

    $job = new SubmitUrls(['https://example.com/a'], backoff: [45, 90]);
    $job->setJob($queueJob);
    $job->handle(app(IndexNowService::class));
})->with([
    'Retry-After' => [['Retry-After' => '30'], 30],
    'backoff' => [[], 45],
]);

it('fails a rate-limited job on its last attempt', function () {
    Http::fake(['*' => Http::response('', 429)]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $queueJob->shouldNotReceive('release');

    $job = new SubmitUrls(['https://example.com/a'], tries: 3);
    $job->setJob($queueJob);
    $job->handle(app(IndexNowService::class));
})->throws(RateLimited::class);

it('lets other failures retry through the queue', function () {
    Http::fake(['*' => Http::response('', 403)]);

    (new SubmitUrls(['https://example.com/a']))->handle(app(IndexNowService::class));
})->throws(InvalidKey::class);
