<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Kyzegs\IndexNow\Exceptions\IndexNowException;
use Kyzegs\IndexNow\IndexNow;
use Kyzegs\IndexNow\Support\SitemapReader;
use Kyzegs\IndexNow\Support\UrlList;

class SubmitCommand extends Command
{
    protected $signature = 'index-now:submit
        {urls?* : URLs or paths to submit}
        {--sitemap=* : Submit every URL in this sitemap (sitemap indexes are followed)}
        {--file= : Submit the URLs in this file, one per line}
        {--sync : Send now instead of queueing}
        {--dry-run : List the URLs without submitting them}';

    protected $description = 'Submit URLs to IndexNow';

    public function handle(IndexNow $indexNow, SitemapReader $sitemaps, Filesystem $files, Config $config): int
    {
        $root = $config->get('app.url');

        try {
            $urls = UrlList::normalise($this->collect($sitemaps, $files), is_string($root) ? $root : '');
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($urls === []) {
            $this->components->error('No URLs to submit. Pass URLs, --sitemap or --file.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($urls);
        }

        if (!$indexNow->isEnabled() || $indexNow->key() === null) {
            $this->components->warn('IndexNow is disabled or has no key; nothing will be sent.');
        }

        try {
            $this->option('sync') ? $this->submit($indexNow, $urls) : $this->queue($indexNow, $urls, $config);
        } catch (IndexNowException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collect(SitemapReader $sitemaps, Filesystem $files): array
    {
        /** @var list<string> $urls */
        $urls = (array) $this->argument('urls');

        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            array_push($urls, ...$this->readFile($files, $file));
        }

        foreach ((array) $this->option('sitemap') as $sitemap) {
            if (is_string($sitemap)) {
                array_push($urls, ...$sitemaps->urls($sitemap));
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function readFile(Filesystem $files, string $file): array
    {
        if (!$files->exists($file)) {
            throw new InvalidArgumentException("File [{$file}] does not exist.");
        }

        $lines = array_map(trim(...), preg_split('/\R/', $files->get($file)) ?: []);

        return array_values(array_filter($lines, fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }

    /**
     * @param  list<string>  $urls
     */
    private function dryRun(array $urls): int
    {
        foreach ($urls as $url) {
            $this->line($url);
        }

        $this->components->info(sprintf('%d URL(s) would be submitted.', count($urls)));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $urls
     */
    private function submit(IndexNow $indexNow, array $urls): void
    {
        $responses = $indexNow->submit($urls);

        $this->components->info(sprintf('Submitted %d URL(s) in %d request(s).', count($urls), count($responses)));
    }

    /**
     * @param  list<string>  $urls
     */
    private function queue(IndexNow $indexNow, array $urls, Config $config): void
    {
        $jobs = 0;

        $size = $config->get('index-now.batch_size', 10_000);

        foreach (array_chunk($urls, max(1, is_numeric($size) ? (int) $size : 10_000)) as $chunk) {
            $jobs += $indexNow->queue($chunk) !== null ? 1 : 0;
        }

        $this->components->info(sprintf('Queued %d URL(s) in %d job(s).', $jobs > 0 ? count($urls) : 0, $jobs));
    }
}
