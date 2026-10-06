<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Reads the <loc> entries of a sitemap, following sitemap indexes. Gzipped
 * sitemaps are inflated; each sitemap is fetched once, so a loop ends.
 */
class SitemapReader
{
    public function __construct(protected Http $http) {}

    /**
     * @return list<string>
     */
    public function urls(string $sitemap, int $maxDepth = 5): array
    {
        $urls = [];
        $visited = [];
        $this->read($sitemap, $maxDepth, $urls, $visited);

        return array_keys($urls);
    }

    /**
     * @param  array<string, true>  $urls
     * @param  array<string, true>  $visited
     */
    protected function read(string $sitemap, int $depth, array &$urls, array &$visited): void
    {
        if ($depth < 0 || isset($visited[$sitemap])) {
            return;
        }

        $visited[$sitemap] = true;
        $xml = $this->parse($sitemap, $this->http->get($sitemap)->throw()->body());

        if ($xml->getName() === 'sitemapindex') {
            foreach ($xml->children() as $child) {
                $this->read(trim((string) $child->loc), $depth - 1, $urls, $visited);
            }

            return;
        }

        foreach ($xml->children() as $child) {
            $loc = trim((string) $child->loc);

            if ($loc !== '') {
                $urls[$loc] = true;
            }
        }
    }

    protected function parse(string $sitemap, string $body): SimpleXMLElement
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) gzdecode($body);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false || !in_array($xml->getName(), ['urlset', 'sitemapindex'], true)) {
            throw new InvalidArgumentException("[{$sitemap}] is not a sitemap.");
        }

        return $xml;
    }
}
