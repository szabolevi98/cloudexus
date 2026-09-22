<?php

namespace Cloudexus\Core;

/**
 * The visitor's real IP address. Behind the Cloudflare proxy REMOTE_ADDR is a
 * Cloudflare edge server, and the visitor's address comes in the
 * CF-Connecting-IP header. The header is only believed when the request really
 * comes from a Cloudflare range; from anywhere else it could be forged, and
 * REMOTE_ADDR is used as is.
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
        $forwarded = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) && self::isCloudflare($remote)) {
            return $forwarded;
        }

        return $remote;
    }

    private static function isCloudflare(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach (self::CLOUDFLARE_RANGES as $range) {
            [$subnet, $bits] = explode('/', $range);
            $subnetPacked = inet_pton($subnet);
            if (strlen($subnetPacked) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
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
