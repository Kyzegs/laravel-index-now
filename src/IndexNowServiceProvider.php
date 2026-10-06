<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Kyzegs\IndexNow\Commands\GenerateKeyCommand;
use Kyzegs\IndexNow\Commands\PublishKeyCommand;
use Kyzegs\IndexNow\Commands\SubmitCommand;
use Kyzegs\IndexNow\Http\KeyController;

class IndexNowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/index-now.php', 'index-now');

        $this->app->scoped(IndexNow::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/index-now.php' => config_path('index-now.php')], 'index-now-config');

            $this->commands([GenerateKeyCommand::class, PublishKeyCommand::class, SubmitCommand::class]);

            AboutCommand::add('IndexNow', fn(): array => [
                'Enabled' => $this->app->make(IndexNow::class)->isEnabled() ? '<fg=green;options=bold>ENABLED</>' : 'OFF',
                'Key' => $this->app->make(IndexNow::class)->key() !== null ? 'set' : '<fg=yellow;options=bold>NOT SET</>',
                'Endpoints' => implode(', ', $this->app->make(IndexNow::class)->endpoints()),
            ]);
        }

        $this->registerKeyRoute();
    }

    protected function registerKeyRoute(): void
    {
        $config = $this->app->make(Config::class);

        if (!filter_var($config->get('index-now.key_route', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $key = $config->get('index-now.key');
        $location = $config->get('index-now.key_location', '');

        if (!is_string($key) || !IndexNow::isValidKey(trim($key))) {
            return;
        }

        $path = ltrim(is_string($location) ? $location : '', '/') . trim($key) . '.txt';

        $this->app->make(Router::class)->get($path, KeyController::class)->name('index-now.key');
    }
}
