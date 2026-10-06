<?php

declare(strict_types=1);

use Illuminate\Foundation\Console\AboutCommand;
use Kyzegs\IndexNow\IndexNow;

it('binds one instance per scope', function () {
    $first = app(IndexNow::class);

    expect(app(IndexNow::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(IndexNow::class))->not->toBe($first);
});

it('reports itself in about', function () {
    $this->artisan(AboutCommand::class, ['--only' => 'indexnow'])
        ->expectsOutputToContain('ENABLED')
        ->expectsOutputToContain('api.indexnow.org')
        ->assertSuccessful();
});

it('publishes its config', function () {
    $this->artisan('vendor:publish', ['--tag' => 'index-now-config'])->assertSuccessful();

    expect(config_path('index-now.php'))->toBeFile();

    unlink(config_path('index-now.php'));
});
