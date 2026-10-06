<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\Tests\TestCase;

afterEach(function () {
    File::delete(File::glob(public_path('*.txt')));
    File::deleteDirectory(public_path('indexnow'));
    File::delete(base_path('.env'));
});

it('builds the key file path from key_location', function (string $location, string $expected) {
    config(['index-now.key_location' => $location]);

    expect(IndexNow::keyFilePath())->toBe($expected)
        ->and(IndexNow::keyLocation('example.com'))->toBe('https://example.com/' . $expected);
})->with([
    'root' => ['', TestCase::KEY . '.txt'],
    'directory' => ['/indexnow/', 'indexnow/' . TestCase::KEY . '.txt'],
    'prefix' => ['indexnow-', 'indexnow-' . TestCase::KEY . '.txt'],
]);

it('has no key file without a key', function () {
    config(['index-now.key' => null]);

    expect(IndexNow::keyFilePath())->toBeNull()
        ->and(IndexNow::keyLocation('example.com'))->toBeNull();
});

it('validates keys', function (string $key, bool $valid) {
    expect(IndexNow::isValidKey($key))->toBe($valid);
})->with([
    ['abcd1234', true],
    ['ABCD-1234-efgh', true],
    [str_repeat('a', 128), true],
    ['abc1234', false],
    [str_repeat('a', 129), false],
    ['abcd_1234', false],
    ['abcd 1234', false],
]);

it('generates a key and prints it', function () {
    $this->artisan('index-now:key')
        ->expectsOutputToContain('INDEXNOW_KEY=')
        ->assertSuccessful();
});

it('writes a new key to the env file', function () {
    File::put(base_path('.env'), "APP_NAME=Test\nINDEXNOW_KEY=\n");

    $this->artisan('index-now:key --write-env')->assertSuccessful();

    expect(File::get(base_path('.env')))->toMatch('/^APP_NAME=Test\nINDEXNOW_KEY=[0-9a-f]{32}\n$/');
});

it('appends the key when the env file lacks it', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");

    $this->artisan('index-now:key --write-env')->assertSuccessful();

    expect(File::get(base_path('.env')))->toMatch('/^APP_NAME=Test\n\nINDEXNOW_KEY=[0-9a-f]{32}\n$/');
});

it('does not replace a key without --force', function () {
    File::put(base_path('.env'), "INDEXNOW_KEY=existing-key\n");

    $this->artisan('index-now:key --write-env')->assertFailed();

    expect(File::get(base_path('.env')))->toBe("INDEXNOW_KEY=existing-key\n");

    $this->artisan('index-now:key --write-env --force')->assertSuccessful();

    expect(File::get(base_path('.env')))->not->toContain('existing-key');
});

it('fails without an env file', function () {
    $this->artisan('index-now:key --write-env')->assertFailed();
});

it('can write the generated key file', function () {
    config(['index-now.key_location' => 'indexnow/']);

    $this->artisan('index-now:key --file')->assertSuccessful();

    $files = File::files(public_path('indexnow'));

    expect($files)->toHaveCount(1)
        ->and($files[0]->getContents() . '.txt')->toBe($files[0]->getFilename());
});

it('publishes the configured key file, creating its directory', function () {
    config(['index-now.key_location' => 'indexnow/']);

    $this->artisan('index-now:publish-key')->assertSuccessful();

    expect(File::get(public_path('indexnow/' . TestCase::KEY . '.txt')))->toBe(TestCase::KEY);
});

it('publishes nothing without a key', function () {
    config(['index-now.key' => null]);

    $this->artisan('index-now:publish-key')
        ->expectsOutputToContain('not set')
        ->assertSuccessful();

    expect(File::glob(public_path('*.txt')))->toBe([]);
});

it('refuses to publish a malformed key', function () {
    config(['index-now.key' => 'bad_key!']);

    $this->artisan('index-now:publish-key')->assertFailed();
});
