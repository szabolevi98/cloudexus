<?php

namespace Cloudexus\Core;

/**
 * Mennyiség annyi tizedessel, amennyi kell: 12, 2,5, 0,125 (kg, l, m is).
 * A Twig |qty szűrője és a controllerek üzenetei is ezt használják.
 */
final class Quantity
{
    public static function format(float|int|string|null $quantity): string
    {
        $formatted = rtrim(rtrim(number_format((float) $quantity, 3, ',', ' '), '0'), ',');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
