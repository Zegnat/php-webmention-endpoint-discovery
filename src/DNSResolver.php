<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

use Psr\SimpleCache\CacheInterface;

class DNSResolver implements DNSResolverInterface
{
    private $dnsCache;

    public function __construct(CacheInterface $dnsCache)
    {
        $this->dnsCache = $dnsCache;
    }

    public function resolve(string $host): ?string
    {
        if (null !== $cache = $this->dnsCache->get($host)) {
            return $cache;
        }
        $records = \dns_get_record($host, DNS_A + DNS_AAAA);
        $first = \array_pop($records);
        $ip = $first[$first['type'] === 'A' ? 'ip' : 'ipv6'];
        $this->dnsCache->set($host, $ip, $first['ttl']);
        return $ip;
    }
}
