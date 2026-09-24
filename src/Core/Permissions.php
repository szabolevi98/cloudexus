<?php

namespace Cloudexus\Core;

/**
 * A jogosultság-kulcsok katalógusa.
 *
 * A lista kódban él — verziózható, és a controllerek statikusan hivatkoznak
 * rá —, a szerepkör → kulcs hozzárendelés viszont a role_permissions
 * táblában, a Szerepkörök oldalon szerkeszthetően. A groups() adja a mátrix
 * csoportosítását, a defaults() a beépített szerepkörök kezdő mátrixát.
 *
 * Új kulcs bevezetése: konstans, felvétel a groups()-ba és a defaults()-ba,
 * felirat a Language/<nyelv>/permissions.php-ba. A következő
 * database/migrate.php futás egyszer odaadja azoknak a szerepköröknek,
 * amelyeknek a defaults() szerint jár — a felületen később elvett jogot
 * viszont nem adja vissza.
 */
final class Permissions
{
    // --- Áttekintés ------------------------------------------------------
    public const DASHBOARD_VIEW = 'dashboard.view';

    // --- Törzsadatok -----------------------------------------------------
    public const PRODUCTS_VIEW = 'products.view';
    public const PRODUCTS_MANAGE = 'products.manage';
    /** Kategóriák, termékparaméterek és mértékegységek. */
    public const CATALOG_MANAGE = 'catalog.manage';
    public const PARTNERS_VIEW = 'partners.view';
    public const PARTNERS_MANAGE = 'partners.manage';
    /** Vevőcsoportok és a csoportonkénti árak. */
    public const CUSTOMER_GROUPS_MANAGE = 'customer_groups.manage';
    /** Árszabályok: csoportkedvezmény, mennyiségi kedvezmény, akció. */
    public const PRICING_MANAGE = 'pricing.manage';

    // --- Készlet ---------------------------------------------------------
    public const STOCK_VIEW = 'stock.view';
    /** Bevét, kiadás, raktárközi átadás, vonalkódos gyűjtés — a mobil appból is. */
    public const STOCK_MOVE = 'stock.move';
    public const STOCKTAKING_MANAGE = 'stocktaking.manage';
    public const WAREHOUSES_MANAGE = 'warehouses.manage';

    // --- Értékesítés -----------------------------------------------------
    public const ORDERS_VIEW = 'orders.view';
    public const ORDERS_MANAGE = 'orders.manage';
    public const INVOICES_VIEW = 'invoices.view';
    public const INVOICES_ISSUE = 'invoices.issue';
    public const INVOICES_STORNO = 'invoices.storno';

    // --- Beszerzés és pénzügy --------------------------------------------
    public const PURCHASING_VIEW = 'purchasing.view';
    public const PURCHASING_MANAGE = 'purchasing.manage';
    /** Kimenő és bejövő számla kifizetettnek jelölése. */
    public const FINANCE_MARK_PAID = 'finance.mark_paid';
    public const CASH_VIEW = 'cash.view';
    public const CASH_MANAGE = 'cash.manage';

    // --- CRM -------------------------------------------------------------
    public const CRM_VIEW = 'crm.view';
    /** Teendők és partner-tevékenységek. */
    public const CRM_MANAGE = 'crm.manage';

    // --- Rendszer --------------------------------------------------------
    public const USERS_MANAGE = 'system.users';
    public const ROLES_MANAGE = 'system.roles';
    /** Cégadatok, pénznemek, nyelvek. */
    public const SETTINGS_MANAGE = 'system.settings';
    /** API-felhasználók, napló és dokumentáció. */
    public const API_MANAGE = 'system.api';
    public const AUDIT_VIEW = 'system.audit';

    /** @return array<string, list<string>> csoport => kulcsok, a mátrix sorrendjében */
    public static function groups(): array
    {
        return [
            'overview' => [self::DASHBOARD_VIEW],
            'master_data' => [
                self::PRODUCTS_VIEW, self::PRODUCTS_MANAGE, self::CATALOG_MANAGE,
                self::PARTNERS_VIEW, self::PARTNERS_MANAGE, self::CUSTOMER_GROUPS_MANAGE, self::PRICING_MANAGE,
            ],
            'inventory' => [self::STOCK_VIEW, self::STOCK_MOVE, self::STOCKTAKING_MANAGE, self::WAREHOUSES_MANAGE],
            'sales' => [self::ORDERS_VIEW, self::ORDERS_MANAGE, self::INVOICES_VIEW, self::INVOICES_ISSUE, self::INVOICES_STORNO],
            'finance' => [self::PURCHASING_VIEW, self::PURCHASING_MANAGE, self::FINANCE_MARK_PAID, self::CASH_VIEW, self::CASH_MANAGE],
            'crm' => [self::CRM_VIEW, self::CRM_MANAGE],
            'system' => [self::USERS_MANAGE, self::ROLES_MANAGE, self::SETTINGS_MANAGE, self::API_MANAGE, self::AUDIT_VIEW],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * A beépített szerepkörök kezdő jogai. A szuper admin itt nem szerepel:
     * ő a kódja alapján mindent elér.
     *
     * @return array<string, list<string>> szerepkör-kód => kulcsok
     */
    public static function defaults(): array
    {
        $views = [
            self::DASHBOARD_VIEW, self::PRODUCTS_VIEW, self::PARTNERS_VIEW, self::STOCK_VIEW,
            self::ORDERS_VIEW, self::INVOICES_VIEW, self::PURCHASING_VIEW, self::CASH_VIEW, self::CRM_VIEW,
        ];
        $system = self::groups()['system'];

        return [
            RoleCode::MANAGER => array_values(array_merge(array_diff(self::all(), $system), [self::AUDIT_VIEW])),
            RoleCode::FINANCE => [
                self::DASHBOARD_VIEW, self::PRODUCTS_VIEW, self::PARTNERS_VIEW, self::STOCK_VIEW, self::CRM_VIEW,
                self::ORDERS_VIEW, self::INVOICES_VIEW, self::INVOICES_ISSUE, self::INVOICES_STORNO,
                self::PURCHASING_VIEW, self::PURCHASING_MANAGE, self::FINANCE_MARK_PAID, self::CASH_VIEW, self::CASH_MANAGE,
            ],
            RoleCode::SALES => [
                self::DASHBOARD_VIEW, self::PRODUCTS_VIEW, self::PARTNERS_VIEW, self::PARTNERS_MANAGE, self::STOCK_VIEW,
                self::ORDERS_VIEW, self::ORDERS_MANAGE, self::INVOICES_VIEW, self::INVOICES_ISSUE, self::CRM_VIEW, self::CRM_MANAGE,
            ],
            RoleCode::WAREHOUSE => [
                self::DASHBOARD_VIEW, self::PRODUCTS_VIEW, self::STOCK_VIEW, self::STOCK_MOVE, self::STOCKTAKING_MANAGE,
                self::ORDERS_VIEW, self::PURCHASING_VIEW,
            ],
            RoleCode::VIEWER => $views,
        ];
    }
}
