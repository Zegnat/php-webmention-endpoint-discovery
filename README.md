# Webmention Endpoint Discovery

Mostly a thought experiment to see what exactly is required to do [Webmention endpoints discovery](https://webmention.net/draft/#sender-discovers-receiver-webmention-endpoint). You can use this implementation on its own, though it will probably net you better results to tightly integrate the code within the rest of your application.

Under the licence of this project you should feel free to copy any parts of it that you want to take for inspiration! Not even attribution is required – even when appreciated.

## Install

Via Composer

``` bash
$ composer require zegnat/webmention-endpoint-discovery
```

## Usage

``` php
$discoverer = new Zegnat\Webmention\EndpointDiscovery($httpClient, $requestFactory);
echo $discoverer->discover('https://webmention.rocks/test/1');
// https://webmention.rocks/test/1/webmention\?head=true
```

* `$httpClient` must be an implementation of `HttpClient` as defined by [HTTPlug](http://httplug.io/). This will (hopefully soon) be replaced with PSR-18.
* `$requestFactory` must be an implementation of `RequestFactoryInterface` as defined by [PHP-FIG](https://www.php-fig.org/) as [PSR-17](https://www.php-fig.org/psr/psr-17/).

### Secure Endpoints

***NOTE:*** This feature is very much experimental. Please consult the code before deciding to use it.

There might be some weird issues triggered by having Webmention senders post mentions to things on their local machines. The Webmention specification calls on senders to [avoid sending Webmentions to localhost](https://webmention.net/draft/#avoid-sending-webmentions-to-localhost). This library enables that.

`EndpointDiscovery::secureDiscover` will return `null` not only when no endpoint could be found, but also when the found endpoint’s host’s DNS resolves to an IP address in the private or reserved range. When all the IP addresses found are deemed to be OK, the method will return an array as follows:

``` php
$discoverer = new Zegnat\Webmention\EndpointDiscovery($httpClient, $requestFactory);
var_export($discoverer->secureDiscover('https://webmention.rocks/test/1'));
// array (
//   'url' => 'https://webmention.rocks/test/1/webmention?head=true',
//   'host' => 'webmention.rocks',
//   'ips' =>
//   array (
//     0 => '173.230.155.197',
//   ),
// )
```

It is recommended that the Webmention sender uses this information for posting the mention. Use one of the checked IP addresses to post to (instead of the domain, which might redo the DNS lookup) with the host in a `Host` HTTP header.

## Testing

For quick testing without logging, coverage reports, or live calls to [Webmention Rocks!](https://webmention.rocks/):

``` bash
$ composer test -- --no-logging --no-coverage --exclude-group internet
```

Else:

``` bash
$ composer test
```
