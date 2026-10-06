<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Kyzegs\IndexNow\Exceptions\InvalidConfiguration;

/**
 * Typed, forgiving reads of the index-now config, so a published config with
 * env() strings in it ("10", "true", "a,b") behaves like the shipped one.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class Settings
{
    public const KEY_PATTERN = '/^[A-Za-z0-9-]{8,128}$/';

    public function __construct(protected Config $config) {}

    public function enabled(): bool
    {
        return $this->bool('index-now.enabled', false);
    }

    public function key(): ?string
    {
        $key = $this->string('index-now.key');

        return $key !== null && trim($key) !== '' ? trim($key) : null;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public function keyFilePath(?string $key = null): ?string
    {
        $key ??= $this->key();

        return $key === null ? null : ltrim($this->string('index-now.key_location') ?? '', '/') . $key . '.txt';
    }

    public function host(): string
    {
        $host = $this->string('index-now.host') ?? UrlList::host($this->string('app.url') ?? '');

        return $host !== '' ? strtolower($host) : throw InvalidConfiguration::noHost();
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_values(array_unique([$this->host(), ...array_map(strtolower(...), $this->strings('index-now.hosts'))]));
    }

    /**
     * Root that relative paths resolve against.
     */
    public function root(): string
    {
        $url = $this->string('app.url') ?? '';

        return UrlList::host($url) !== '' ? rtrim($url, '/') : 'https://' . $this->host();
    }

    /**
     * @return list<string>
     */
    public function endpoints(): array
    {
        $endpoints = $this->strings('index-now.endpoints');

        return $endpoints !== [] ? $endpoints : throw InvalidConfiguration::noEndpoints();
    }

    /**
     * @return int<1, 10000>
     */
    public function batchSize(): int
    {
        return max(1, min(10_000, $this->int('index-now.batch_size', 10_000)));
    }

    public function throwOnForeignUrls(): bool
    {
        return match ($mode = $this->string('index-now.foreign_urls') ?? 'skip') {
            'skip' => false,
            'throw' => true,
            default => throw InvalidConfiguration::invalidForeignUrlsMode($mode),
        };
    }

    public function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * A list setting; a comma-separated string is accepted too.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->config->get($key, []);
        $values = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);

        return array_values(array_filter(
            array_map(fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $values),
            fn(string $item): bool => $item !== '',
        ));
    }
}
