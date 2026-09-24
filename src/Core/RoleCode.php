<?php

namespace Cloudexus\Core;

/**
 * A beépített szerepkörök kódjai. A szerepkörök a roles táblában élnek (nevük
 * és leírásuk szerkeszthető, újak is felvehetők), de ezek a kódok fixek: a
 * kód alapján dől el a szuper admin teljes hozzáférése és a kezdő mátrix.
 */
final class RoleCode
{
    public const SUPER_ADMIN = 'super_admin';
    public const MANAGER = 'manager';
    public const FINANCE = 'finance';
    public const SALES = 'sales';
    public const WAREHOUSE = 'warehouse';
    public const VIEWER = 'viewer';
}
