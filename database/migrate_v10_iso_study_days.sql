-- =============================================================
-- UniPortal – Migration v10
-- Normalise class-section study days to true ISO weekday numbers.
--
-- v9 stored study days using the Vietnamese label number
-- (2 = "Thứ 2" = Monday ... 7 = Saturday) while documenting them
-- as ISO. Automatic session generation then produced sessions one
-- weekday later than the published timetable. This migration
-- shifts every existing value (2..7 -> 1..6) so that 1 = Monday,
-- 7 = Sunday, matching TeachingSchedule and the student timetable.
--
-- SAFE / IDEMPOTENT:
--   * guarded by a SystemConfig marker; rerunning is a no-op
--   * only rewrites ClassSection.study_days
--   * schedule text, enrollments and all other data untouched
-- =============================================================
SET NAMES utf8mb4;

SET @v10_done = (
    SELECT config_value FROM SystemConfig
    WHERE config_key = 'migration_v10_iso_study_days' LIMIT 1
);

UPDATE ClassSection
SET study_days = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                     REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                         study_days,
                         '2','b'), '3','c'), '4','d'), '5','e'), '6','f'), '7','g'),
                     'b','1'), 'c','2'), 'd','3'), 'e','4'), 'f','5'), 'g','6')
WHERE @v10_done IS NULL
  AND study_days IS NOT NULL
  AND study_days <> '';

INSERT INTO SystemConfig (config_key, config_value, description)
VALUES ('migration_v10_iso_study_days', '1', 'Study days normalised to ISO weekday numbers')
ON DUPLICATE KEY UPDATE config_value = '1';
