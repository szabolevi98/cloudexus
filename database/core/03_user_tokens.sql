-- ---------------------------------------------------------------------------
-- Felhasználónkénti API tokenek (a mobil / PDA app bejelentkezéséhez).
-- Az api_users tokenje egy integrációé (webshop), ez viszont egy konkrét
-- munkatársé: a vele rögzített készletmozgás created_by mezője ő lesz.
-- A tokent csak SHA-256 hash-ként tároljuk, a nyers értéket egyszer, a
-- bejelentkezés válaszában látja a kliens.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_name VARCHAR(120) DEFAULT NULL,
  last_used_at DATETIME DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_token_hash (token_hash),
  KEY idx_user_tokens_user (user_id),
  CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A kérés-log a felhasználói tokennel érkező kéréseket is a gazdájához köti,
-- így a rate limit felhasználónként számolódik, és a log oldal is mutatja, ki volt.
ALTER TABLE api_request_logs
  ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER api_user_id,
  ADD INDEX IF NOT EXISTS idx_log_user_created (user_id, created_at),
  ADD INDEX IF NOT EXISTS idx_log_ip_path_created (ip_address, path, created_at);
