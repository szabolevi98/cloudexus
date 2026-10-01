-- =============================================================================
-- Kiszedés (komissiózás) és áruátvétel a mobil appból
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Kiszedés: a PDA egy visszaigazolt rendelés tételeit szedi le a polcokról,
-- és a végén egy gombbal kiadja őket a raktárból — a polcokkal együtt. A
-- rendelés megjegyzi, mikor, ki és melyik raktárból szedte ki. Az ilyen
-- rendelésről kiállított számla már NEM ad ki készletet (különben kétszer
-- menne ki), és a sztornója sem hozza vissza: az áru a kiszedéskor fizikailag
-- elment.
--
-- Átvétel: a PDA egy beszerzési rendelés tételeit veszi át, akár több
-- részletben is; minden átvétel azonnal bevételez (polcra). A tétel
-- received_quantity mezője az eddig átvett mennyiség. Az átvett beszerzési
-- rendelésről rögzített bejövő számla már NEM vesz be készletet, és a
-- sztornója sem adja ki.

ALTER TABLE orders ADD COLUMN IF NOT EXISTS picked_at DATETIME NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS picked_by INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS picked_warehouse_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS received_at DATETIME NULL DEFAULT NULL;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS received_by INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS received_warehouse_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE purchase_order_items ADD COLUMN IF NOT EXISTS received_quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000;
