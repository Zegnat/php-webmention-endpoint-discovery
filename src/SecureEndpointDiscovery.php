<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

use DOMXPath;
use HTTP2;
use Http\Client\HttpClient;
use InvalidArgumentException;
use Masterminds\HTML5;
use Net_URL2;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class SecureEndpointDiscovery
{
    private $httpClient;
    private $requestFactory;
    private $dnsResolver;

    public function __construct(
        HttpClient $httpClient,
        RequestFactoryInterface $requestFactory,
        DNSResolverInterface $dnsResolver
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->dnsResolver = $dnsResolver;
    }

    /**
     * Ignore endpoints that loopback to the local machine or other special IP addresses.
     *
     * This is an extension to the usual discovery that tries to blacklist all IP addresses in the private and
     * reserved blocks. It is very much an experiment and should not be relied upon in production. (Yet.)
     *
     * @see https://webmention.net/draft/#avoid-sending-webmentions-to-localhost
     *
     * @return array Dictionary with three keys: `url` (string) for the discovered endpoint, `host` (string) for the
     *               host the DNS records were checked for, and `ips` (array) for all checked DNS values.
     */
    public function discover(string $url): ?array
    {
        $endpoint = null;
        $url = new Net_URL2($url);
        $url->normalize();

        // Argument was not a valid (or workable) URL:
        if (false === \in_array($url->getScheme(), ['http', 'https']) || false !== $url->getHost()) {
            throw new InvalidArgumentException('EndpointDiscovery::discover() requires an absolute HTTP URL.');
        }

        // HEAD request, following any redirects:
        do {
            $headRequest = $this->requestFactory->createRequest('HEAD', (string)$url);
            $response = $this->httpClient->sendRequest($headRequest);
        } while (\in_array($response->getStatusCode(), [301, 302, 307, 308]) &&
            [] !== $location = $response->getHeader('location') &&
            $url = $url->resolve($location[0]));

        // Check HTTP Link headers:
        if ([] !== $httpLinks = $response->getHeader('link')) {
            $linkParser = new HTTP2();
            foreach ($httpLinks as $httpLink) {
                $links = $linkParser->parseLinks($httpLink);
                foreach ($links as $link) {
                    if (\array_key_exists('rel', $link) &&
                        \in_array('webmention', \array_map('strtolower', $link['rel']))
                    ) {
                        $endpoint = $url->resolve($link['_uri']);
                    }
                }
            }
        }

        // If no endpoint was discovered in the Link headers, GET the HTML:
        if (null === $endpoint) {
            $getRequest = $this->requestFactory->createRequest('GET', (string)$url);
            $response = $this->httpClient->sendRequest($getRequest);
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
                $url = $url->resolve($base->item(0)->value);
            }
            $endpoint = $url->resolve($links->item(0)->value);
        }

        // No endpoint could be discovered.
        if (null === $endpoint) {
            return null;
        }

        // Find a valid (non-reserver and non-loopback) IP for the domain:
        $host = $endpoint->getHost();
        if (true === \filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } elseif ('[' === $host[0] && ']' === $host[strlen($host)-1] &&
            true === \filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $ip = substr($host, 1, -1);
        } else {
            $ip = $this->dnsResolver->resolve($host);
        }
        if (false === \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return [
            'url' => (string)$url,
            'host' => $host,
            'ip' => $ip,
        ];
    }
}
