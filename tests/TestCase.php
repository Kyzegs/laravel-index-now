<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Tests;

use Illuminate\Routing\RouteCollection;
use Kyzegs\IndexNow\IndexNowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public const KEY = 'a1b2c3d4e5f60718';

    protected function getPackageProviders($app): array
    {
        return [IndexNowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.com');
        $app['config']->set('index-now.enabled', true);
        $app['config']->set('index-now.key', self::KEY);
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Re-run the provider with new config, for settings read at boot (the key
     * route), starting from an empty route table.
     *
     * @param  array<string, mixed>  $config
     */
    public function rebootWith(array $config): void
    {
        config($config);
        $this->app['router']->setRoutes(new RouteCollection());
        $this->app->register(IndexNowServiceProvider::class, force: true);
    }
}
