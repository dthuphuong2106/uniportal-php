-- ============================================================
-- UniPortal – Remove the optional demo workflow
-- Deletes only rows created by demo_academic_workflow.sql.
-- Upload files are not deleted by SQL; remove the two demo
-- files manually if desired:
--   storage/uploads/assignments/36/demo-de-bai.txt
--   storage/uploads/submissions/36/demo-bai-nop.txt
-- ============================================================

USE uniportal;

SET @a1 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập chuyên cần – Tuần 10' LIMIT 1);
SET @a2 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập nháp – Giảng viên đang soạn' LIMIT 1);
SET @a3 := (SELECT assignment_id FROM Assignment
            WHERE section_id = 36 AND title = '[DEMO] Bài tập đã đóng – Tuần 9' LIMIT 1);

DELETE h FROM SubmissionGradeHistory h
JOIN Submission sub ON sub.submission_id = h.submission_id
WHERE sub.assignment_id IN (@a1, @a2, @a3);

DELETE FROM Submission WHERE assignment_id IN (@a1, @a2, @a3);
DELETE FROM AssignmentAttachment WHERE assignment_id IN (@a1, @a2, @a3);
DELETE FROM Assignment WHERE assignment_id IN (@a1, @a2, @a3);

SELECT '[DEMO] remaining assignments' AS info, COUNT(*) AS total
FROM Assignment WHERE title LIKE '[DEMO]%';
