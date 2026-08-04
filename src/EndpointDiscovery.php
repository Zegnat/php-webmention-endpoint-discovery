<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use HTTP2;
use Http\Client\HttpClient;
use InvalidArgumentException;
use Masterminds\HTML5;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class EndpointDiscovery
{
    private $httpClient;
    private $requestFactory;

    /**
     * Both PSR-18 and HTTPlug v1 declare the identical sendRequest() signature,
     * so either client type can serve all requests made during discovery.
     *
     * @param ClientInterface|HttpClient $httpClient A PSR-18 or HTTPlug v1 HTTP client.
     */
    public function __construct($httpClient, RequestFactoryInterface $requestFactory)
    {
        if (false === $httpClient instanceof ClientInterface && false === $httpClient instanceof HttpClient) {
            throw new InvalidArgumentException(
                'EndpointDiscovery::__construct() requires a PSR-18 or HTTPlug v1 HTTP client.'
            );
        }
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
    }

    /**
     * @return string|null The discovered endpoint URL, or null if none was advertised.
     */
    public function discover(string $url)
    {
        $url = new Uri($url);
        if (false === Uri::isAbsolute($url)) {
            throw new InvalidArgumentException('EndpointDiscovery::discover() requires an absolute URL.');
        }
        $headRequest = $this->requestFactory->createRequest('HEAD', (string)$url);
        $response = $this->httpClient->sendRequest($headRequest);
        while (\in_array($response->getStatusCode(), [301, 302, 307, 308]) &&
                [] !== $location = $response->getHeader('location')) {
            $url = UriResolver::resolve($url, new Uri($location[0]));
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('HEAD', (string)$url));
        }
        $linkParser = new HTTP2();
        foreach ($response->getHeader('link') as $line) {
            $links = $linkParser->parseLinks($line);
            foreach ($links as $link) {
                if (\array_key_exists('rel', $link) &&
                    \in_array('webmention', \array_map('strtolower', $link['rel']))
                ) {
                    return (string)UriResolver::resolve($url, new Uri($link['_uri']));
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
        if (0 !== ($base = $xpath->query('//html:base[@href][1]/@href'))->length) {
            $url = UriResolver::resolve($url, new Uri($base->item(0)->value));
        }
        return (string)UriResolver::resolve($url, new Uri($links->item(0)->value));
    }

    /**
     * Ignore endpoints that loopback to the local machine or other special IP addresses.
     *
     * This is an extension to the usual discovery that tries to blacklist all IP addresses in the private and
     * reserved blocks. It is very much an experiment and should not be relied upon in production. (Yet.)
     *
     * @see https://webmention.net/draft/#avoid-sending-webmentions-to-localhost
     *
     * @return array|null Dictionary with three keys: `url` (string) for the discovered endpoint, `host` (string) for
     *                    the host the DNS records were checked for, and `ips` (array) for all checked DNS values.
     *                    Null when no endpoint was found or it did not pass the checks.
     */
    public function secureDiscover(string $url)
    {
        $url = $this->discover($url);
        if (null === $url) {
            return null;
        }
        $host = (new Uri($url))->getHost();
        if (false === \filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = array_map(
                function (array $item): string {
                    return $item[$item['type'] === 'A' ? 'ip' : 'ipv6'];
                },
                \dns_get_record($host, DNS_A + DNS_AAAA)
            );
        } else {
            $ips = (array)$host;
        }
        foreach ($ips as $ip) {
            if (false === \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        return [
            'url' => $url,
            'host' => $host,
            'ips' => $ips,
        ];
    }
}
