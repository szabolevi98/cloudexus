-- ---------------------------------------------------------------------------
-- Idempotency-Key: a mobil kliens gyenge wifin újraküldheti ugyanazt a
-- rögzítést, ha a választ nem kapta meg. A kulcs alapján a második kérés a
-- tárolt választ kapja vissza, és nem könyvel kétszer. owner_key a kulcs
-- gazdája ('u<user_id>' vagy 'a<api_user_id>'), mert két kliens kulcsai
-- ütközhetnek. 7 nap után önműködően törlődik (lásd IdempotencyKeyModel).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key VARCHAR(16) NOT NULL,
  idempotency_key VARCHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  status_code SMALLINT UNSIGNED DEFAULT NULL,
  response MEDIUMTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_owner_idempotency_key (owner_key, idempotency_key),
  KEY idx_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
