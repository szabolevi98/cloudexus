-- =============================================================================
-- Mentett szűrők
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy lista szűrése és rendezése néven eltéve: a lista (page) és a cím
-- lekérdezés-része (query), ahogy a szűrőűrlap küldi. Saját, vagy mindenkivel
-- megosztott (is_shared) — a megosztottat is csak az törölheti, aki mentette,
-- vagy aki a beállításokat kezeli.

CREATE TABLE IF NOT EXISTS saved_filters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  page VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  query TEXT NOT NULL,
  is_shared TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_saved_filters_page (page, user_id),
  CONSTRAINT fk_saved_filters_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
