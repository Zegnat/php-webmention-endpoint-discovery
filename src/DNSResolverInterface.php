<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

interface DNSResolverInterface
{
    /**
     * Find the IP address behind a domain name.
     *
     * @param string $host the domain host, e.g. example.com
     * @return string the IP address the domain resolves to, e.g. 93.184.216.34
     */
    public function resolve(string $host): ?string;
}
