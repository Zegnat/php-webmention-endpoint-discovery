# Webmention Endpoint Discovery

Mostly a thought experiment to see what exactly is required to do
[Webmention endpoints discovery][discovery]. You can use this
implementation on its own, though it will probably net you better results
to tightly integrate the code within the rest of your application.

Under the licence of this project you should feel free to copy any parts
of it that you want to take for inspiration! Not even attribution is
required – even when appreciated.

[discovery]: https://webmention.net/draft/#sender-discovers-receiver-webmention-endpoint

## Install

Via Composer

``` bash
$ composer require zegnat/webmention-endpoint-discovery
```

## Usage

``` php
$discoverer = new Zegnat\Webmention\EndpointDiscovery($httpClient, $requestFactory);
echo $discoverer->discover('https://webmention.rocks/test/1');
// https://webmention.rocks/test/1/webmention?head=true
```

* `$httpClient` must be an HTTP client implementing `ClientInterface` as
  defined by [PSR-18](https://www.php-fig.org/psr/psr-18/). Clients
  implementing `HttpClient` as defined by [HTTPlug](http://httplug.io/)
  1.x are also still accepted.
* `$requestFactory` must be an implementation of `RequestFactoryInterface`
  as defined by [PHP-FIG](https://www.php-fig.org/) as
  [PSR-17](https://www.php-fig.org/psr/psr-17/).

### Secure Endpoints

***NOTE:*** This feature is very much experimental. Please consult the
code before deciding to use it.

There might be some weird issues triggered by having Webmention senders
post mentions to things on their local machines. The Webmention
specification calls on senders to [avoid sending Webmentions to
localhost][localhost]. This library enables that.

`EndpointDiscovery::secureDiscover` will return `null` not only when no
endpoint could be found, but also when the found endpoint’s host’s DNS
resolves to an IP address in the private or reserved range. When all the
IP addresses found are deemed to be OK, the method will return an array
as follows:

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

It is recommended that the Webmention sender uses this information for
posting the mention. Use one of the checked IP addresses to post to
(instead of the domain, which might redo the DNS lookup) with the host
in a `Host` HTTP header.

[localhost]: https://webmention.net/draft/#avoid-sending-webmentions-to-localhost

## Supported PHP versions

This library requires PHP 7.0 or newer and is tested against PHP 7.0, 7.1,
7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5.

## Testing

Tests run on every supported PHP version through Docker, orchestrated by
[Castor][castor]. Each PHP version and its dependencies live entirely in their
container, so only Docker and Castor are needed on the host:

``` bash
$ castor matrix     # run the whole matrix
$ castor test 8.4   # run a single version
$ castor coverage   # run with code coverage and enforce the minimum
```

The tests that make live calls to [Webmention Rocks!](https://webmention.rocks/)
are marked with `@group internet`. Pass `--offline` to `castor test` or
`castor matrix` to exclude them:

``` bash
$ castor test 8.4 --offline
```

The hash-named test fixtures under `tests/responses` are verbatim captures of
Webmention Rocks! responses under its license; see [CREDITS.md](CREDITS.md)
for their source and the `LICENSES` directory ([REUSE][reuse]).

[castor]: https://castor.jolicode.com/
[reuse]: https://reuse.software/

## License

The BSD Zero Clause License (0BSD). Please see the LICENSE file for more
information.
