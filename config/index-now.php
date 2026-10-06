<?php

declare(strict_types=1);

$csv = static fn(mixed $value): array => array_values(array_filter(array_map(trim(...), explode(',', is_string($value) ? $value : '')), fn(string $item): bool => $item !== ''));

return [

    /*
    | Master switch. Off by default outside production, so local and staging
    | never ping the engines. Skipped submissions still fire SubmissionSkipped.
    */
    'enabled' => (bool) env('INDEXNOW_ENABLED', env('APP_ENV', 'production') === 'production'),

    /*
    | The key the engines verify by fetching {key_location}{key}.txt from your
    | host: 8-128 characters of a-z, A-Z, 0-9 and dashes. Generate one with
    | `php artisan index-now:key --write-env`. Blank turns every submission
    | into a skip instead of a rejected request.
    */
    'key' => env('INDEXNOW_KEY'),

    /*
    | Path or prefix of the key file inside the public root: '' serves
    | /{key}.txt, 'indexnow/' serves /indexnow/{key}.txt and 'indexnow-'
    | serves /indexnow-{key}.txt. The keyLocation URL sent with every request
    | is derived from it.
    */
    'key_location' => env('INDEXNOW_KEY_LOCATION', ''),

    /*
    | Serve the key file through a route, so no file has to exist on disk.
    | Turn it off when you write the file with `index-now:publish-key`, or when
    | your web server answers *.txt itself and the route would never be hit.
    */
    'key_route' => (bool) env('INDEXNOW_KEY_ROUTE', true),

    /*
    | The host submissions are made for. Null uses the host of app.url.
    */
    'host' => env('INDEXNOW_HOST'),

    /*
    | Other hosts this application serves and holds the key file on. URLs are
    | grouped per host, one request each.
    */
    'hosts' => $csv(env('INDEXNOW_HOSTS')),

    /*
    | What to do with URLs whose host is neither `host` nor in `hosts`: 'skip'
    | drops them (logged, SubmissionSkipped fired), 'throw' raises ForeignUrls.
    */
    'foreign_urls' => env('INDEXNOW_FOREIGN_URLS', 'skip'),

    /*
    | Endpoints every batch is posted to. Engines share submissions with each
    | other, so one is enough; list several to not depend on that. Known ones:
    | https://api.indexnow.org/indexnow, https://www.bing.com/indexnow,
    | https://yandex.com/indexnow, https://search.seznam.cz/indexnow,
    | https://searchadvisor.naver.com/indexnow, https://indexnow.yep.com/indexnow
    */
    'endpoints' => $csv(env('INDEXNOW_ENDPOINTS', 'https://api.indexnow.org/indexnow')),

    /*
    | URLs per request. The protocol caps it at 10,000; larger lists are split.
    */
    'batch_size' => (int) env('INDEXNOW_BATCH_SIZE', 10_000),

    'http' => [
        'timeout' => (int) env('INDEXNOW_TIMEOUT', 10),
        'connect_timeout' => (int) env('INDEXNOW_CONNECT_TIMEOUT', 5),
        // In-request retries on connection errors and 5xx. Queued jobs also
        // retry on their own (see queue.tries), so this is usually left at 0.
        'retry' => [
            'times' => (int) env('INDEXNOW_RETRY_TIMES', 0),
            'sleep' => (int) env('INDEXNOW_RETRY_SLEEP', 100),
        ],
        'user_agent' => env('INDEXNOW_USER_AGENT'),
    ],

    'queue' => [
        'connection' => env('INDEXNOW_QUEUE_CONNECTION'),
        'name' => env('INDEXNOW_QUEUE'),
        'tries' => (int) env('INDEXNOW_TRIES', 3),
        // Seconds between attempts; a list sets one value per retry.
        'backoff' => array_map(intval(...), $csv(env('INDEXNOW_BACKOFF', '60,300,900'))),
        // Wait for open database transactions to commit, so a rolled-back
        // save never reaches the engines.
        'after_commit' => (bool) env('INDEXNOW_AFTER_COMMIT', true),
    ],

    /*
    | Seconds a queued submission waits before it is sent. While it waits, an
    | identical URL set is not queued again, so a burst of edits to one page
    | sends one ping. 0 sends right away.
    */
    'debounce' => (int) env('INDEXNOW_DEBOUNCE', 0),

    'log' => [
        // Null uses the default log channel.
        'channel' => env('INDEXNOW_LOG_CHANNEL'),
        'skipped' => (bool) env('INDEXNOW_LOG_SKIPPED', true),
        'failed' => (bool) env('INDEXNOW_LOG_FAILED', true),
    ],

];
