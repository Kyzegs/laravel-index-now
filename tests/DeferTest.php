<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\Jobs\SubmitUrls;

it('queues one job for everything deferred during a request', function () {
    Queue::fake();

    Route::get('/save', function () {
        IndexNow::defer('/a');
        IndexNow::defer(['/b', '/a']);

        Queue::assertNothingPushed();

        return 'ok';
    });

    $this->get('/save')->assertOk();

    Queue::assertPushed(SubmitUrls::class, 1);
    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->urls === ['https://example.com/a', 'https://example.com/b']);
});

it('still queues when the response failed', function () {
    Queue::fake();

    Route::get('/fail', function () {
        IndexNow::defer('/a');
        abort(500);
    });

    $this->get('/fail')->assertStatus(500);

    Queue::assertPushed(SubmitUrls::class, 1);
});

it('can be flushed by hand', function () {
    Queue::fake();

    IndexNow::defer('/a');

    expect(IndexNow::flush())->not->toBeNull()
        ->and(IndexNow::flush())->toBeNull();

    Queue::assertPushed(SubmitUrls::class, 1);
});

it('registers nothing for an empty list', function () {
    IndexNow::defer([]);

    expect(Illuminate\Support\defer())->toHaveCount(0);
});
