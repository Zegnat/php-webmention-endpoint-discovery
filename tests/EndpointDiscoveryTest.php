<?php

declare(strict_types=1);

namespace Tests\Zegnat\Webmention;

use Http\Client\Curl\Client as Curl;
use Http\Client\HttpClient;
use InvalidArgumentException;
use Masterminds\HTML5;
use Nyholm\Psr7\Factory\HttplugFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Zegnat\Webmention\EndpointDiscovery;
use Zend\Diactoros\Response\Serializer as Response;

/**
 * @coversDefaultClass \Zegnat\Webmention\EndpointDiscovery
 */
class EndpointDiscoveryTest extends TestCase
{
    private static $live;
    private static $offline;

    public static function setUpBeforeClass()
    {
        $factory = new HttplugFactory();
        self::$live = new EndpointDiscovery(new Curl($factory, $factory), new Psr17Factory());
        self::$offline = new EndpointDiscovery(new FakeHttp(), new Psr17Factory());
    }

    public function testConstruct()
    {
        $mockHttp = $this->createMock(HttpClient::class);
        $mockFactory = $this->createMock(RequestFactoryInterface::class);
        $this->assertInstanceOf(EndpointDiscovery::class, new EndpointDiscovery($mockHttp, $mockFactory));
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
    public function testLocal(?string $expected, string $responseFile, string $baseUrl)
    {
        $response = Response::fromString(file_get_contents($responseFile));
        $fakeHttp = $this->createMock(HttpClient::class);
        $fakeHttp->method('sendRequest')->willReturn($response);
        $discoverer = new EndpointDiscovery($fakeHttp, new Psr17Factory());
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
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200, 'OK')
            ->withHeader('Link', '<' . $endpoint . '>; rel="webmention"');
        $fakeHttp = $this->createMock(HttpClient::class);
        $fakeHttp->method('sendRequest')->willReturn($response);
        $discoverer = new EndpointDiscovery($fakeHttp, $factory);
        $this->assertEquals($endpoint, $discoverer->discover($endpoint));
        $this->assertEquals(null, $discoverer->secureDiscover($endpoint));
    }

    public function loopbackTests()
    {
        return [
            ['https://localhost/malicious/endpoint'],
            ['http://192.168.1.1/'],
            ['https://127.0.0.1/'],
            ['http://127.0.0.1.xip.io/'],
        ];
    }

    /**
     * @dataProvider webmentionRocks
     */
    public function testWebmentionRocks(string $expected, string $url)
    {
        $this->assertRegExp('@^https://webmention.rocks/test/'. $expected, self::$offline->discover($url));
    }

    /**
     * @dataProvider webmentionRocks
     * @group internet
     */
    public function testWebmentionRocksLive(string $expected, string $url)
    {
        $this->assertRegExp('@^https://webmention.rocks/test/'. $expected, self::$live->discover($url));
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
        $this->assertInternalType('array', $discover['ips']);
        // Do not check exact values of the DNS lookup, instead make sure there are some:
        $this->assertNotCount(0, $discover['ips']);
    }
}
