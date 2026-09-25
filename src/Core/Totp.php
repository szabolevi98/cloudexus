<?php

namespace Cloudexus\Core;

/**
 * Időalapú egyszer használatos jelszavak (RFC 6238): a hat számjegy, amit egy
 * hitelesítő alkalmazás mutat, harminc másodpercenként újat.
 *
 * Nem külső csomag: egy számláló HMAC-je és negyven sor, és a belépés
 * útjában minden függőség eggyel több, amiben meg kell bízni.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Új titkos kulcs: 160 véletlen bit, base32-ben, ahogy az alkalmazások kérik. */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::unbase32($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Az időablak, amihez a kód tartozik, ha jó — egy ablak eltérés mindkét
     * irányban belefér egy kicsit siető vagy késő telefonnak —, és újabb az
     * utoljára használtnál, így a valaki válla fölött meglesett kód nem jó
     * még egyszer. Null, ha nem jó.
     */
    public static function verify(string $secret, string $given, ?int $lastStep = null, ?int $time = null): ?int
    {
        $given = preg_replace('/\s+/', '', $given) ?? '';

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $given) !== 1) {
            return null;
        }

        $now = self::step($time);

        foreach ([0, -1, 1] as $drift) {
            $step = $now + $drift;

            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }

            if (hash_equals(self::code($secret, $step), $given)) {
                return $step;
            }
        }

        return null;
    }

    /** Az otpauth:// cím, amit a QR-kód az alkalmazásba visz. */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function unbase32(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text) ?? '');
        $bits = '';

        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);

            if ($value === false) {
                return '';
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
