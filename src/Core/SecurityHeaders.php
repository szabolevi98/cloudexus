<?php

namespace Cloudexus\Core;

/**
 * Böngésző-oldali védelmek minden válaszra. Szándékosan nem korlátozza a
 * szkripteket (a sablonokban inline szkript és a reCAPTCHA is van), csak azt,
 * ami biztosan nem kell: idegen oldal nem keretezheti be a felületet (a
 * clickjacking ellen), űrlap csak ide küldhet, a böngésző nem találgat
 * tartalomtípust, és a hivatkozó URL nem szivárog ki más oldalra.
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
        header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");

        // HSTS csak akkor, ha az alkalmazás HTTPS-en fut: egy http-s helyi
        // telepítést nem zárunk ki a saját böngészőjéből.
        if (str_starts_with((string) Config::get('app.base_url', ''), 'https://')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
