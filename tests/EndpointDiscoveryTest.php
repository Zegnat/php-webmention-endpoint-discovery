<?php

declare(strict_types=1);

namespace Tests\Zegnat\Webmention;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Http\Client\Curl\Client as Curl;
use Http\Client\HttpClient;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\ResponseFactory;
use Http\Factory\Guzzle\StreamFactory;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\StreamFactory\GuzzleStreamFactory;
use InvalidArgumentException;
use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Zegnat\Webmention\EndpointDiscovery;

/**
 * @coversDefaultClass \Zegnat\Webmention\EndpointDiscovery
 */
class EndpointDiscoveryTest extends TestCase
{
    private static $live;
    private static $offline;

    /**
     * Annotated instead of overriding setUpBeforeClass: PHPUnit 8+ declares that
     * method with a void return type, which PHP 7.0 cannot parse.
     *
     * @beforeClass
     */
    public static function setUpDiscoverers()
    {
        self::$live = new EndpointDiscovery(self::createLiveClient(), self::requestFactory());
        self::$offline = new EndpointDiscovery(new FakeHttp(), self::requestFactory());
    }

    /**
     * curl-client 2.x is PSR-18 and takes PSR-17 factories; 1.x is HTTPlug v1
     * and takes the factories from php-http/message. The PHP 7.0 leg can only
     * resolve 1.x, so support both.
     */
    private static function createLiveClient()
    {
        if (is_subclass_of(Curl::class, ClientInterface::class)) {
            return new Curl(self::responseFactory(), self::streamFactory());
        }
        return new Curl(new GuzzleMessageFactory(), new GuzzleStreamFactory());
    }

    /**
     * Guzzle only ships its own PSR-17 HttpFactory from psr7 2.0, which never
     * installs on PHP 7.0-7.2. Fall back to http-interop/http-factory-guzzle
     * there, which wraps guzzle psr7 1.x.
     */
    private static function requestFactory(): RequestFactoryInterface
    {
        return class_exists(HttpFactory::class) ? new HttpFactory() : new RequestFactory();
    }

    private static function responseFactory(): ResponseFactoryInterface
    {
        return class_exists(HttpFactory::class) ? new HttpFactory() : new ResponseFactory();
    }

    private static function streamFactory(): StreamFactoryInterface
    {
        return class_exists(HttpFactory::class) ? new HttpFactory() : new StreamFactory();
    }

    public function testConstruct()
    {
        $mockHttp = $this->createMock(HttpClient::class);
        $mockFactory = $this->createMock(RequestFactoryInterface::class);
        $this->assertInstanceOf(EndpointDiscovery::class, new EndpointDiscovery($mockHttp, $mockFactory));
    }

    public function testConstructWithPsr18Client()
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockFactory = $this->createMock(RequestFactoryInterface::class);
        $this->assertInstanceOf(EndpointDiscovery::class, new EndpointDiscovery($mockHttp, $mockFactory));
    }

    public function testConstructRejectsNonClient()
    {
        $this->expectException(InvalidArgumentException::class);
        new EndpointDiscovery('not a client', $this->createMock(RequestFactoryInterface::class));
    }

    public function testRelativeUrl()
    {
        $this->expectException(InvalidArgumentException::class);
        self::$offline->discover('/folder/endpoint');
    }

    /**
     * @dataProvider localTests
     * @covers ::discover
     * @covers ::secureDiscover
     */
    public function testLocal($expected, string $responseFile, string $baseUrl)
    {
        $response = Message::parseResponse(file_get_contents($responseFile));
        $fakeHttp = $this->createMock(HttpClient::class);
        $fakeHttp->method('sendRequest')->willReturn($response);
        $discoverer = new EndpointDiscovery($fakeHttp, self::requestFactory());
        $this->assertEquals($expected, $discoverer->discover($baseUrl));
        $this->assertEquals($expected, $discoverer->secureDiscover($baseUrl)['url']);
    }

    public function localTests()
    {
        return [
            'HTML <link> tag, relative URL, <base> tag required' => [
                'https://example.com/folder/endpoint',
                __DIR__ . '/responses/base-element.txt',
                'https://example.com/'
            ],
            'No endpoint' => [
                null,
                __DIR__ . '/responses/no-endpoint.txt',
                'https://example.com/'
            ],
            'Image file, no Link header' => [
                null,
                __DIR__ . '/responses/image-without-endpoint.txt',
                'https://example.com/png-transparent.png'
            ],
            'Image file, with Link header' => [
                'https://example.com/folder/endpoint',
                __DIR__ . '/responses/image-with-endpoint.txt',
                'https://example.com/png-transparent.png'
            ]
        ];
    }

    /**
     * @dataProvider loopbackTests
     * @covers ::secureDiscover
     */
    public function testSecure(string $endpoint)
    {
        $this->assertLoopbackRejected($endpoint);
    }

    public function loopbackTests()
    {
        return [
            ['http://192.168.1.1/'],
            ['https://127.0.0.1/'],
        ];
    }

    /**
     * These endpoints only get rejected when live DNS resolves their hostnames
     * to loopback addresses. nip.io provides the wildcard DNS xip.io once did.
     *
     * @dataProvider loopbackDnsTests
     * @covers ::secureDiscover
     * @group internet
     */
    public function testSecureDns(string $endpoint)
    {
        $this->assertLoopbackRejected($endpoint);
    }

    public function loopbackDnsTests()
    {
        return [
            ['https://localhost/malicious/endpoint'],
            ['http://127.0.0.1.nip.io/'],
        ];
    }

    private function assertLoopbackRejected(string $endpoint)
    {
        $response = self::responseFactory()->createResponse(200, 'OK')
            ->withHeader('Link', '<' . $endpoint . '>; rel="webmention"');
        $fakeHttp = $this->createMock(HttpClient::class);
        $fakeHttp->method('sendRequest')->willReturn($response);
        $discoverer = new EndpointDiscovery($fakeHttp, self::requestFactory());
        $this->assertEquals($endpoint, $discoverer->discover($endpoint));
        $this->assertNull($discoverer->secureDiscover($endpoint));
    }

    /**
     * assertRegExp was replaced by assertMatchesRegularExpression in PHPUnit 9.1
     * and warns from 9.3; PHPUnit 6.5 only has assertRegExp.
     */
    private function assertUrlMatches(string $pattern, $url)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $url);
        } else {
            $this->assertRegExp($pattern, $url);
        }
    }

    /**
     * @dataProvider webmentionRocks
     */
    public function testWebmentionRocks(string $expected, string $url)
    {
        $this->assertUrlMatches('@^https://webmention.rocks/test/'. $expected, self::$offline->discover($url));
    }

    /**
     * @dataProvider webmentionRocks
     * @group internet
     */
    public function testWebmentionRocksLive(string $expected, string $url)
    {
        $this->assertUrlMatches('@^https://webmention.rocks/test/'. $expected, self::$live->discover($url));
    }

    public function webmentionRocks()
    {
        return [
            'HTTP Link header, unquoted rel, relative URL'=> [
                '1/webmention\?head=true$@', 'https://webmention.rocks/test/1'
            ],
            'HTTP Link header, unquoted rel, absolute URL'=> [
                '2/webmention\?head=true$@', 'https://webmention.rocks/test/2'
            ],
            'HTML <link> tag, relative URL'=> [
                '3/webmention$@', 'https://webmention.rocks/test/3'
            ],
            'HTML <link> tag, absolute URL'=> [
                '4/webmention$@', 'https://webmention.rocks/test/4'
            ],
            'HTML <a> tag, relative URL'=> [
                '5/webmention$@', 'https://webmention.rocks/test/5'
            ],
            'HTML <a> tag, absolute URL'=> [
                '6/webmention$@', 'https://webmention.rocks/test/6'
            ],
            'HTTP Link header with strange casing'=> [
                '7/webmention\?head=true$@', 'https://webmention.rocks/test/7'
            ],
            'HTTP Link header, quoted rel'=> [
                '8/webmention\?head=true$@', 'https://webmention.rocks/test/8'
            ],
            'Multiple rel values on a <link> tag'=> [
                '9/webmention$@', 'https://webmention.rocks/test/9'
            ],
            'Multiple rel values on a Link header'=> [
                '10/webmention\?head=true$@', 'https://webmention.rocks/test/10'
            ],
            'Multiple Webmention endpoints advertised: Link, <link>, <a>'=> [
                '11/webmention$@', 'https://webmention.rocks/test/11'
            ],
            'Checking for exact match of rel=webmention'=> [
                '12/webmention$@', 'https://webmention.rocks/test/12'
            ],
            'False endpoint inside an HTML comment'=> [
                '13/webmention$@', 'https://webmention.rocks/test/13'
            ],
            'False endpoint in escaped HTML'=> [
                '14/webmention$@', 'https://webmention.rocks/test/14'
            ],
            'Webmention href is an empty string'=> [
                '15$@', 'https://webmention.rocks/test/15'
            ],
            'Multiple Webmention endpoints advertised: <a>, <link>'=> [
                '16/webmention$@', 'https://webmention.rocks/test/16'
            ],
            'Multiple Webmention endpoints advertised: <link>, <a>'=> [
                '17/webmention$@', 'https://webmention.rocks/test/17'
            ],
            'Multiple HTTP Link headers'=> [
                '18/webmention\?head=true$@', 'https://webmention.rocks/test/18'
            ],
            'Single HTTP Link header with multiple values'=> [
                '19/webmention\?head=true$@', 'https://webmention.rocks/test/19'
            ],
            'Link tag with no href attribute'=> [
                '20/webmention$@', 'https://webmention.rocks/test/20'
            ],
            'Webmention endpoint has query string parameters'=> [
                '21/webmention\?query=yes$@', 'https://webmention.rocks/test/21'
            ],
            'Webmention endpoint is relative to the path'=> [
                '22/webmention$@', 'https://webmention.rocks/test/22'
            ],
            'Webmention target is a redirect and the endpoint is relative'=> [
                '23/page/webmention-endpoint/[^/]+$@', 'https://webmention.rocks/test/23/page'
            ],
        ];
    }

    /**
     * @group internet
     */
    public function testSecureDiscovery()
    {
        $discover = self::$live->secureDiscover('https://webmention.rocks/test/1');
        $this->assertArrayHasKey('url', $discover);
        $this->assertSame('https://webmention.rocks/test/1/webmention?head=true', $discover['url']);
        $this->assertArrayHasKey('host', $discover);
        $this->assertSame('webmention.rocks', $discover['host']);
        $this->assertArrayHasKey('ips', $discover);
        $this->assertTrue(is_array($discover['ips']));
        // Do not check exact values of the DNS lookup, instead make sure there are some:
        $this->assertNotCount(0, $discover['ips']);
    }
}
