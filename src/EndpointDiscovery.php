<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

use DOMXPath;
use Exception;
use HTTP2;
use Http\Client\HttpClient;
use Masterminds\HTML5;
use Net_URL2;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class EndpointDiscovery
{
    private $httpClient;
    private $requestFactory;

    public function __construct(HttpClient $httpClient, RequestFactoryInterface $requestFactory)
    {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
    }

    public function discover(string $url): ?string
    {
        $url = new Net_URL2($url);
        if (false === $url->isAbsolute()) {
            throw new Exception('EndpointDiscovery::discover() requires an absolute URL.');
        }
        $headRequest = $this->requestFactory->createRequest('HEAD', (string)$url);
        $response = $this->httpClient->sendRequest($headRequest);
        while (\in_array($response->getStatusCode(), [301, 302, 307, 308]) &&
                [] !== $location = $response->getHeader('location')) {
            $url = $url->resolve($location[0]);
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('HEAD', (string)$url));
        }
        $linkParser = new HTTP2();
        foreach ($response->getHeader('link') as $line) {
            $links = $linkParser->parseLinks($line);
            foreach ($links as $link) {
                if (\array_key_exists('rel', $link) &&
                    \in_array('webmention', \array_map('strtolower', $link['rel']))
                ) {
                    return (string)$url->resolve($link['_uri']);
                }
            }
        }
        $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', (string)$url));
        $parser = new HTML5();
        $html = $parser->loadHtml($response->getBody());
        $xpath = new DOMXPath($html);
        $xpath->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        $links = $xpath->query('//html:*[local-name()="link" or local-name()="a"]'.
                               '[@href and contains(concat(" ", @rel, " "), " webmention ")][1]/@href');
        if (0 === $links->length) {
            return null;
        }
        if (0 !== ($base = $xpath->query('//base[@href][1]/@href'))->length) {
            $url = $url->resolve($base->item(0)->value);
        }
        return (string)$url->resolve($links->item(0)->value);
    }
}
