<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kyzegs\IndexNow\Jobs\SubmitUrls;

function sitemap(string $root, array $locs): string
{
    $entries = implode('', array_map(fn(string $loc): string => $root === 'urlset' ? "<url><loc>{$loc}</loc></url>" : "<sitemap><loc>{$loc}</loc></sitemap>", $locs));

    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$root} xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">{$entries}</{$root}>";
}

it('queues the urls given as arguments', function () {
    Queue::fake();

    $this->artisan('index-now:submit', ['urls' => ['https://example.com/a', '/b']])
        ->expectsOutputToContain('Queued 2 URL(s) in 1 job(s).')
        ->assertSuccessful();

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->urls === ['https://example.com/a', 'https://example.com/b']);
});

it('queues one job per batch', function () {
    config(['index-now.batch_size' => 2]);
    Queue::fake();

    $this->artisan('index-now:submit', ['urls' => ['/a', '/b', '/c']])->assertSuccessful();

    Queue::assertPushed(SubmitUrls::class, 2);
});

it('sends right away with --sync', function () {
    Http::fake();

    $this->artisan('index-now:submit', ['urls' => ['/a'], '--sync' => true])
        ->expectsOutputToContain('Submitted 1 URL(s) in 1 request(s).')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('reports a refused submission', function () {
    Http::fake(['*' => Http::response('', 403)]);

    $this->artisan('index-now:submit', ['urls' => ['/a'], '--sync' => true])
        ->expectsOutputToContain('403')
        ->assertFailed();
});

it('reads urls from a file', function () {
    $path = sys_get_temp_dir() . '/index-now-urls.txt';
    File::put($path, "https://example.com/a\n\n# comment\n/b\r\n");
    Queue::fake();

    $this->artisan('index-now:submit', ['--file' => $path])->assertSuccessful();

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->urls === ['https://example.com/a', 'https://example.com/b']);

    File::delete($path);
});

it('fails on a missing file', function () {
    $this->artisan('index-now:submit', ['--file' => '/nope/urls.txt'])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('reads sitemaps, following indexes and gzip, each once', function () {
    Http::fake([
        'example.com/sitemap.xml' => Http::response(sitemap('sitemapindex', ['https://example.com/pages.xml', 'https://example.com/posts.xml.gz', 'https://example.com/sitemap.xml'])),
        'example.com/pages.xml' => Http::response(sitemap('urlset', ['https://example.com/', 'https://example.com/about'])),
        'example.com/posts.xml.gz' => Http::response(gzencode(sitemap('urlset', ['https://example.com/post', 'https://example.com/about']))),
    ]);
    Queue::fake();

    $this->artisan('index-now:submit', ['--sitemap' => ['https://example.com/sitemap.xml']])->assertSuccessful();

    Queue::assertPushed(SubmitUrls::class, fn(SubmitUrls $job): bool => $job->urls === ['https://example.com/', 'https://example.com/about', 'https://example.com/post']);
    Http::assertSentCount(3);
});

it('rejects something that is not a sitemap', function () {
    Http::fake(['*' => Http::response('<html></html>')]);

    $this->artisan('index-now:submit', ['--sitemap' => ['https://example.com/sitemap.xml']])
        ->expectsOutputToContain('is not a sitemap')
        ->assertFailed();
});

it('lists the urls on a dry run', function () {
    Queue::fake();
    Http::fake();

    $this->artisan('index-now:submit', ['urls' => ['/a', '/b'], '--dry-run' => true])
        ->expectsOutput('https://example.com/a')
        ->expectsOutput('https://example.com/b')
        ->expectsOutputToContain('2 URL(s) would be submitted.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('fails on invalid urls', function () {
    $this->artisan('index-now:submit', ['urls' => ['not a url']])
        ->expectsOutputToContain('not an absolute http(s) URL')
        ->assertFailed();
});

it('fails with nothing to submit', function () {
    $this->artisan('index-now:submit')
        ->expectsOutputToContain('No URLs to submit')
        ->assertFailed();
});

it('warns when nothing will be sent', function () {
    config(['index-now.enabled' => false]);
    Queue::fake();

    $this->artisan('index-now:submit', ['urls' => ['/a']])
        ->expectsOutputToContain('disabled or has no key')
        ->expectsOutputToContain('Queued 0 URL(s) in 0 job(s).')
        ->assertSuccessful();
});
