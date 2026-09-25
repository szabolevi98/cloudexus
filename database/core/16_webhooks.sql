-- =============================================================================
-- Webhookok
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy másik program (egy webshop, egy könyvelés) itt kér értesítést arról,
-- ami történt: új rendelés, kiállított vagy kifizetett számla, változó
-- készlet. Minden üzenet előbb ide íródik (ugyanabban a tranzakcióban, mint
-- a változás, így egy visszagörgetett változásról üzenet sem megy), és a
-- bin/webhooks.php küldi ki, percenként, cronból; ami nem ment át, azt egyre
-- később újra próbálja.

CREATE TABLE IF NOT EXISTS webhooks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  url VARCHAR(500) NOT NULL,
  secret CHAR(64) NOT NULL,
  -- '*' mindenre, vagy vesszővel elválasztott események
  events VARCHAR(255) NOT NULL DEFAULT '*',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_webhooks_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  state ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  response_status SMALLINT UNSIGNED DEFAULT NULL,
  response_body VARCHAR(1000) DEFAULT NULL,
  error VARCHAR(255) DEFAULT NULL,
  duration_ms INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_webhook_deliveries_due (state, next_attempt_at),
  KEY idx_webhook_deliveries_hook (webhook_id, id),
  CONSTRAINT fk_webhook_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
