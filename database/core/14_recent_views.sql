-- =============================================================================
-- Legutóbb megnyitottak
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Felhasználónként a legutóbb megnyitott termékek, partnerek és bizonylatok,
-- a Ctrl+K keresőnek: üres kereséssel ezek jönnek elő. Felhasználónként a
-- legutóbbi 30 marad meg.

CREATE TABLE IF NOT EXISTS recent_views (
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind, item_id),
  KEY idx_recent_views_user (user_id, viewed_at),
  CONSTRAINT fk_recent_views_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
