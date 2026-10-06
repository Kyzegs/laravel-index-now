<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Http;

use Illuminate\Http\Response;
use Kyzegs\IndexNow\IndexNow;

class KeyController
{
    public function __invoke(IndexNow $indexNow): Response
    {
        $key = $indexNow->key();

        abort_if($key === null, 404);

        return new Response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
