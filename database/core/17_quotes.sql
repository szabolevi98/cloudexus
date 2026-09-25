-- =============================================================================
-- Árajánlatok
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy ajánlat a vevőnek: tételsorok a számlához hasonlóan árazva (a termék
-- ÁFA-kulcsa, nettó, ÁFA, bruttó soronként), érvényességi idővel. Állapota:
-- draft (piszkozat) → sent (elküldve) → accepted / rejected, és ordered, ha
-- rendelés lett belőle (order_id). A lejárt ajánlat nincs külön tárolva: egy
-- elküldött, de valid_until előtt el nem fogadott ajánlat a listán lejártként
-- látszik. Sorszáma a többi bizonylatéhoz hasonlóan évente hézagmentes (AJ).

CREATE TABLE IF NOT EXISTS quotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_number VARCHAR(32) NOT NULL,
  partner_id INT UNSIGNED NOT NULL,
  status ENUM('draft','sent','accepted','rejected','ordered') NOT NULL DEFAULT 'draft',
  quote_date DATE NOT NULL,
  valid_until DATE NOT NULL,
  shipping_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  payment_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  extra_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 27.00,
  net_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  vat_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  note TEXT DEFAULT NULL,
  rejection_reason VARCHAR(255) DEFAULT NULL,
  order_id INT UNSIGNED DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  emailed_to VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_quote_number (quote_number),
  KEY idx_quotes_partner (partner_id),
  KEY idx_quotes_status (status, valid_until),
  CONSTRAINT fk_quotes_partner FOREIGN KEY (partner_id) REFERENCES partners (id),
  CONSTRAINT fk_quotes_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_quotes_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(200) DEFAULT NULL,
  product_sku VARCHAR(64) DEFAULT NULL,
  unit_code VARCHAR(16) DEFAULT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 27.00,
  net_amount DECIMAL(14,2) NOT NULL,
  vat_amount DECIMAL(14,2) NOT NULL,
  gross_amount DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_quote_items_quote (quote_id),
  CONSTRAINT fk_quote_items_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE,
  CONSTRAINT fk_quote_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A rendelés, ami egy ajánlatból lett.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS quote_id INT UNSIGNED DEFAULT NULL AFTER billing_address_id;
