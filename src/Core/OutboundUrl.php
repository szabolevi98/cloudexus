<?php

namespace Cloudexus\Core;

/**
 * Küldhet-e a szerver kérést egy címre, amit valaki beírt — egy webhookéra —,
 * és pontosan melyik IP-re.
 *
 * Egy űrlapba írt címet a szerver a saját hálózata belsejéből látogat meg.
 * Enélkül a "http://127.0.0.1:3306" vagy a "http://169.254.169.254/..." arra
 * volna jó, hogy az alkalmazás olyan ajtókon kopogtasson, amiket kívülről
 * senki nem ér el. Ezért: csak http vagy https, és a név minden címének
 * nyilvánosnak kell lennie. Az ellenőrzött IP-hez kapcsolódik utána
 * (CURLOPT_RESOLVE), így egy név, ami az ellenőrzéskor nyilvános címre, egy
 * pillanattal később 127.0.0.1-re mutat, nem jut át a kettő között.
 *
 * A config.ini [webhooks] allow_private = 1 kikapcsolja a címellenőrzést, egy
 * fejlesztői gépnek, ami magának küld.
 */
final class OutboundUrl
{
    private const BLOCKED = ['100.64.0.0/10', '0.0.0.0/8', '169.254.0.0/16', 'fc00::/7', 'fe80::/10', '::/128', '::1/128'];

    /**
     * @return array{host: string, port: int, ip: string}
     * @throws \InvalidArgumentException a hiba fordítási kulcsával, ha a cím nem látogatható
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('webhooks.url_invalid');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($addresses === []) {
            throw new \InvalidArgumentException('webhooks.url_unresolved');
        }

        if (!(bool) Config::get('webhooks.allow_private', false)) {
            foreach ($addresses as $ip) {
                if (!self::isPublic($ip)) {
                    throw new \InvalidArgumentException('webhooks.url_private');
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublic(string $ip): bool
    {
        // IPv6-ként írt IPv4 cím (::ffff:127.0.0.1) az, ami.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        // A dns_get_record() nem olvassa a hosts fájlt; a gethostbynamel()
        // igen — egy fejlesztői gép "localhost"-jához ez kell.
        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
