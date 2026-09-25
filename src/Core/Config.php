<?php

namespace Cloudexus\Core;

class Config
{
    private static ?array $data = null;

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: $path");
        }

        self::$data = parse_ini_file($path, true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new \RuntimeException('Config not loaded yet.');
        }

        [$section, $name] = array_pad(explode('.', $key, 2), 2, null);

        if ($name === null) {
            return self::$data[$section] ?? $default;
        }

        return self::$data[$section][$name] ?? $default;
    }

    /** Egy érték felülírása futás közben — a teszteknek ("szakasz.kulcs"). */
    public static function set(string $key, mixed $value): void
    {
        [$section, $name] = array_pad(explode('.', $key, 2), 2, null);
        if ($name === null) {
            throw new \InvalidArgumentException('Config::set needs "section.key".');
        }
        self::$data[$section][$name] = $value;
    }
}
