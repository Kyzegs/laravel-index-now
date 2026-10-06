<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Exceptions;

class InvalidConfiguration extends IndexNowException
{
    public static function invalidKey(): self
    {
        return new self('The IndexNow key must be 8 to 128 characters of a-z, A-Z, 0-9 and dashes.');
    }

    public static function noHost(): self
    {
        return new self('IndexNow has no host: set index-now.host or app.url.');
    }

    public static function noEndpoints(): self
    {
        return new self('IndexNow has no endpoints configured.');
    }

    public static function invalidForeignUrlsMode(string $mode): self
    {
        return new self("index-now.foreign_urls must be 'skip' or 'throw', [{$mode}] given.");
    }
}
