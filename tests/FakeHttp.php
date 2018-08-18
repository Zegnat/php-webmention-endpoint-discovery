<?php

namespace Tests\Zegnat\Webmention;

use Http\Client\HttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Request\Serializer as Request;
use Zend\Diactoros\Response\Serializer as Response;

class FakeHttp implements HttpClient
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $name = hash('sha256', Request::toString($request));
        return Response::fromString(file_get_contents(__DIR__ . '/responses/' . $name . '.txt'));
    }
}
