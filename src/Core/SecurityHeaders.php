<?php

namespace Cloudexus\Core;

/**
 * Böngésző-oldali védelmek minden válaszra: szkript csak az alkalmazás saját
 * fájljaiból futhat (script-src 'self' — nincs inline szkript, sem inline
 * eseménykezelő; a sablonok data-* attribútumokat és nem futtatható JSON-
 * blokkokat használnak, a viselkedés a web/assets/js fájlokban van), így egy
 * valahogy mégis bejutott <script> vagy onerror="…" nem fut le. Bekapcsolt
 * reCAPTCHA mellett a Google két címe is. Továbbá: idegen oldal nem
 * keretezheti be a felületet (clickjacking ellen), űrlap csak ide küldhet, a
 * böngésző nem találgat tartalomtípust, és a hivatkozó URL nem szivárog ki.
 */
final class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Content-Security-Policy: ' . self::policy());

        // HSTS csak akkor, ha az alkalmazás HTTPS-en fut: egy http-s helyi
        // telepítést nem zárunk ki a saját böngészőjéből.
        if (str_starts_with((string) Config::get('app.base_url', ''), 'https://')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function policy(): string
    {
        $scripts = "'self'";
        if (Recaptcha::enabled()) {
            $scripts .= ' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/';
        }

        return "script-src $scripts; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";
    }
}
