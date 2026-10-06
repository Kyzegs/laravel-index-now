<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Kyzegs\IndexNow\IndexNow;

/**
 * Writes the configured key to the public directory. Run it on deploy when
 * the web server serves *.txt itself, so the key route is never reached.
 */
class PublishKeyCommand extends Command
{
    protected $signature = 'index-now:publish-key';

    protected $description = 'Write the configured IndexNow key file to the public directory';

    public function handle(IndexNow $indexNow, Filesystem $files): int
    {
        $key = $indexNow->key();

        if ($key === null) {
            $this->components->info('INDEXNOW_KEY is not set; nothing to write.');

            return self::SUCCESS;
        }

        if (!IndexNow::isValidKey($key)) {
            $this->components->error('INDEXNOW_KEY must be 8 to 128 characters of a-z, A-Z, 0-9 and dashes.');

            return self::FAILURE;
        }

        $path = public_path((string) $indexNow->keyFilePath());
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $key);

        $this->components->info("Key file written to [{$path}].");

        return self::SUCCESS;
    }
}
