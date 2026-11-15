<?php
/**
 * UniPortal – Secure File Download Handler
 *
 * Serves uploaded files (assignment attachments, student submissions,
 * payment evidence) without exposing them through public URLs.
 *
 * Authentication required: user must be logged in.
 * Authorization: students can only download their own submissions and
 *   attachments for their enrolled classes; lecturers can download
 *   files for their assigned sections; admins can download anything.
 *
 * Usage: download.php?type=assignment_attachment&id=123
 *        download.php?type=submission&id=456
 *        download.php?type=assignment&id=789  (legacy single-file)
 *        download.php?type=submission_attachment&id=101
 */
$root = dirname(__DIR__);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/config/database.php';

require_login();

$pdo     = get_db();
$type    = $_GET['type'] ?? '';
$fileId  = (int)($_GET['id'] ?? 0);
$stuId   = $_SESSION['student_id'] ?? '';
$lecId   = (int)($_SESSION['lecturer_id'] ?? 0);
$role    = $_SESSION['role'] ?? '';
$accId   = (int)($_SESSION['account_id'] ?? 0);

if ($fileId < 1 || !in_array($type, ['assignment_attachment', 'submission', 'assignment', 'submission_attachment'])) {
    abort(404, 'File not found');
}

$filePath = null;
$fileName = null;
$fileMime = null;
$fileSize = null;

try {
    switch ($type) {
        // ── Assignment attachment (lecturer uploaded files for students to download) ──
        case 'assignment_attachment':
            $stmt = $pdo->prepare(
                'SELECT aa.*, a.section_id, a.assignment_id
                 FROM AssignmentAttachment aa
                 JOIN Assignment a ON a.assignment_id = aa.assignment_id
                 WHERE aa.attachment_id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch();
            if (!$file) abort(404, 'File not found');

            // Authorization: student must be enrolled in the section
            if ($role === 'Student') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM Enrollment e
                     WHERE e.student_id = :sid AND e.section_id = :sec
                       AND e.status IN (\'Enrolled\', \'Completed\') LIMIT 1'
                );
                $chk->execute([':sid' => $stuId, ':sec' => $file['section_id']]);
                if (!$chk->fetch()) abort(403, 'You are not enrolled in this class');
            }
            // Lecturer must own the section
            if ($role === 'Lecturer') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM ClassSection cs WHERE cs.section_id = :sec AND cs.lecturer_id = :lid'
                );
                $chk->execute([':sec' => $file['section_id'], ':lid' => $lecId]);
                if (!$chk->fetch()) abort(403, 'Not your class');
            }
            $filePath = $file['file_path'];
            $fileName = $file['file_name'];
            $fileMime = $file['file_mime'];
            $fileSize = (int)($file['file_size'] ?? 0);
            break;

        // ── Legacy single-file assignment attachment ──
        case 'assignment':
            $stmt = $pdo->prepare(
                'SELECT a.attachment_path, a.attachment_name, a.attachment_size, a.attachment_mime, a.section_id
                 FROM Assignment a WHERE a.assignment_id = :id AND a.is_deleted = 0 LIMIT 1'
            );
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch();
            if (!$file || !$file['attachment_path']) abort(404, 'File not found');

            if ($role === 'Student') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM Enrollment e WHERE e.student_id = :sid AND e.section_id = :sec
                       AND e.status IN (\'Enrolled\', \'Completed\') LIMIT 1'
                );
                $chk->execute([':sid' => $stuId, ':sec' => $file['section_id']]);
                if (!$chk->fetch()) abort(403, 'You are not enrolled in this class');
            }
            if ($role === 'Lecturer') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM ClassSection cs WHERE cs.section_id = :sec AND cs.lecturer_id = :lid'
                );
                $chk->execute([':sec' => $file['section_id'], ':lid' => $lecId]);
                if (!$chk->fetch()) abort(403, 'Not your class');
            }
            $filePath = $file['attachment_path'];
            $fileName = $file['attachment_name'];
            $fileMime = $file['attachment_mime'];
            $fileSize = (int)($file['attachment_size'] ?? 0);
            break;

        // ── Student submission file ──
        case 'submission':
            $stmt = $pdo->prepare(
                'SELECT sub.*, a.section_id
                 FROM Submission sub
                 JOIN Assignment a ON a.assignment_id = sub.assignment_id
                 WHERE sub.submission_id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch();
            if (!$file || !$file['file_path']) abort(404, 'File not found');

            if ($role === 'Student') {
                // Student can only download their own submissions
                $chk = $pdo->prepare(
                    'SELECT 1 FROM Enrollment e WHERE e.enrollment_id = :eid AND e.student_id = :sid LIMIT 1'
                );
                $chk->execute([':eid' => $file['enrollment_id'], ':sid' => $stuId]);
                if (!$chk->fetch()) abort(403, 'Not your submission');
            }
            if ($role === 'Lecturer') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM ClassSection cs WHERE cs.section_id = :sec AND cs.lecturer_id = :lid'
                );
                $chk->execute([':sec' => $file['section_id'], ':lid' => $lecId]);
                if (!$chk->fetch()) abort(403, 'Not your class');
            }
            $filePath = $file['file_path'];
            $fileName = $file['file_name'];
            $fileMime = $file['file_mime'];
            $fileSize = (int)($file['file_size'] ?? 0);
            break;

        // ── Submission attachment (multi-file submission) ──
        case 'submission_attachment':
            $stmt = $pdo->prepare(
                'SELECT sa.*, sub.enrollment_id, a.section_id
                 FROM SubmissionAttachment sa
                 JOIN Submission sub ON sub.submission_id = sa.submission_id
                 JOIN Assignment a ON a.assignment_id = sub.assignment_id
                 WHERE sa.sub_attachment_id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $fileId]);
            $file = $stmt->fetch();
            if (!$file) abort(404, 'File not found');

            if ($role === 'Student') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM Enrollment e WHERE e.enrollment_id = :eid AND e.student_id = :sid LIMIT 1'
                );
                $chk->execute([':eid' => $file['enrollment_id'], ':sid' => $stuId]);
                if (!$chk->fetch()) abort(403, 'Not your submission');
            }
            if ($role === 'Lecturer') {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM ClassSection cs WHERE cs.section_id = :sec AND cs.lecturer_id = :lid'
                );
                $chk->execute([':sec' => $file['section_id'], ':lid' => $lecId]);
                if (!$chk->fetch()) abort(403, 'Not your class');
            }
            $filePath = $file['file_path'];
            $fileName = $file['file_name'];
            $fileMime = $file['file_mime'];
            $fileSize = (int)($file['file_size'] ?? 0);
            break;
    }
} catch (Throwable $e) {
    abort(500, 'Database error');
}

if (!$filePath) abort(404, 'File not found');

// Build full filesystem path
$fullPath = $root . '/storage/uploads/' . $filePath;
if (!file_exists($fullPath) || !is_file($fullPath)) {
    abort(404, 'File not found on disk');
}

// Prevent directory traversal
$realPath = realpath($fullPath);
$storageRoot = realpath($root . '/storage/uploads');
if ($realPath === false || $storageRoot === false || !str_starts_with($realPath, $storageRoot)) {
    abort(403, 'Access denied');
}

// Set headers for file download
header('Content-Description: File Transfer');
header('Content-Type: ' . ($fileMime ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName ?? 'download') . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
if ($fileSize > 0) header('Content-Length: ' . $fileSize);

// Clear any output buffers and serve the file
ob_clean();
flush();
readfile($realPath);
exit;
