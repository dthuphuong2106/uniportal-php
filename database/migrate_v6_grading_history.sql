-- ============================================================
-- UniPortal – Migration v6: Assignment grading history
--
-- Records every change to a submission grade (score, feedback,
-- publish state) so lecturers can review how a grade evolved.
--
-- Safe to run multiple times:
--   * table creation uses IF NOT EXISTS
--   * the backfill only touches submissions without history
--
-- Rollback (manual, only if the application is also reverted):
--   DROP TABLE SubmissionGradeHistory;
-- ============================================================

USE uniportal;

CREATE TABLE IF NOT EXISTS SubmissionGradeHistory (
    history_id        INT UNSIGNED AUTO_INCREMENT,
    submission_id     INT UNSIGNED        NOT NULL,
    old_score         DECIMAL(5,2)            NULL,
    new_score         DECIMAL(5,2)            NULL,
    old_feedback      TEXT                    NULL,
    new_feedback      TEXT                    NULL,
    old_grade_status  ENUM('Draft','Published') NULL,
    new_grade_status  ENUM('Draft','Published') NOT NULL,
    changed_by        INT UNSIGNED            NULL COMMENT 'account_id of the lecturer',
    changed_at        DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (history_id),
    KEY idx_sgh_submission (submission_id, changed_at),
    KEY idx_sgh_account    (changed_by),

    CONSTRAINT fk_sgh_submission
        FOREIGN KEY (submission_id)
        REFERENCES Submission(submission_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_sgh_account
        FOREIGN KEY (changed_by)
        REFERENCES Account(account_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB
  COMMENT = 'Audit trail of assignment submission grade changes';

-- Baseline entry for submissions that were graded before this migration.
INSERT INTO SubmissionGradeHistory
    (submission_id, old_score, new_score, old_feedback, new_feedback,
     old_grade_status, new_grade_status, changed_by, changed_at)
SELECT sub.submission_id, NULL, sub.score, NULL, sub.feedback,
       NULL, sub.grade_status, acc.account_id,
       COALESCE(sub.submitted_at, NOW())
FROM Submission sub
JOIN Assignment a        ON a.assignment_id = sub.assignment_id
JOIN ClassSection cs     ON cs.section_id   = a.section_id
LEFT JOIN Account acc    ON acc.lecturer_id = cs.lecturer_id
WHERE sub.score IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM SubmissionGradeHistory h
      WHERE h.submission_id = sub.submission_id
  );

SELECT 'Migration v6 applied.' AS result;
