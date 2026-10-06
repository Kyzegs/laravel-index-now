<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Kyzegs\IndexNow\IndexNow;

class GenerateKeyCommand extends Command
{
    protected $signature = 'index-now:key
        {--write-env : Store the key as INDEXNOW_KEY in the .env file}
        {--file : Also write the key file to the public directory}
        {--force : Replace an INDEXNOW_KEY that is already set}';

    protected $description = 'Generate an IndexNow key';

    public function handle(IndexNow $indexNow, Filesystem $files): int
    {
        $key = bin2hex(random_bytes(16));

        if ($this->option('write-env') && !$this->writeEnv($files, $key)) {
            return self::FAILURE;
        }

        if ($this->option('file')) {
            $path = public_path((string) $indexNow->keyFilePath($key));
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $key);
            $this->components->info("Key file written to [{$path}].");
        }

        if (!$this->option('write-env')) {
            $this->components->info('Add this to your .env file:');
            $this->line("INDEXNOW_KEY={$key}");
        }

        return self::SUCCESS;
    }

    private function writeEnv(Filesystem $files, string $key): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (!$files->exists($path)) {
            $this->components->error("No environment file at [{$path}].");

            return false;
        }

        $contents = $files->get($path);
        $pattern = '/^INDEXNOW_KEY=(.*)$/m';

        if (preg_match($pattern, $contents, $match) !== 1) {
            $files->put($path, rtrim($contents, "\n") . "\n\nINDEXNOW_KEY={$key}\n");
            $this->components->info("INDEXNOW_KEY written to [{$path}].");

            return true;
        }

        if (trim($match[1], " \t\"'") !== '' && !$this->option('force')) {
            $this->components->error('INDEXNOW_KEY is already set. Use --force to replace it; the engines will need the new key file before they accept submissions again.');

            return false;
        }

        $contents = (string) preg_replace($pattern, "INDEXNOW_KEY={$key}", $contents, 1);

        $files->put($path, $contents);
        $this->components->info("INDEXNOW_KEY written to [{$path}].");

        return true;
    }
}
