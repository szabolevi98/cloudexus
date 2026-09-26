-- =============================================================================
-- Partnercímkék
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Szabadon adott címkék a partnerekre ("nagyker", "étterem", "VIP"…), a
-- listán szűrni és tömegesen adni lehet őket. Egy címke csak addig létezik,
-- amíg van partnere: a már senkin nem lévőt a mentés törli. A színe a nevéből
-- jön (TagModel::colour), nem tárolt.

CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_tags (
  partner_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (partner_id, tag_id),
  KEY idx_partner_tags_tag (tag_id),
  CONSTRAINT fk_partner_tags_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
  CONSTRAINT fk_partner_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
