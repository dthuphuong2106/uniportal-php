-- ============================================================
-- UniPortal – Migration v5: Assignment lifecycle
--
-- Adds an explicit lifecycle status to Assignment so the
-- Lecturer workflow can create Draft assignments, publish them
-- to students, and close submissions without deleting data.
--
-- Safe to run multiple times (IF NOT EXISTS).
-- Existing rows default to 'Published', which preserves the
-- previous behaviour (visibility controlled by publication_date).
--
-- Rollback (manual, only if the application is also reverted):
--   ALTER TABLE Assignment DROP COLUMN status;
-- ============================================================

USE uniportal;

ALTER TABLE Assignment
    ADD COLUMN IF NOT EXISTS status ENUM('Draft','Published','Closed')
        NOT NULL DEFAULT 'Published'
        COMMENT 'Draft = lecturer only, Published = visible to enrolled students, Closed = no new submissions'
        AFTER max_score;

-- Make the student visibility filter cheap on large assignment sets.
-- Index name is unique; DROP/ADD is avoided so reruns stay safe.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'Assignment'
      AND index_name   = 'idx_assign_status'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE Assignment ADD INDEX idx_assign_status (status, section_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migration v5 applied.' AS result;
