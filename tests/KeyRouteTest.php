<?php

declare(strict_types=1);

use Kyzegs\IndexNow\Tests\TestCase;

it('serves the key as plain text', function () {
    $this->get('/' . TestCase::KEY . '.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent(TestCase::KEY);

    expect(route('index-now.key', absolute: false))->toBe('/' . TestCase::KEY . '.txt');
});

it('serves nothing for another key', function () {
    $this->get('/0000000000000000.txt')->assertNotFound();
});

it('answers 404 when the key is removed at runtime', function () {
    config(['index-now.key' => null]);

    $this->get('/' . TestCase::KEY . '.txt')->assertNotFound();
});

describe('under key_location', function () {
    beforeEach(function () {
        $this->rebootWith(['index-now.key_location' => 'indexnow/']);
    });

    it('serves the key there', function () {
        $this->get('/indexnow/' . TestCase::KEY . '.txt')->assertOk()->assertContent(TestCase::KEY);
        $this->get('/' . TestCase::KEY . '.txt')->assertNotFound();
    });
});

describe('with the route off', function () {
    beforeEach(function () {
        $this->rebootWith(['index-now.key_route' => false]);
    });

    it('registers no route', function () {
        $this->get('/' . TestCase::KEY . '.txt')->assertNotFound();
    });
});

describe('with a malformed key', function () {
    beforeEach(function () {
        $this->rebootWith(['index-now.key' => '../etc/passwd']);
    });

    it('registers no route', function () {
        expect(app('router')->has('index-now.key'))->toBeFalse();
    });
});
