<?php

namespace App\Domain\Data;

use InvalidArgumentException;

/**
 * Stops connectors being used to reach the platform itself (server-side request
 * forgery). Loopback, link-local (cloud metadata) and unspecified addresses are
 * always refused. Private ranges stay reachable, because on-premise databases
 * live there, unless `aixbi.connectors.block_private` is set.
 */
class OutboundGuard
{
    /** @param  callable(string): list<string>|null  $resolve  host → IP addresses (injectable for tests) */
    public function __construct(private readonly mixed $resolve = null) {}

    public function assertHostAllowed(string $host): void
    {
        $host = trim($host, '[]');
        if ($host === '') {
            throw new InvalidArgumentException('A host is required.');
        }
        // Hosts an operator has explicitly allowed (e.g. a database on the same machine during development).
        if (in_array(strtolower($host), array_map('strtolower', (array) config('aixbi.connectors.allow_hosts', [])), true)) {
            return;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHost($host);
        if ($ips === []) {
            throw new InvalidArgumentException("The host {$host} could not be resolved.");
        }
        foreach ($ips as $ip) {
            if ($reason = $this->blocked($ip)) {
                throw new InvalidArgumentException("Connections to {$host} are not allowed ({$reason}).");
            }
        }
    }

    public function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('Use an http or https URL.');
        }
        $this->assertHostAllowed($parts['host']);
    }

    private function blocked(string $ip): ?string
    {
        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $in = fn (string $cidr) => $this->inCidr($ip, $cidr);

        return match (true) {
            $v4 && ($in('127.0.0.0/8') || $in('0.0.0.0/8')), ! $v4 && ($ip === '::1' || $ip === '::') => 'loopback address',
            $v4 && $in('169.254.0.0/16'), ! $v4 && $in('fe80::/10') => 'link-local or metadata address',
            ! $v4 && str_starts_with(strtolower($ip), '::ffff:') => $this->blocked(substr($ip, 7)),
            (bool) config('aixbi.connectors.block_private') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false => 'private network address',
            default => null,
        };
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        if ($this->resolve !== null) {
            return ($this->resolve)($host);
        }
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }
}
