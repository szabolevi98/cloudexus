-- =============================================================================
-- Szerepkörök, jogosultság-mátrix és audit napló
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- A jogosultság-kulcsok katalógusa kódban él (src/Core/Permissions.php); itt
-- csak a szerepkörök és a szerepkör → kulcs hozzárendelés vannak. A kezdő
-- mátrixot a migrate.php tölti be szerepkörönként egyszer
-- (permissions_seeded_at), így a felületen tett módosításokat egy későbbi
-- migráció sem írja felül.
--
-- Minden felhasználónak pontosan egy szerepköre van. A szuper admin a
-- kódja alapján mindenhez hozzáfér; nála a mátrix nem szerkeszthető, így
-- egy elrontott beállítás sem zárhatja ki a rendszerből.

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  permissions_seeded_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission VARCHAR(100) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A beépített szerepkörök. A nevük és a leírásuk szerkeszthető, a kódjuk nem.
INSERT IGNORE INTO roles (code, name, description, is_system, sort_order) VALUES
    ('super_admin', 'Szuper admin', 'Mindenhez hozzáfér, a jogosultságai nem szerkeszthetők.', 1, 10),
    ('manager', 'Vezető', 'Minden üzleti modul, a rendszerbeállítások nélkül.', 1, 20),
    ('finance', 'Pénzügy', 'Számlák, pénztár, beszerzés és a kifizetések.', 1, 30),
    ('sales', 'Értékesítő', 'Partnerek, CRM, rendelések és számlakiállítás.', 1, 40),
    ('warehouse', 'Raktáros', 'Készletmozgások, leltár és a raktári áttekintés.', 1, 50),
    ('viewer', 'Csak olvasó', 'Mindent lát, semmit nem módosíthat.', 1, 60);

-- A felhasználó szerepköre. A régi role oszlop (admin / user) csak az
-- átálláshoz marad meg: az admin szuper admin lesz, a felhasználó vezető —
-- ők eddig is mindenhez hozzáfértek a rendszerbeállításokon kívül.
ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT UNSIGNED DEFAULT NULL AFTER role;

UPDATE users u
JOIN roles r ON r.code = CASE WHEN u.role = 'admin' THEN 'super_admin' ELSE 'manager' END
SET u.role_id = r.id
WHERE u.role_id IS NULL;

ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_role (role_id);
ALTER TABLE users
  ADD CONSTRAINT fk_users_role FOREIGN KEY IF NOT EXISTS (role_id) REFERENCES roles (id) ON DELETE RESTRICT;

-- Ki, mikor, mit csinált: belépés, jogosultság-változás, felhasználó- és
-- szerepkör-kezelés, számla kiállítása és sztornója, pénztár, leltár.
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED DEFAULT NULL,
  user_name VARCHAR(120) DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id INT UNSIGNED DEFAULT NULL,
  label VARCHAR(255) DEFAULT NULL,
  details TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
