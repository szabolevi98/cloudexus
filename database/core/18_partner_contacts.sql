-- =============================================================================
-- Kapcsolattartók
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy partnernél több ember: név, beosztás, e-mail, telefon. Egyikük az
-- elsődleges (is_primary), és aki a számlákat kapja (receives_invoices) — a
-- számla és az ajánlat e-mailje alapból neki megy, nem a partner általános
-- címére. Egy tevékenység (hívás, találkozó) egy kapcsolattartóhoz köthető.

CREATE TABLE IF NOT EXISTS partner_contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  position VARCHAR(120) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  receives_invoices TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_contacts_partner (partner_id),
  CONSTRAINT fk_partner_contacts_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE partner_activities ADD COLUMN IF NOT EXISTS contact_id INT UNSIGNED DEFAULT NULL AFTER partner_id;
