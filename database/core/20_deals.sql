-- =============================================================================
-- Értékesítési folyamat (pipeline)
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy üzlet (deal) egy lehetséges eladás egy partnernél, amíg meg nem nyerjük
-- vagy el nem veszítjük. A szakaszok rögzítettek (lead → qualified → proposal →
-- negotiation → won / lost), a táblán oszlopként látszanak. A valószínűség
-- alapból a szakaszé (DealModel::STAGES), de üzletenként felülírható; az érték
-- és a valószínűség szorzata a súlyozott érték, ebből jön az előrejelzés.
-- Egy árajánlathoz köthető (quote_id): az ajánlatból lett rendelés megnyeri az
-- üzletet, és a rendelés is ide kerül (order_id). Elvesztéskor az ok kötelező.
-- A position a kártya helye az oszlopán belül, húzással rendezhető.

CREATE TABLE IF NOT EXISTS deals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  partner_id INT UNSIGNED NOT NULL,
  stage ENUM('lead','qualified','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  probability TINYINT UNSIGNED DEFAULT NULL,
  expected_close DATE DEFAULT NULL,
  owner_id INT UNSIGNED DEFAULT NULL,
  lost_reason VARCHAR(255) DEFAULT NULL,
  quote_id INT UNSIGNED DEFAULT NULL,
  order_id INT UNSIGNED DEFAULT NULL,
  note TEXT DEFAULT NULL,
  position INT NOT NULL DEFAULT 0,
  closed_at DATETIME DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_deals_stage (stage, position),
  KEY idx_deals_partner (partner_id),
  KEY idx_deals_owner (owner_id),
  KEY idx_deals_quote (quote_id),
  CONSTRAINT fk_deals_partner FOREIGN KEY (partner_id) REFERENCES partners (id),
  CONSTRAINT fk_deals_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_deals_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL,
  CONSTRAINT fk_deals_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_deals_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
