<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\IndexNow as IndexNowService;
use Kyzegs\IndexNow\Support\SubmissionMode;
use Kyzegs\IndexNow\Testing\IndexNowFake;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function () {
    Http::fake();
    Queue::fake();
    $this->fake = IndexNow::fake();
});

it('replaces the service in the container too', function () {
    expect(app(IndexNowService::class))->toBe($this->fake)->toBeInstanceOf(IndexNowFake::class);
});

it('records every mode without sending or queueing', function () {
    IndexNow::submit('/a');
    IndexNow::queue(['/b', '/c'], 30);
    IndexNow::defer('/d');

    expect($this->fake->submissions())->toBe([
        ['urls' => ['https://example.com/a'], 'mode' => SubmissionMode::Sync, 'delay' => null],
        ['urls' => ['https://example.com/b', 'https://example.com/c'], 'mode' => SubmissionMode::Queue, 'delay' => 30],
        ['urls' => ['https://example.com/d'], 'mode' => SubmissionMode::Defer, 'delay' => null],
    ])->and($this->fake->urls())->toBe(['https://example.com/a', 'https://example.com/b', 'https://example.com/c', 'https://example.com/d'])
        ->and(IndexNow::flush())->toBeNull();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('records regardless of the environment settings', function () {
    config(['index-now.enabled' => false, 'index-now.key' => null]);

    IndexNow::queue('/a');

    $this->fake->assertSubmitted('/a');
});

it('asserts by url, path, mode and callback', function () {
    IndexNow::queue('/a', 10);

    $this->fake->assertSubmitted('/a');
    $this->fake->assertSubmitted('https://example.com/a', SubmissionMode::Queue);
    $this->fake->assertSubmitted(fn(array $urls, SubmissionMode $mode, ?int $delay): bool => $delay === 10);
    $this->fake->assertNotSubmitted('/a', SubmissionMode::Sync);
    $this->fake->assertNotSubmitted('/b');
    $this->fake->assertSubmittedCount(1);
});

it('fails assertions that do not hold', function (string $method, array $arguments) {
    IndexNow::submit('/a');

    expect(fn() => $this->fake->{$method}(...$arguments))->toThrow(ExpectationFailedException::class);
})->with([
    'submitted' => ['assertSubmitted', ['/b']],
    'not submitted' => ['assertNotSubmitted', ['/a']],
    'nothing' => ['assertNothingSubmitted', []],
    'count' => ['assertSubmittedCount', [2]],
]);

it('records nothing for an empty list', function () {
    IndexNow::submit([]);

    $this->fake->assertNothingSubmitted();
});
