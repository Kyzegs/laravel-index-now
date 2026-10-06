<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

use InvalidArgumentException;

final class UrlList
{
    /**
     * Trim, absolutise, strip fragments and dedupe. Relative paths resolve
     * against $root; anything that is not an http(s) URL with a host throws,
     * because the engines would reject the whole batch for it.
     *
     * @param  string|iterable<mixed>  $urls
     * @return list<string>
     */
    public static function normalise(string|iterable $urls, string $root): array
    {
        $normalised = [];

        foreach (is_string($urls) ? [$urls] : $urls as $url) {
            if (!is_string($url)) {
                throw new InvalidArgumentException(sprintf('IndexNow URLs must be strings, %s given.', get_debug_type($url)));
            }

            $url = trim($url);

            if ($url === '') {
                continue;
            }

            if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                $url = rtrim($root, '/') . $url;
            }

            $url = explode('#', $url, 2)[0];

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if (!in_array($scheme, ['http', 'https'], true) || self::host($url) === '') {
                throw new InvalidArgumentException("IndexNow cannot submit [{$url}]: it is not an absolute http(s) URL.");
            }

            $normalised[$url] = true;
        }

        return array_keys($normalised);
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, list<string>> host => urls, in first-seen order
     */
    public static function groupByHost(array $urls): array
    {
        $groups = [];

        foreach ($urls as $url) {
            $groups[self::host($url)][] = $url;
        }

        return $groups;
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function scheme(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME));
    }
}
