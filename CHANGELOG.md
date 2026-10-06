# Changelog

All notable changes to `laravel-index-now` will be documented in this file.

## v1.0.0 - Unreleased

First release as `kyzegs/laravel-index-now`, a rewrite of `laravel-freelancer-nl/laravel-index-now` v2.1.0. See the upgrade notes in the README.

### Added

- `IndexNow::queue()` with configurable connection, queue, tries, backoff, after-commit and debounce; jobs are unique per URL set regardless of order.
- `IndexNow::defer()`: everything deferred during a request, job or command is queued as one job when it ends.
- Multiple hosts (one request per host), multiple endpoints, automatic batching above `batch_size` (max 10,000).
- Relative paths resolve against `app.url`; fragments are stripped and duplicates removed.
- Typed exceptions for 403, 422 and 429 (with `retryAfter()`), connection errors and bad configuration.
- `UrlsSubmitted`, `SubmissionFailed` and `SubmissionSkipped` events; failure and skip logging with a configurable channel.
- HTTP timeouts, in-request retries, user agent and an `IndexNow::configureHttpUsing()` hook.
- Key route serving `{key_location}{key}.txt`, `index-now:publish-key`, `index-now:key --write-env/--file/--force`.
- `index-now:submit` for URLs, files and sitemaps (indexes and gzip included), with `--sync` and `--dry-run`.
- `InteractsWithIndexNow` Eloquent trait.
- `IndexNow::fake()` with assertions.
- `about` command section.

### Fixed

- Refused submissions were reported as successes: responses were never checked, there was no timeout and the queued job never retried.
- A missing key was sent as `key=""` instead of skipping.
- Generating a key always replaced the key file with a new random key, which broke deploys that rebuild `public/`.
- The facade advertised a non-existent `getKeyFileName()` method and the wrong `submit()` return type.

### Changed

- Config keys are snake_case; `production-env` is replaced by `enabled`, `search-engine` by `endpoints`, `delay` by `debounce`.
- Every submission is a JSON POST.
- Dropped the `nunomaduro/termwind`, `guzzlehttp/guzzle` (now through `illuminate/http`) and `spatie/laravel-package-tools` dependencies.
- PHPStan runs at level max without excluded files.

## v2.1.0 - 2026-03-21

### Added

- Added Laravel 13 support.

### Fixed

- Fixed `INDEXNOW_PRODUCTION_ENV=false` so production-only submit gating can be disabled completely.
- Fixed `keyLocation` handling to send the public key file URL derived from `APP_URL`, with a fallback to `https://<host>` when `APP_URL` is empty.
- Fixed bulk URL submissions to send the expected `host` value and `urlList` payload.
- Fixed the registered `IndexNow` facade alias.

### Documentation

- Clarified the difference between `INDEXNOW_PRODUCTION_ENV` and `INDEXNOW_LOG_FAILED_SUBMITS`.
- Clarified how `INDEXNOW_KEY_LOCATION` maps to the public `keyLocation` URL.

## v2.0.1 - 2025-08-27

Moved Pest dep to dev-reqs

## v2.0.0 - 2025-03-13

### Changes

- Dropped Laravel 10 support
- Added Laravel 11 support

## v1.3.1 - 2025-01-05

Fixes php 8.4 implicit nullable type deprecation.

Thanks @it-can

## 1.3 - 2024-03-17

Laravel 11 support

## 1.2

Laravel 10 support

## 1.1 - 2022-06-19

Index submissions are only made on the configured production environment.

## 1.0 - 2022-06-16

IndexNow API wrapper for Laravel
