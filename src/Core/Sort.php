<?php

namespace Cloudexus\Core;

/**
 * Column sorting for the lists, from ?sort=<key>&dir=asc|desc.
 *
 * A header cycles through three states: ascending, descending, and back to the
 * list's own order. Every list whitelists its columns (a model's SORTS constant
 * maps each key to an SQL expression), so nothing from the address reaches the
 * SQL except through that map; an unknown key simply leaves the list as it is.
 *
 * Like the Paginator it reads $_GET itself, so a model's paginate() can apply
 * it without every controller passing it along.
 */
final class Sort
{
    /**
     * The ORDER BY for a list: the chosen column, with the list's own order after
     * it as the tie-breaker (so equal values keep a stable order across pages), or
     * the list's own order alone.
     *
     * @param array<string, string> $columns key => SQL expression
     */
    public static function orderBy(array $columns, string $default): string
    {
        [$key, $dir] = self::current();
        if ($key === null || !isset($columns[$key])) {
            return $default;
        }

        return $columns[$key] . ' ' . strtoupper($dir) . ', ' . $default;
    }

    /** @return array{0: ?string, 1: string} the requested key (null when none or malformed) and direction */
    public static function current(): array
    {
        $key = $_GET['sort'] ?? null;
        $key = is_string($key) && preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1 ? $key : null;
        $dir = ($_GET['dir'] ?? null) === 'desc' ? 'desc' : 'asc';

        return [$key, $dir];
    }

    /** 'asc' or 'desc' when the list is sorted by this key, otherwise null. */
    public static function state(string $key): ?string
    {
        [$current, $dir] = self::current();

        return $current === $key ? $dir : null;
    }

    /**
     * Where a header links to: this key ascending, then descending, then off.
     * The other parameters (the filters) stay; the page goes back to the first.
     */
    public static function url(string $key): string
    {
        $query = $_GET;
        unset($query['page'], $query['sort'], $query['dir']);

        $state = self::state($key);
        if ($state === null) {
            $query['sort'] = $key;
            $query['dir'] = 'asc';
        } elseif ($state === 'asc') {
            $query['sort'] = $key;
            $query['dir'] = 'desc';
        }

        return '?' . http_build_query($query);
    }

    /** @return array<string, string> sort and dir, for links and forms that must keep the order */
    public static function params(): array
    {
        [$key, $dir] = self::current();

        return $key === null ? [] : ['sort' => $key, 'dir' => $dir];
    }

    /** The clickable header: the label, an arrow for the state, and a tooltip for what the next click does. */
    public static function link(string $key, string $label): string
    {
        $state = self::state($key);
        $icon = match ($state) {
            'asc' => 'bi-caret-up-fill',
            'desc' => 'bi-caret-down-fill',
            default => 'bi-chevron-expand',
        };
        $next = match ($state) {
            'asc' => Lang::get('common.sort_next_desc'),
            'desc' => Lang::get('common.sort_next_none'),
            default => Lang::get('common.sort_next_asc'),
        };
        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<a class="cx-sort' . ($state !== null ? ' is-sorted' : '') . '" href="' . $esc(self::url($key)) . '" title="' . $esc($next) . '">'
            . $esc($label) . '<i class="bi ' . $icon . '" aria-hidden="true"></i></a>';
    }

    /** aria-sort for the header cell, so a screen reader hears the order too. */
    public static function aria(string $key): string
    {
        return match (self::state($key)) {
            'asc' => 'aria-sort="ascending"',
            'desc' => 'aria-sort="descending"',
            default => '',
        };
    }

    /** Hidden inputs that carry the order through a filter form. */
    public static function inputs(): string
    {
        $html = '';
        foreach (self::params() as $name => $value) {
            $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
        }

        return $html;
    }
}
