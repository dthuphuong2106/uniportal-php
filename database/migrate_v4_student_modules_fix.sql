-- ============================================================
-- UniPortal – Migration v4: Student Portal module fixes
--
-- Adds schema objects that the Student Portal code already
-- depends on but which were never created:
--   1. Submission.version            (resubmission history)
--   2. AssignmentAttachment          (lecturer assignment files)
--   3. SubmissionAttachment          (multi-file student uploads)
--   4. GPAPlanScenario               (saved GPA planning scenarios)
--
-- Safe to run multiple times (IF NOT EXISTS).
-- No existing rows or columns are removed or rewritten.
-- ============================================================

USE uniportal;

-- ── 1. Submission.version ────────────────────────────────────
ALTER TABLE Submission
    ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Student submission attempt number'
        AFTER grade_status;

-- ── 2. Assignment attachments (multi-file) ───────────────────
CREATE TABLE IF NOT EXISTS AssignmentAttachment (
    attachment_id   INT UNSIGNED AUTO_INCREMENT,
    assignment_id   INT UNSIGNED        NOT NULL,
    file_path       VARCHAR(500)        NOT NULL,
    file_name       VARCHAR(255)        NOT NULL,
    file_size       BIGINT                  NULL,
    file_mime       VARCHAR(100)            NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (attachment_id),
    KEY idx_asg_attachment (assignment_id),

    CONSTRAINT fk_asg_attachment_assignment
        FOREIGN KEY (assignment_id)
        REFERENCES Assignment(assignment_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;

-- ── 3. Submission attachments (multi-file) ───────────────────
CREATE TABLE IF NOT EXISTS SubmissionAttachment (
    sub_attachment_id INT UNSIGNED AUTO_INCREMENT,
    submission_id     INT UNSIGNED     NOT NULL,
    file_path         VARCHAR(500)     NOT NULL,
    file_name         VARCHAR(255)     NOT NULL,
    file_size         BIGINT               NULL,
    file_mime         VARCHAR(100)         NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (sub_attachment_id),
    KEY idx_sub_attachment (submission_id),

    CONSTRAINT fk_sub_attachment_submission
        FOREIGN KEY (submission_id)
        REFERENCES Submission(submission_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;

-- ── 4. GPA planning scenarios ────────────────────────────────
CREATE TABLE IF NOT EXISTS GPAPlanScenario (
    scenario_id      INT UNSIGNED AUTO_INCREMENT,
    student_id       CHAR(8)          NOT NULL,
    scenario_name    VARCHAR(150)     NOT NULL,
    target_gpa       DECIMAL(3,2)     NOT NULL,
    target_label     VARCHAR(50)          NULL,
    future_credits   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    required_gpa     DECIMAL(4,3)         NULL,
    feasibility      VARCHAR(20)      NOT NULL DEFAULT 'achievable',
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (scenario_id),
    KEY idx_gpa_scenario_student (student_id, created_at),

    CONSTRAINT fk_gpa_scenario_student
        FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB;

SELECT 'Migration v4 applied.' AS result;
