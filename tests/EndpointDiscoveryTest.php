<?php

declare(strict_types=1);

namespace Tests\Zegnat\Webmention;

use PHPUnit\Framework\TestCase;
use Masterminds\HTML5;
use Zegnat\Webmention\EndpointDiscovery;
use Http\Client\Curl\Client as Curl;
use Nyholm\Psr7\Factory\HttplugFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * @covers \Zegnat\Webmention\EndpointDiscovery
 */
class EndpointDiscoveryTest extends TestCase
{
    private static $webmention;

    public static function setUpBeforeClass()
    {
        $factory = new HttplugFactory();
        self::$webmention = new EndpointDiscovery(new Curl($factory, $factory), new Psr17Factory());
    }

    /**
     * @dataProvider webmentionRocks
     * @group internet
     */
    public function testWebmentionRocks(string $expected, string $url)
    {
        $this->assertRegExp('@^https://webmention.rocks/test/'. $expected, self::$webmention->discover($url));
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
}
