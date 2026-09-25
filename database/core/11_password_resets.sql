-- =============================================================================
-- Elfelejtett jelszó
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy egyszer használható link, egy óráig érvényes. Csak a token hash-e
-- kerül ide (mint a mobil tokeneknél), így egy kiszivárgott adatbázisból
-- nem lehet vele jelszót állítani. Egy újabb kérés a régebbi, fel nem
-- használt linkeket érvényteleníti.

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
