-- =============================================================================
-- Kimenő levelek sora
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- A levél itt vár a küldésre, ahelyett hogy a kérés alatt menne ki, amíg
-- valaki az oldalra vár: egy lassú vagy elérhetetlen levelezőszerver így
-- semmit nem lassít, és egy visszautasított levél később újra próbálkozik,
-- nem vész el. A bin/outbox.php küldi ki, ami esedékes, percenként, cronból.
-- Egy levélnek legfeljebb egy melléklete van (a számla PDF-je).

CREATE TABLE IF NOT EXISTS outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_address VARCHAR(255) NOT NULL,
  to_name VARCHAR(160) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  attachment_name VARCHAR(190) DEFAULT NULL,
  attachment MEDIUMBLOB DEFAULT NULL,
  -- mi ez: invoice, password_reset, digest, test, mail
  kind VARCHAR(20) NOT NULL DEFAULT 'mail',
  -- waiting: next_attempt_at-kor esedékes; sending: egy küldő futás elvitte;
  -- sent: elment; failed: annyiszor próbálta, ahányszor fogja.
  state ENUM('waiting','sending','sent','failed') NOT NULL DEFAULT 'waiting',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  taken_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_outbox_due (state, next_attempt_at),
  KEY idx_outbox_sent (state, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
