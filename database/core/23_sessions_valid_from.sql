-- =============================================================================
-- Kijelentkezés mindenhol máshol
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- A munkamenet az utolsó kattintástól egy évig él (session.lifetime), ami egy
-- elhagyott laptopnál sok. A munkamenet tudja, mikor lépett be (logged_in_at);
-- ha ez korábbi a felhasználó sessions_valid_from értékénél, a munkamenet
-- megszűnik. Új jelszó (saját, admin által adott vagy e-mailes visszaállítás)
-- beállítja — ahogy a mobilapp tokenjeit is visszavonja —, és a profil
-- "Kijelentkezés mindenhol máshol" gombja is. Az a böngésző, amelyikben a
-- saját jelszavát változtatta, bejelentkezve marad.

ALTER TABLE users ADD COLUMN IF NOT EXISTS sessions_valid_from DATETIME NULL DEFAULT NULL;
