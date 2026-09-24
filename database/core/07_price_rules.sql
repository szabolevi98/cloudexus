-- =============================================================================
-- Árszabályok
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy szabály feltételei (mind opcionális, az üres = bármi): vevőcsoport,
-- termék vagy kategória (az alkategóriái is), legkisebb mennyiség és
-- érvényességi időszak. A hatása vagy százalékos kedvezmény az alapárból,
-- vagy fix nettó egységár. Ha több szabály is illik egy sorra, a vevőnek
-- legkedvezőbb ár nyer; a szabályok nem adódnak össze.
--
-- Az alapár a vevőcsoport-ár (ha van a termékre), különben a termék ára.
-- Az akciós ár továbbra is él: ha az olcsóbb, az marad.

CREATE TABLE IF NOT EXISTS price_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  customer_group_id INT UNSIGNED DEFAULT NULL,
  product_id INT UNSIGNED DEFAULT NULL,
  category_id INT UNSIGNED DEFAULT NULL,
  min_quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  discount_percent DECIMAL(5,2) DEFAULT NULL,
  fixed_price DECIMAL(14,2) DEFAULT NULL,
  valid_from DATE DEFAULT NULL,
  valid_to DATE DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_price_rules_active (is_active, valid_from, valid_to),
  KEY fk_price_rules_group (customer_group_id),
  KEY fk_price_rules_product (product_id),
  KEY fk_price_rules_category (category_id),
  CONSTRAINT fk_price_rules_group FOREIGN KEY (customer_group_id) REFERENCES customer_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_rules_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_rules_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
