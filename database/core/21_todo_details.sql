-- =============================================================================
-- Teendők részletei
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- type: mi a teendő — feladat, hívás, e-mail vagy találkozó.
-- due_time: a határidő napján hánykor; NULL = egész nap.
-- recurrence: ismétlődés. Egy ismétlődő teendő késznek jelölésekor létrejön a
-- következő (a határidő egy nappal/héttel/hónappal később), és a
-- recurrence_parent_id mutat arra, amiből lett — egyszer, akkor is, ha a kész
-- teendőt újranyitják és megint kipipálják.
-- deal_id, quote_id: az üzlet vagy az árajánlat, amihez a teendő tartozik.

ALTER TABLE todos ADD COLUMN IF NOT EXISTS type ENUM('task','call','email','meeting') NOT NULL DEFAULT 'task' AFTER title;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS note TEXT DEFAULT NULL AFTER type;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS due_time TIME DEFAULT NULL AFTER due_date;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS recurrence ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' AFTER due_time;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS recurrence_parent_id INT UNSIGNED DEFAULT NULL AFTER recurrence;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS deal_id INT UNSIGNED DEFAULT NULL AFTER partner_id;
ALTER TABLE todos ADD COLUMN IF NOT EXISTS quote_id INT UNSIGNED DEFAULT NULL AFTER deal_id;

ALTER TABLE todos ADD INDEX IF NOT EXISTS idx_todos_due (is_done, due_date);
ALTER TABLE todos ADD INDEX IF NOT EXISTS idx_todos_deal (deal_id);
ALTER TABLE todos ADD INDEX IF NOT EXISTS idx_todos_quote (quote_id);
ALTER TABLE todos ADD INDEX IF NOT EXISTS idx_todos_parent (recurrence_parent_id);

ALTER TABLE todos ADD CONSTRAINT fk_todos_deal FOREIGN KEY IF NOT EXISTS (deal_id) REFERENCES deals (id) ON DELETE SET NULL;
ALTER TABLE todos ADD CONSTRAINT fk_todos_quote FOREIGN KEY IF NOT EXISTS (quote_id) REFERENCES quotes (id) ON DELETE SET NULL;
