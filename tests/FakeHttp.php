<?php

namespace Tests\Zegnat\Webmention;

use GuzzleHttp\Psr7\Message;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FakeHttp implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $name = hash('sha256', Message::toString($request));
        return Message::parseResponse(file_get_contents(__DIR__ . '/responses/' . $name . '.txt'));
    }
}
