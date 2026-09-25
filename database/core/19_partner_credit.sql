-- =============================================================================
-- Hitelkeret és fizetési határidő partnerenként
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- credit_limit: ennyi nyitott (ki nem fizetett) számlaösszeg mellett még
-- nyugodtan lehet neki szállítani; NULL = nincs keret. A rendelés, az
-- ajánlat és a számla űrlapja figyelmeztet, ha a partner túllépné, vagy ha
-- lejárt tartozása van — megtiltani nem tiltja.
-- payment_terms_days: a számla fizetési határideje alapból ennyi nap; NULL =
-- az általános 8 nap.

ALTER TABLE partners ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(14,2) DEFAULT NULL AFTER address;
ALTER TABLE partners ADD COLUMN IF NOT EXISTS payment_terms_days SMALLINT UNSIGNED DEFAULT NULL AFTER credit_limit;
