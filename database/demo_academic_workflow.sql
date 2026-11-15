-- ============================================================
-- UniPortal – Demo academic workflow (optional)
--
-- Adds three clearly-labelled [DEMO] assignments to class
-- MIS3101-01 (section 36, lecturer_mis) plus one graded
-- submission for student 23070001, so every assignment feature
-- can be seen immediately:
--
--   * Published assignment  -> student can submit / see grade
--   * Draft assignment      -> lecturer-only, publish/close flow
--   * Closed assignment     -> student sees the closed state
--   * Grade history         -> two recorded grade changes
--
-- Idempotent: rerunning inserts nothing twice.
-- Remove everything again with:
--   database/demo_academic_workflow_cleanup.sql
-- ============================================================

USE uniportal;
-- Force UTF-8 for the client session; the Windows mysql CLI defaults to a
-- legacy code page and would otherwise store Vietnamese text as mojibake.
SET NAMES utf8mb4;

SET @a1 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập chuyên cần – Tuần 10' LIMIT 1);
INSERT INTO Assignment
    (section_id, title, description, instructions, publication_date,
     attachment_path, attachment_name, attachment_size, attachment_mime,
     due_date, max_score, status, is_deleted, created_by)
SELECT 36,
       '[DEMO] Bài tập chuyên cần – Tuần 10',
       'Bài tập minh họa để trải nghiệm luồng nộp bài và chấm điểm của UniPortal.',
       'Sinh viên nộp file PDF hoặc TXT. Bài nộp sau hạn sẽ được đánh dấu Nộp muộn.',
       DATE_SUB(NOW(), INTERVAL 2 DAY),
       'assignments/36/demo-de-bai.txt', 'de-bai-demo.txt', 164, 'text/plain',
       DATE_ADD(NOW(), INTERVAL 7 DAY), 10, 'Published', 0, 17
FROM DUAL
WHERE @a1 IS NULL;

SET @a1 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập chuyên cần – Tuần 10' LIMIT 1);

-- The app's PDO session uses PHP's timezone while the mysql CLI uses the
-- server timezone. Normalise the publication date so the assignment is
-- visible regardless of which timezone seeded it.
UPDATE Assignment
SET publication_date = DATE_SUB(NOW(), INTERVAL 2 DAY)
WHERE assignment_id = @a1
  AND (publication_date IS NULL OR publication_date > DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO AssignmentAttachment (assignment_id, file_path, file_name, file_size, file_mime)
SELECT @a1, 'assignments/36/demo-de-bai.txt', 'de-bai-demo.txt', 164, 'text/plain'
FROM DUAL
WHERE @a1 IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM AssignmentAttachment
                  WHERE assignment_id = @a1 AND file_name = 'de-bai-demo.txt');

SET @a2 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập nháp – Giảng viên đang soạn' LIMIT 1);
INSERT INTO Assignment
    (section_id, title, description, due_date, max_score, status, is_deleted, created_by)
SELECT 36,
       '[DEMO] Bài tập nháp – Giảng viên đang soạn',
       'Bản nháp này chỉ giảng viên nhìn thấy. Dùng nút "Công bố" để phát hành cho sinh viên.',
       DATE_ADD(NOW(), INTERVAL 10 DAY), 10, 'Draft', 0, 17
FROM DUAL
WHERE @a2 IS NULL;

SET @a3 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập đã đóng – Tuần 9' LIMIT 1);
INSERT INTO Assignment
    (section_id, title, description, publication_date, due_date, max_score, status, is_deleted, created_by)
SELECT 36,
       '[DEMO] Bài tập đã đóng – Tuần 9',
       'Bài tập đã hết hạn và được đóng; sinh viên không thể nộp thêm.',
       DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 10, 'Closed', 0, 17
FROM DUAL
WHERE @a3 IS NULL;

SET @e1  := (SELECT enrollment_id FROM Enrollment
             WHERE student_id = '23070001' AND section_id = 36
               AND status IN ('Enrolled','Completed') LIMIT 1);

SET @sub := (SELECT submission_id FROM Submission
             WHERE assignment_id = @a1 AND enrollment_id = @e1 LIMIT 1);
INSERT INTO Submission
    (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime,
     submitted_at, score, feedback, grade_status, version)
SELECT @a1, @e1,
       'submissions/36/demo-bai-nop.txt', 'bai-nop-demo.txt', 113, 'text/plain',
       DATE_SUB(NOW(), INTERVAL 1 DAY), 8.50,
       'Bài làm tốt, trình bày rõ ràng và đúng yêu cầu.', 'Published', 1
FROM DUAL
WHERE @a1 IS NOT NULL AND @e1 IS NOT NULL AND @sub IS NULL;

SET @sub := (SELECT submission_id FROM Submission
             WHERE assignment_id = @a1 AND enrollment_id = @e1 LIMIT 1);

-- Two historical grade changes so the "Lịch sử" dialog has content.
INSERT INTO SubmissionGradeHistory
    (submission_id, old_score, new_score, old_feedback, new_feedback,
     old_grade_status, new_grade_status, changed_by, changed_at)
SELECT @sub, NULL, 7.50, NULL,
       'Bài làm khá tốt, cần bổ sung phần kiểm thử.', 'Draft', 'Published', 17,
       DATE_SUB(NOW(), INTERVAL 2 DAY)
FROM DUAL
WHERE @sub IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM SubmissionGradeHistory WHERE submission_id = @sub);

INSERT INTO SubmissionGradeHistory
    (submission_id, old_score, new_score, old_feedback, new_feedback,
     old_grade_status, new_grade_status, changed_by, changed_at)
SELECT @sub, 7.50, 8.50,
       'Bài làm khá tốt, cần bổ sung phần kiểm thử.',
       'Bài làm tốt, trình bày rõ ràng và đúng yêu cầu.', 'Published', 'Published', 17,
       DATE_SUB(NOW(), INTERVAL 1 DAY)
FROM DUAL
WHERE @sub IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM SubmissionGradeHistory
                  WHERE submission_id = @sub AND old_score = 7.50 AND new_score = 8.50);

SELECT '[DEMO] assignment ids' AS info, @a1 AS published_id, @a2 AS draft_id, @a3 AS closed_id,
       @sub AS graded_submission_id;
