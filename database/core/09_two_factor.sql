-- =============================================================================
-- Kétlépcsős belépés (TOTP, RFC 6238)
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- A felhasználó maga kapcsolja be a profiljában, egy hitelesítő alkalmazással
-- (Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden, …). A
-- titkos kulcs csak akkor kerül ide, amikor már beírt vele egy jó kódot, így
-- egy rosszul beolvasott QR-kód nem zárja ki. Az utoljára használt időablak
-- azért kell, hogy ugyanaz a kód ne legyen kétszer jó.

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL AFTER last_login_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME DEFAULT NULL AFTER totp_secret;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT UNSIGNED DEFAULT NULL AFTER totp_enabled_at;

-- Tíz egyszer használható helyreállító kód arra a napra, amikor a telefon
-- nincs meg — jelszóként hash-elve.
CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_recovery_codes_user (user_id),
  CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
