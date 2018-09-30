<?php

declare(strict_types=1);

namespace Zegnat\Webmention;

use Psr\SimpleCache\CacheInterface;

class DNSResolver implements DNSResolverInterface
{
    public const INCLUDE_IPV6 = 0b0001;
    public const PREFER_IPV6 = 0b0011;

    private $dnsCache;
    private $records = \DNS_A;
    private $ipv6 = false;

    public function __construct(CacheInterface $dnsCache, int $options = 0b0000)
    {
        $this->dnsCache = $dnsCache;
        if ($options & self::INCLUDE_IPV6) {
            $this->records += \DNS_AAAA;
        }
        if ($options & self::PREFER_IPV6) {
            $this->ipv6 = true;
        }
    }

    public function resolve(string $host): ?string
    {
        if (null !== $cache = $this->dnsCache->get($host)) {
            return $cache;
        }
        $records = \dns_get_record($host, $this->records);
        if (empty($records)) {
            return null;
        }
        $resolved = $records[0];
        if ('AAAA' !== $resolved['type'] && $this->ipv6) {
            foreach ($records as $record) {
                if ('AAAA' === $record['type']) {
                    $resolved = $record;
                    break;
                }
            }
        }
        $ip = $resolved['A' === $resolved['type'] ? 'ip' : 'ipv6'];
        $this->dnsCache->set($host, $ip, $resolved['ttl']);

        return $ip;
    }
}
