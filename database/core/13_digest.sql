-- =============================================================================
-- Reggeli összefoglaló
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Aki kéri (a profiljában), hétköznap reggel e-mailt kap arról, ami ma
-- figyelmet kér: a lejárt vevői és szállítói számlák, a minimum alá esett
-- termékek, a neki kiosztott esedékes teendők — abból, amit a szerepköre
-- láthat. A bin/digest.php küldi, cronból; üres napon nem megy levél.

ALTER TABLE users ADD COLUMN IF NOT EXISTS digest_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_last_step;
