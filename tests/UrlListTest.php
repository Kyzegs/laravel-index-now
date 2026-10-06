<?php

declare(strict_types=1);

use Kyzegs\IndexNow\Support\UrlList;

it('trims, strips fragments and dedupes', function () {
    expect(UrlList::normalise([' https://example.com/a ', 'https://example.com/a#top', '', 'https://example.com/b'], 'https://example.com'))
        ->toBe(['https://example.com/a', 'https://example.com/b']);
});

it('resolves paths against the root', function () {
    expect(UrlList::normalise('/blog/post', 'https://example.com/'))->toBe(['https://example.com/blog/post']);
});

it('keeps query strings', function () {
    expect(UrlList::normalise('https://example.com/search?q=a', 'https://example.com'))->toBe(['https://example.com/search?q=a']);
});

it('rejects anything that is not an absolute http(s) url', function (mixed $url) {
    UrlList::normalise([$url], 'https://example.com');
})->with(['relative' => 'blog/post', 'mailto' => 'mailto:a@example.com', 'protocol-relative' => '//example.com/a', 'no host' => 'https:///a', 'not a string' => 42])
    ->throws(InvalidArgumentException::class);

it('groups by lowercased host in first-seen order', function () {
    expect(UrlList::groupByHost(['https://B.example.com/1', 'https://a.example.com/2', 'https://b.example.com/3']))->toBe([
        'b.example.com' => ['https://B.example.com/1', 'https://b.example.com/3'],
        'a.example.com' => ['https://a.example.com/2'],
    ]);
});

it('accepts generators', function () {
    $urls = (function () {
        yield 'https://example.com/a';
        yield 'https://example.com/b';
    })();

    expect(UrlList::normalise($urls, 'https://example.com'))->toBe(['https://example.com/a', 'https://example.com/b']);
});
