# Laravel IndexNow

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kyzegs/laravel-index-now.svg?style=flat)](https://packagist.org/packages/kyzegs/laravel-index-now)
[![Tests](https://img.shields.io/github/actions/workflow/status/Kyzegs/laravel-index-now/run-tests.yml?branch=main&label=tests)](https://github.com/Kyzegs/laravel-index-now/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Quality assurance](https://img.shields.io/github/actions/workflow/status/Kyzegs/laravel-index-now/quality-assurance.yml?branch=main&label=quality%20assurance)](https://github.com/Kyzegs/laravel-index-now/actions/workflows/quality-assurance.yml?query=branch%3Amain)
[![License](https://img.shields.io/github/license/Kyzegs/laravel-index-now)](LICENSE.md)

Tell search engines about changed pages the moment they change, through the
[IndexNow](https://www.indexnow.org) protocol. One submission reaches every
participating engine (Bing, Yandex, Seznam, Naver, Yep…). Google does not take
part, so keep your sitemap.

- Sync, queued or **deferred** submissions: every URL touched during a request, job or command goes out as one job when it ends.
- **Debouncing**: a burst of edits to a page sends one ping.
- Failures are never silent: typed exceptions, queue retries with backoff, `Retry-After` honoured on 429.
- Key file served by a route, or written on deploy, from one `INDEXNOW_KEY`.
- Multiple hosts and endpoints, automatic batching past the 10,000-URL cap.
- An Eloquent trait that submits old and new URLs when a slug changes or a page is unpublished.
- `index-now:submit` for URLs, files and whole sitemaps.
- Events for every outcome and `IndexNow::fake()` for your tests.

Requires PHP 8.2+ and Laravel 11.23+, 12 or 13.

## Installation

```bash
composer require kyzegs/laravel-index-now
php artisan index-now:key --write-env
```

That writes a random `INDEXNOW_KEY` to `.env`. Submissions are only sent when
`INDEXNOW_ENABLED` is true, which by default means `APP_ENV=production`.

Publish the config to change anything else:

```bash
php artisan vendor:publish --tag=index-now-config
```

## The key file

The engines verify a submission by fetching `https://your-host/{key}.txt`. The
package answers that URL with a route, so nothing has to exist on disk.

When your web server answers `*.txt` itself (nginx `try_files`, a CDN, static
hosting), the route is never reached. Write the file on deploy instead:

```bash
php artisan index-now:publish-key   # writes public/{key_location}{key}.txt from INDEXNOW_KEY
```

and set `INDEXNOW_KEY_ROUTE=false`. Rotating the key is
`php artisan index-now:key --write-env --force` followed by a deploy.

## Usage

```php
use Kyzegs\IndexNow\Facades\IndexNow;

IndexNow::submit('https://example.com/blog/hello');   // now, in this process
IndexNow::queue(['/blog/hello', '/blog']);            // a queued job
IndexNow::queue('/blog/hello', delay: 600);           // a queued job, sent in 10 minutes
IndexNow::defer('/blog/hello');                       // one job for everything, after the response
```

Paths are resolved against `app.url`; fragments are dropped and duplicates
removed. Prefer injecting `Kyzegs\IndexNow\IndexNow` over the facade in your
own classes; the API is the same.

`submit()` returns one `Illuminate\Http\Client\Response` per request made and
an empty array when the submission was skipped. `queue()` returns the
`PendingDispatch`, or `null` when nothing would be sent.

### Which mode to use

| Mode | Use it when |
|---|---|
| `defer` | A request or job may touch the same pages several times: everything is sent once, in one job, when it ends. Works in HTTP requests, queued jobs and Artisan commands. |
| `queue` | You want a job now. With `INDEXNOW_DEBOUNCE` (or `delay:`), identical URL sets queued while one is waiting are dropped. |
| `submit` | You are already in a job or a command and want the result or the exception. |

### Debouncing

```dotenv
INDEXNOW_DEBOUNCE=600
```

Queued submissions then wait 10 minutes. The job is unique per URL set (order
does not matter), so while it waits, queueing the same URLs again is a no-op.
Uniqueness needs a cache store that supports locks.

## Eloquent

```php
use Kyzegs\IndexNow\Concerns\InteractsWithIndexNow;
use Kyzegs\IndexNow\Support\SubmissionMode;

class Post extends Model
{
    use InteractsWithIndexNow;

    public function indexNowUrls(): string|array
    {
        return route('posts.show', $this);
    }

    // Optional hooks, with their defaults:

    public function shouldSubmitToIndexNow(): bool
    {
        return true;                       // e.g. $this->published && ! $this->noindex
    }

    public function indexNowAttributes(): ?array
    {
        return null;                       // null = any change except timestamps
    }

    public function indexNowMode(): SubmissionMode
    {
        return SubmissionMode::Defer;
    }
}
```

| Event | Submitted |
|---|---|
| created | the URLs, if `shouldSubmitToIndexNow()` |
| updated | the new URLs if the model qualifies now, **and** the old URLs if it qualified before. A changed slug sends both; an unpublished page is sent so the engines see the 404 or noindex. |
| deleted | the URLs, if the model qualified |

## Artisan

```bash
php artisan index-now:submit https://example.com/a /b       # queue these
php artisan index-now:submit --sitemap=https://example.com/sitemap.xml
php artisan index-now:submit --file=urls.txt --sync         # one URL per line, # comments allowed
php artisan index-now:submit --sitemap=… --dry-run          # list, do not send
php artisan index-now:key [--write-env] [--file] [--force]
php artisan index-now:publish-key
php artisan about --only=indexnow
```

`--sitemap` follows sitemap indexes, reads gzipped sitemaps and can be given
more than once.

## Configuration

Every option has an environment variable.

| Option | Env | Default | |
|---|---|---|---|
| `enabled` | `INDEXNOW_ENABLED` | `APP_ENV === 'production'` | Off = every submission is skipped. |
| `key` | `INDEXNOW_KEY` | – | 8–128 of `a-z A-Z 0-9 -`. Blank = skipped. |
| `key_location` | `INDEXNOW_KEY_LOCATION` | `''` | `indexnow/` → `/indexnow/{key}.txt`; `indexnow-` → `/indexnow-{key}.txt`. |
| `key_route` | `INDEXNOW_KEY_ROUTE` | `true` | Serve the key file through a route (`index-now.key`). |
| `host` | `INDEXNOW_HOST` | host of `app.url` | |
| `hosts` | `INDEXNOW_HOSTS` | – | More hosts you serve, comma-separated. One request per host. |
| `foreign_urls` | `INDEXNOW_FOREIGN_URLS` | `skip` | `skip` or `throw` for URLs on other hosts. |
| `endpoints` | `INDEXNOW_ENDPOINTS` | `https://api.indexnow.org/indexnow` | Comma-separated; each batch goes to each. |
| `batch_size` | `INDEXNOW_BATCH_SIZE` | `10000` | URLs per request, capped at the protocol's 10,000. |
| `http.timeout` | `INDEXNOW_TIMEOUT` | `10` | Seconds. |
| `http.connect_timeout` | `INDEXNOW_CONNECT_TIMEOUT` | `5` | Seconds. |
| `http.retry.times` | `INDEXNOW_RETRY_TIMES` | `0` | In-request retries on connection errors and 5xx. |
| `http.retry.sleep` | `INDEXNOW_RETRY_SLEEP` | `100` | Milliseconds. |
| `http.user_agent` | `INDEXNOW_USER_AGENT` | – | |
| `queue.connection` | `INDEXNOW_QUEUE_CONNECTION` | default | |
| `queue.name` | `INDEXNOW_QUEUE` | default | |
| `queue.tries` | `INDEXNOW_TRIES` | `3` | |
| `queue.backoff` | `INDEXNOW_BACKOFF` | `60,300,900` | Seconds between attempts. |
| `queue.after_commit` | `INDEXNOW_AFTER_COMMIT` | `true` | Dispatch after open DB transactions commit. |
| `debounce` | `INDEXNOW_DEBOUNCE` | `0` | Seconds a queued submission waits. |
| `log.channel` | `INDEXNOW_LOG_CHANNEL` | default | |
| `log.skipped` | `INDEXNOW_LOG_SKIPPED` | `true` | Debug-level line per skipped submission. |
| `log.failed` | `INDEXNOW_LOG_FAILED` | `true` | Error-level line per refused request. |

Anything the config does not cover, such as proxies, headers or Guzzle
middleware, goes through the HTTP client hook, typically in a service
provider's `boot()`:

```php
use Illuminate\Http\Client\PendingRequest;
use Kyzegs\IndexNow\IndexNow;

IndexNow::configureHttpUsing(fn (PendingRequest $request) => $request->withOptions(['proxy' => 'http://proxy:8080']));
```

## Errors

All exceptions extend `Kyzegs\IndexNow\Exceptions\IndexNowException`.

| Exception | When |
|---|---|
| `InvalidKey` | 403: the key file is missing, unreachable or different. |
| `UrlsRejected` | 422: URLs do not belong to the host. |
| `RateLimited` | 429. `retryAfter()` reads the header; queued jobs are released for that long. |
| `RequestFailed` | Any other non-2xx or a connection error. The base of the three above; carries `endpoint`, `urls` and `response`. |
| `ForeignUrls` | URLs on unknown hosts with `foreign_urls=throw`. |
| `InvalidConfiguration` | Malformed key, no host, no endpoints. |

200 and 202 (received, key validation pending) count as success. With several
endpoints, every endpoint is tried before the first failure is thrown.

## Events

| Event | Properties |
|---|---|
| `Events\UrlsSubmitted` | `host`, `endpoint`, `urls`, `response` |
| `Events\SubmissionFailed` | `host`, `endpoint`, `urls`, `exception` |
| `Events\SubmissionSkipped` | `urls`, `reason` (`SkipReason::Disabled`, `MissingKey`, `ForeignHost`, `NoUrls`) |

## Testing your application

```php
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\Support\SubmissionMode;

$fake = IndexNow::fake();

$post->update(['slug' => 'new']);

$fake->assertSubmitted('/posts/new');
$fake->assertSubmitted('https://example.com/posts/old', SubmissionMode::Defer);
$fake->assertSubmitted(fn (array $urls, SubmissionMode $mode, ?int $delay) => count($urls) === 2);
$fake->assertNotSubmitted('/drafts/1');
$fake->assertSubmittedCount(1);
$fake->assertNothingSubmitted();
$fake->urls();          // every URL recorded
$fake->submissions();   // every call: urls, mode, delay
```

The fake records what your code asked to submit, in any mode, regardless of
`enabled`, the key or the host settings. It replaces the container binding as
well as the facade, so injected instances resolved after `fake()` are faked too.

## Upgrading from laravel-freelancer-nl/laravel-index-now

This package started as a fork of
[laravel-freelancer-nl/laravel-index-now](https://github.com/LaravelFreelancerNL/laravel-index-now)
and was rewritten.

- `composer remove laravel-freelancer-nl/laravel-index-now && composer require kyzegs/laravel-index-now`
- Namespace `LaravelFreelancerNL\LaravelIndexNow` → `Kyzegs\IndexNow`; the facade is `Kyzegs\IndexNow\Facades\IndexNow`.
- Republish the config. Keys are snake_case now: `key-location` → `key_location`, `search-engine` → `endpoints` (a list of full URLs), `log-failed-submits` → `log.skipped`, `delay` → `debounce` (default 0 instead of 600).
- `INDEXNOW_PRODUCTION_ENV` → `INDEXNOW_ENABLED`.
- `IndexNow::delaySubmission($urls, $seconds)` → `IndexNow::queue($urls, delay: $seconds)`.
- `IndexNow::submit()` throws on a refused request, and returns a list of responses (`[]` when skipped) instead of `Response|false`.
- `TooManyUrlsException` is gone: large lists are split into batches.
- `index-now:generate-key` → `index-now:key`; it no longer writes the key file by default. Use `--file`, `index-now:publish-key` or the key route.
- The single-URL GET request is gone; every submission is a JSON POST.

## Development

```bash
composer test
composer analyse
composer format
```

## Credits

- [Sebastiaan](https://github.com/Kyzegs)
- [Bas (LaravelFreelancerNL)](https://github.com/LaravelFreelancerNL), author of the original package

## License

MIT. See [LICENSE.md](LICENSE.md).
