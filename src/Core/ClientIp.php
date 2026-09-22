<?php

namespace Cloudexus\Core;

/**
 * The visitor's real IP address, for the API request log and the sign-in
 * throttle. Behind a reverse proxy REMOTE_ADDR is the proxy, and the visitor's
 * address travels in a header, which is only believed when the request comes
 * from a proxy that is trusted to set it; from anywhere else it could be forged.
 *
 * - Cloudflare needs no configuration: CF-Connecting-IP is used when the
 *   request comes from a published Cloudflare range, and never otherwise, so
 *   an installation without Cloudflare is unaffected.
 * - Any other proxy (nginx, a load balancer, another CDN) is configured in
 *   config.ini: [proxy] trusted_proxies lists its addresses or CIDR ranges,
 *   and client_ip_header names the header it sets (default X-Forwarded-For).
 *
 * With neither, REMOTE_ADDR is used as is.
 */
class ClientIp
{
    /** https://www.cloudflare.com/ips/ */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function get(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        $trusted = self::trustedProxies();
        if ($trusted && self::inRanges($remote, $trusted)) {
            $fromHeader = self::fromForwardedHeader($trusted);
            if ($fromHeader !== null) {
                return $fromHeader;
            }
        }

        $cloudflare = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cloudflare !== '' && filter_var($cloudflare, FILTER_VALIDATE_IP) && self::inRanges($remote, self::CLOUDFLARE_RANGES)) {
            return $cloudflare;
        }

        return $remote;
    }

    /**
     * The client address from the configured header. X-Forwarded-For may hold a
     * chain ("client, proxy1, proxy2"); anything left of an untrusted hop could
     * be forged by the client, so the chain is read from the right and the
     * first address that is not a trusted proxy wins.
     */
    private static function fromForwardedHeader(array $trusted): ?string
    {
        $header = trim((string) Config::get('proxy.client_ip_header', 'X-Forwarded-For'));
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header ?: 'X-Forwarded-For'));
        $chain = array_map('trim', explode(',', (string) ($_SERVER[$key] ?? '')));

        foreach (array_reverse($chain) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;
            }
            if (!self::inRanges($ip, $trusted)) {
                return $ip;
            }
        }

        return null;
    }

    /** @return string[] */
    private static function trustedProxies(): array
    {
        $raw = (string) Config::get('proxy.trusted_proxies', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** @param string[] $ranges Single addresses or CIDR ranges, IPv4 or IPv6. */
    private static function inRanges(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $subnetPacked = @inet_pton($subnet);
            if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (strncmp($packed, $subnetPacked, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($subnetPacked[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
