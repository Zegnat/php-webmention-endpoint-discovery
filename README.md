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

## Testing

For quick testing without logging, coverage reports, or live calls to [Webmention Rocks!](https://webmention.rocks/):

``` bash
$ composer test -- --no-logging --no-coverage --exclude-group internet
```

Else:

``` bash
$ composer test
```
