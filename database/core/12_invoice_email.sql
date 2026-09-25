-- =============================================================================
-- Számla e-mailben
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Mikor és kinek ment el utoljára a számla PDF-je. (Minden küldés az audit
-- naplóban is ott van; ez a számla oldalára kell.)

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS emailed_at DATETIME DEFAULT NULL AFTER payment_method;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS emailed_to VARCHAR(255) DEFAULT NULL AFTER emailed_at;
