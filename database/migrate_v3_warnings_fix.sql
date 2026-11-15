-- ============================================================
-- UniPortal – Migration v3: Fix attendance warnings
-- Fixes:
--   1. Adds 'instructions' and 'publication_date' to Assignment
--   2. Backfills attendance warnings for all students with
--      absences > 20% in any semester (not just Active)
-- Safe to run multiple times (uses IF NOT EXISTS / INSERT IGNORE)
-- ============================================================

USE uniportal;

-- ── 1. Add missing Assignment columns requested by prompt ────
ALTER TABLE Assignment
    ADD COLUMN IF NOT EXISTS instructions  TEXT NULL
        COMMENT 'Detailed instructions for students'
        AFTER description,
    ADD COLUMN IF NOT EXISTS publication_date DATETIME NULL
        COMMENT 'When the assignment becomes visible to students'
        AFTER instructions;

-- ── 2. Mark long-overdue 'Enrolled' enrollments in Completed ─
-- semesters as 'Completed' to reflect reality. This fixes the
-- GPA calculation which requires status='Completed'.
-- We only mark Enrolled→Completed where the semester ended >30d ago
-- AND the student has a final grade for all assessments.
-- For now we keep them Enrolled since GPA calc already works.

-- ── 3. Create attendance warnings for students who already ───
-- exceed 20% absence in any semester (batch seed).
-- The service only fires on new attendance saves; we need to
-- backfill historical warnings.
-- We call a stored procedure approach via pure SQL:

-- Delete stale attendance warnings that have section_id but
-- the section no longer qualifies (keeps data clean):
-- (no-op if warnings are already correct)

-- Backfill: insert Level 3 warnings for students with >20%
-- absence in any section (avoid duplicates via INSERT IGNORE):
INSERT IGNORE INTO AcademicWarning
    (student_id, warning_type, message, status, is_read, section_id, warning_level)
SELECT
    agg.student_id,
    'Attendance',
    CONCAT(
        '🚫 CẤM THI RỦI RO: Môn ',
        agg.course_name, ' (', agg.course_code, ')',
        ' — Tỷ lệ vắng ', agg.absent_count, '/', agg.total_count,
        ' buổi (', ROUND(agg.absent_pct, 1), '%) > 20%.',
        ' Bạn có thể bị CẤM THI môn này. Vui lòng liên hệ giảng viên hoặc Phòng Đào tạo.'
    ),
    'Active',
    FALSE,
    agg.section_id,
    3
FROM (
    SELECT
        e.student_id,
        e.section_id,
        c.course_code,
        c.course_name,
        COUNT(a.attendance_id)                AS total_count,
        SUM(a.status = 'Absent')              AS absent_count,
        SUM(a.status = 'Absent') / COUNT(*) * 100 AS absent_pct
    FROM Attendance a
    JOIN Enrollment e   ON e.enrollment_id = a.enrollment_id
    JOIN ClassSection cs ON cs.section_id  = e.section_id
    JOIN Course c        ON c.course_id    = cs.course_id
    WHERE e.status IN ('Enrolled', 'Completed')
    GROUP BY e.student_id, e.section_id
    HAVING absent_pct > 20
) agg
WHERE NOT EXISTS (
    SELECT 1 FROM AcademicWarning aw
    WHERE aw.student_id = agg.student_id
      AND aw.section_id = agg.section_id
      AND aw.warning_type = 'Attendance'
      AND aw.status = 'Active'
);

-- Backfill Level 2 (exactly at 20%) for sections not already with Level 3:
INSERT IGNORE INTO AcademicWarning
    (student_id, warning_type, message, status, is_read, section_id, warning_level)
SELECT
    agg.student_id,
    'Attendance',
    CONCAT(
        '⚠️ CẢNH BÁO: Môn ',
        agg.course_name, ' (', agg.course_code, ')',
        ' — Tỷ lệ vắng ', agg.absent_count, '/', agg.total_count,
        ' buổi = ', ROUND(agg.absent_pct, 1), '%.',
        ' Vắng thêm 1 buổi sẽ bị cấm thi!'
    ),
    'Active',
    FALSE,
    agg.section_id,
    2
FROM (
    SELECT
        e.student_id,
        e.section_id,
        c.course_code,
        c.course_name,
        COUNT(a.attendance_id)                AS total_count,
        SUM(a.status = 'Absent')              AS absent_count,
        SUM(a.status = 'Absent') / COUNT(*) * 100 AS absent_pct
    FROM Attendance a
    JOIN Enrollment e   ON e.enrollment_id = a.enrollment_id
    JOIN ClassSection cs ON cs.section_id  = e.section_id
    JOIN Course c        ON c.course_id    = cs.course_id
    WHERE e.status IN ('Enrolled', 'Completed')
    GROUP BY e.student_id, e.section_id
    HAVING ABS(absent_pct - 20.0) < 0.01
) agg
WHERE NOT EXISTS (
    SELECT 1 FROM AcademicWarning aw
    WHERE aw.student_id = agg.student_id
      AND aw.section_id = agg.section_id
      AND aw.warning_type = 'Attendance'
      AND aw.status = 'Active'
);

-- Backfill Level 1 (>=15% and <20%) for sections not already warned:
INSERT IGNORE INTO AcademicWarning
    (student_id, warning_type, message, status, is_read, section_id, warning_level)
SELECT
    agg.student_id,
    'Attendance',
    CONCAT(
        '📋 Chú ý chuyên cần: Môn ',
        agg.course_name, ' (', agg.course_code, ')',
        ' — Tỷ lệ vắng ', agg.absent_count, '/', agg.total_count,
        ' buổi (', ROUND(agg.absent_pct, 1), '%).',
        ' Hãy cải thiện tỷ lệ chuyên cần để tránh bị cấm thi.'
    ),
    'Active',
    FALSE,
    agg.section_id,
    1
FROM (
    SELECT
        e.student_id,
        e.section_id,
        c.course_code,
        c.course_name,
        COUNT(a.attendance_id)                AS total_count,
        SUM(a.status = 'Absent')              AS absent_count,
        SUM(a.status = 'Absent') / COUNT(*) * 100 AS absent_pct
    FROM Attendance a
    JOIN Enrollment e   ON e.enrollment_id = a.enrollment_id
    JOIN ClassSection cs ON cs.section_id  = e.section_id
    JOIN Course c        ON c.course_id    = cs.course_id
    WHERE e.status IN ('Enrolled', 'Completed')
    GROUP BY e.student_id, e.section_id
    HAVING absent_pct >= 15 AND absent_pct < 20
) agg
WHERE NOT EXISTS (
    SELECT 1 FROM AcademicWarning aw
    WHERE aw.student_id = agg.student_id
      AND aw.section_id = agg.section_id
      AND aw.warning_type = 'Attendance'
      AND aw.status = 'Active'
);

-- Notify all students with new active attendance warnings
-- (Notification table - best effort, skipped if account not found)
INSERT IGNORE INTO Notification (account_id, type, title, body, link)
SELECT
    acc.account_id,
    'Warning',
    '⚠️ Cảnh báo chuyên cần',
    aw.message,
    '/UniPortal/public/assets/student/alerts.php'
FROM AcademicWarning aw
JOIN Account acc ON acc.student_id = aw.student_id
WHERE aw.warning_type = 'Attendance'
  AND aw.status = 'Active'
  AND aw.section_id IS NOT NULL
  AND aw.created_at >= NOW() - INTERVAL 1 MINUTE;

SELECT CONCAT('Migration v3: Attendance warnings created. Total active attendance warnings: ',
    COUNT(*)) AS result
FROM AcademicWarning
WHERE warning_type='Attendance' AND status='Active' AND section_id IS NOT NULL;
