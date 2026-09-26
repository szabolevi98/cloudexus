-- =============================================================================
-- A téma a felhasználónál
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Világos, sötét, vagy a rendszer szerint: eddig egy sütiben volt, így minden
-- böngészőben újra kellett választani. Most a felhasználónál van, és a
-- profilban állítható; a fejléc gombja világos és sötét között vált. NULL: még
-- nem választott — ilyenkor a régi süti dönt, ha van, különben a rendszer.
-- A süti marad a bejelentkező oldalnak, ahol még nem tudjuk, ki jön.

ALTER TABLE users ADD COLUMN IF NOT EXISTS theme VARCHAR(6) NULL DEFAULT NULL;
