<?php
/**
 * UniPortal – Bài tập & Nộp bài (Student Assignments)
 * View assignments from enrolled sections, upload submissions,
 * view feedback and grades.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id'] ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);
$full_name  = $_SESSION['full_name']   ?? '';

// Storage path (outside web root)
$storageBase = $root . '/storage/uploads/submissions';
if (!is_dir($storageBase)) {
    mkdir($storageBase, 0755, true);
}

// Submission policy (single source of truth for UI + POST handler)
$allowLate = false;
try {
    $lateCfg   = $pdo->query("SELECT config_value FROM SystemConfig WHERE config_key='late_submission_allowed'")->fetchColumn();
    $allowLate = (int)$lateCfg === 1;
} catch (Throwable) {
    $allowLate = false;
}

$flash = '';

// ── Handle file submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    csrf_verify();
    $assignment_id  = (int)($_POST['assignment_id']  ?? 0);
    $enrollment_id  = (int)($_POST['enrollment_id']  ?? 0);

    // Validate ownership: enrollment must belong to this student
    $ownChk = $pdo->prepare(
        'SELECT enrollment_id, section_id FROM Enrollment
         WHERE enrollment_id=:eid AND student_id=:sid LIMIT 1'
    );
    $ownChk->execute([':eid' => $enrollment_id, ':sid' => $student_id]);
    $ownEnrollment = $ownChk->fetch();

    if (!$ownEnrollment) {
        $flash = 'error:Không có quyền nộp bài cho lớp này.';
    } else {
        // Assignment must belong to the same section the student enrolled in,
        // must not be deleted, and must be open for submissions.
        $asgRow = $pdo->prepare(
            'SELECT a.*
             FROM Assignment a
             WHERE a.assignment_id=:aid
               AND a.section_id=:sec
               AND a.is_deleted=0
             LIMIT 1'
        );
        $asgRow->execute([':aid' => $assignment_id, ':sec' => $ownEnrollment['section_id']]);
        $asg = $asgRow->fetch();

        if (!$asg) {
            $flash = 'error:Bài tập không tồn tại hoặc không thuộc lớp học của bạn.';
        } elseif ($asg['status'] !== 'Published') {
            $flash = 'error:Bài tập chưa được công bố hoặc đã đóng, không thể nộp.';
        } elseif ($asg['publication_date'] !== null && strtotime($asg['publication_date']) > time()) {
            $flash = 'error:Bài tập chưa được công bố, không thể nộp.';
        } elseif (strtotime($asg['due_date']) < time() && !$allowLate) {
            $flash = 'error:Đã quá hạn nộp bài.';
        } elseif (!isset($_FILES['submission_files']) || empty($_FILES['submission_files']['name'][0])) {
            $flash = 'error:Vui lòng chọn ít nhất một file để nộp.';
        } else {
            $files = $_FILES['submission_files'];
            $maxSize = 20 * 1024 * 1024; // 20 MB per file
            $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','rar','jpg','jpeg','png','txt','py','java','cpp','c'];

            // Validate all files first
            $validFiles = [];
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if ($files['size'][$i] > $maxSize) {
                    $flash = 'error:File "' . $files['name'][$i] . '" quá lớn (tối đa 20 MB).';
                    break;
                }
                if (!in_array($ext, $allowed)) {
                    $flash = 'error:Định dạng file "' . $files['name'][$i] . '" không được phép.';
                    break;
                }
                $validFiles[] = $i;
            }

            if (!$flash && empty($validFiles)) {
                $flash = 'error:Không có file hợp lệ để nộp. Vui lòng thử lại.';
            }

            if (!$flash && !empty($validFiles)) {
                // Check if resubmit (existing submission)
                $existingSub = $pdo->prepare(
                    'SELECT submission_id, version, grade_status, score
                     FROM Submission
                     WHERE assignment_id = :aid AND enrollment_id = :eid LIMIT 1'
                );
                $existingSub->execute([':aid' => $assignment_id, ':eid' => $enrollment_id]);
                $oldSub = $existingSub->fetch();

                if ($oldSub && $oldSub['grade_status'] === 'Published' && $oldSub['score'] !== null) {
                    $flash = 'error:Bài đã được chấm điểm và công bố, không thể nộp lại.';
                } else {
                    $dir = $storageBase . '/' . $assignment_id;
                    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                        $flash = 'error:Không thể tạo thư mục lưu bài. Vui lòng thử lại.';
                    }
                }

                if (!$flash) {
                    $newVersion = $oldSub ? (int)$oldSub['version'] + 1 : 1;
                    $movedFiles = [];
                    $subId      = 0;

                    try {
                        // Move every validated file to storage first
                        $savedFiles = [];
                        foreach ($validFiles as $idx) {
                            $ext      = strtolower(pathinfo($files['name'][$idx], PATHINFO_EXTENSION));
                            $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
                            $destPath = $dir . '/' . $safeName;
                            if (!move_uploaded_file($files['tmp_name'][$idx], $destPath)) {
                                throw new RuntimeException('Không thể lưu file "' . $files['name'][$idx] . '".');
                            }
                            $movedFiles[] = $destPath;
                            $savedFiles[] = [
                                'rel'  => 'submissions/' . $assignment_id . '/' . $safeName,
                                'name' => $files['name'][$idx],
                                'size' => $files['size'][$idx],
                                'mime' => $files['type'][$idx],
                            ];
                        }

                        $primary = $savedFiles[0];

                        $pdo->beginTransaction();

                        if ($oldSub) {
                            $pdo->prepare(
                                'UPDATE Submission SET
                                    file_path = :fp, file_name = :fn, file_size = :fs, file_mime = :fm,
                                    submitted_at = NOW(), version = :ver,
                                    score = NULL, feedback = NULL, grade_status = \'Draft\'
                                 WHERE submission_id = :sid'
                            )->execute([
                                ':fp'  => $primary['rel'],
                                ':fn'  => $primary['name'],
                                ':fs'  => $primary['size'],
                                ':fm'  => $primary['mime'],
                                ':ver' => $newVersion,
                                ':sid' => $oldSub['submission_id'],
                            ]);
                            $subId = (int)$oldSub['submission_id'];
                            $pdo->prepare('DELETE FROM SubmissionAttachment WHERE submission_id = :sid')
                                ->execute([':sid' => $subId]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO Submission
                                    (assignment_id, enrollment_id, file_path, file_name, file_size,
                                     file_mime, submitted_at, grade_status, version)
                                 VALUES (:aid, :eid, :fp, :fn, :fs, :fm, NOW(), \'Draft\', 1)'
                            )->execute([
                                ':aid' => $assignment_id, ':eid' => $enrollment_id,
                                ':fp'  => $primary['rel'], ':fn' => $primary['name'],
                                ':fs'  => $primary['size'], ':fm' => $primary['mime'],
                            ]);
                            $subId = (int)$pdo->lastInsertId();
                        }

                        $attIns = $pdo->prepare(
                            'INSERT INTO SubmissionAttachment (submission_id, file_path, file_name, file_size, file_mime)
                             VALUES (:sid, :fp, :fn, :fs, :fm)'
                        );
                        foreach ($savedFiles as $sf) {
                            $attIns->execute([
                                ':sid' => $subId, ':fp' => $sf['rel'], ':fn' => $sf['name'],
                                ':fs' => $sf['size'], ':fm' => $sf['mime'],
                            ]);
                        }

                        $pdo->commit();

                        $flash = 'success:Nộp bài thành công! ' . count($savedFiles)
                               . ' file – ' . $primary['name']
                               . ($oldSub ? ' (phiên bản ' . $newVersion . ')' : '');

                        NotificationService::send(
                            $account_id, 'Assignment',
                            '📤 Nộp bài thành công',
                            'Bài tập: ' . $asg['title'],
                            '/UniPortal/public/assets/student/assignments.php'
                        );
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        foreach ($movedFiles as $path) {
                            @unlink($path);
                        }
                        $flash = 'error:Lỗi lưu bài nộp. Vui lòng thử lại.';
                    }
                }
            }
        }
    }
}

// ── Load assignments ──────────────────────────────────────────
$semRow = $pdo->query("SELECT * FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1")->fetch();
$semId  = $semRow ? (int)$semRow['semester_id'] : 0;

// All assignments for the student's enrolled sections
$asgStmt = $pdo->prepare(
    'SELECT
        a.assignment_id, a.title, a.description, a.instructions, a.due_date,
        a.max_score, a.attachment_name, a.attachment_path, a.status,
        cs.section_code, cs.section_id,
        c.course_id, c.course_code, c.course_name,
        e.enrollment_id,
        sub.submission_id, sub.file_path AS sub_path, sub.file_name AS sub_file, sub.submitted_at,
        sub.score AS sub_score, sub.feedback AS sub_feedback, sub.grade_status, sub.version AS sub_version
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Course c        ON c.course_id   = cs.course_id
     JOIN Assignment a    ON a.section_id  = cs.section_id AND a.is_deleted = 0
                          AND a.status IN (\'Published\',\'Closed\')
                          AND (a.publication_date IS NULL OR a.publication_date <= NOW())
     LEFT JOIN Submission sub ON sub.assignment_id = a.assignment_id
                              AND sub.enrollment_id = e.enrollment_id
     WHERE e.student_id    = :sid
       AND cs.semester_id  = :sem
       AND e.status        IN (\'Enrolled\',\'Completed\')
     ORDER BY a.due_date ASC'
);
$asgStmt->execute([':sid' => $student_id, ':sem' => $semId]);
$allAssignments = $asgStmt->fetchAll();

// Fetch attachments for each assignment
$asgAttachments = [];
if (!empty($allAssignments)) {
    $asgIds = array_unique(array_column($allAssignments, 'assignment_id'));
    if (!empty($asgIds)) {
        $placeholders = implode(',', array_fill(0, count($asgIds), '?'));
        $attStmt = $pdo->prepare(
            "SELECT assignment_id, attachment_id, file_name, file_size
             FROM AssignmentAttachment WHERE assignment_id IN ($placeholders)"
        );
        $attStmt->execute(array_values($asgIds));
        foreach ($attStmt->fetchAll() as $att) {
            $asgAttachments[$att['assignment_id']][] = $att;
        }
    }
}

// Fetch submission attachments
$subAttachments = [];
foreach ($allAssignments as $asg) {
    if ($asg['submission_id']) {
        $saStmt = $pdo->prepare(
            'SELECT sub_attachment_id, file_name, file_size FROM SubmissionAttachment WHERE submission_id = ?'
        );
        $saStmt->execute([$asg['submission_id']]);
        $subAttachments[$asg['submission_id']] = $saStmt->fetchAll();
    }
}

// Stats
$isPublished = fn(array $a): bool => $a['submission_id'] !== null
    && $a['sub_score'] !== null
    && ($a['grade_status'] ?? '') === 'Published';

$now       = time();
$totalAsg  = count($allAssignments);
$submitted = count(array_filter($allAssignments, fn($a) => $a['submission_id'] !== null));
$pending   = $totalAsg - $submitted;
$graded    = count(array_filter($allAssignments, $isPublished));
$overdue   = count(array_filter($allAssignments, fn($a) => !$a['submission_id'] && strtotime($a['due_date']) < $now));

// ── Filters: course / deadline / status ──────────────────────
$courseF = (int)($_GET['course'] ?? 0);
$dlF     = in_array($_GET['dl'] ?? '', ['week','month','past'], true) ? $_GET['dl'] : '';
$stF     = in_array($_GET['st'] ?? '', ['pending','submitted','late','graded','overdue','closed'], true) ? $_GET['st'] : '';

$courseOptions = [];
foreach ($allAssignments as $a) {
    $courseOptions[$a['course_id']] = $a['course_code'] . ' – ' . $a['course_name'];
}
ksort($courseOptions);

$assignments = array_values(array_filter($allAssignments, function (array $a) use ($courseF, $dlF, $stF, $isPublished, $now): bool {
    if ($courseF && (int)$a['course_id'] !== $courseF) return false;

    $due = strtotime($a['due_date']);
    if ($dlF === 'week'  && ($due < $now || $due > $now + 7 * 86400))  return false;
    if ($dlF === 'month' && ($due < $now || $due > $now + 30 * 86400)) return false;
    if ($dlF === 'past'  && $due >= $now) return false;

    if ($stF === '') return true;
    $hasSubmit = $a['submission_id'] !== null;
    $late      = $hasSubmit && !empty($a['submitted_at']) && strtotime($a['submitted_at']) > $due;
    return match ($stF) {
        'pending'   => !$hasSubmit && $due >= $now && $a['status'] === 'Published',
        'submitted' => $hasSubmit,
        'late'      => $late,
        'graded'    => $isPublished($a),
        'overdue'   => !$hasSubmit && $due < $now,
        'closed'    => $a['status'] === 'Closed',
        default     => true,
    };
}));

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }

$pageTitle  = 'Bài tập & Nộp bài';
$activePage = 'assignments';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<?php if ($flash): [$ft, $fm] = explode(':', $flash, 2); ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;
            background:<?= $ft==='success'?'#d1fae5':'#fee2e2' ?>;
            border:1px solid <?= $ft==='success'?'#6ee7b7':'#fca5a5' ?>;
            color:<?= $ft==='success'?'#065f46':'#b91c1c' ?>;">
    <?= $ft==='success' ? '✅' : '⚠️' ?> <?= h($fm) ?>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="stat-row">
    <div class="stat-mini"><div class="stat-mini__label">Tổng bài tập</div><div class="stat-mini__value"><?= $totalAsg ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Đã nộp</div><div class="stat-mini__value" style="color:#059669;"><?= $submitted ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Chưa nộp</div><div class="stat-mini__value" style="color:#f59e0b;"><?= $pending ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Quá hạn</div><div class="stat-mini__value" style="color:#ef4444;"><?= $overdue ?></div></div>
    <div class="stat-mini"><div class="stat-mini__label">Đã có điểm</div><div class="stat-mini__value" style="color:#3b82f6;"><?= $graded ?></div></div>
</div>

<!-- Filters -->
<div class="card" style="padding:14px 18px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <select name="course" style="height:36px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;">
            <option value="">Tất cả học phần</option>
            <?php foreach ($courseOptions as $cid => $clabel): ?>
            <option value="<?= (int)$cid ?>" <?= $courseF === (int)$cid ? 'selected' : '' ?>><?= h($clabel) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="dl" style="height:36px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;">
            <option value="">Mọi hạn nộp</option>
            <option value="week"  <?= $dlF === 'week'  ? 'selected' : '' ?>>Trong 7 ngày tới</option>
            <option value="month" <?= $dlF === 'month' ? 'selected' : '' ?>>Trong 30 ngày tới</option>
            <option value="past"  <?= $dlF === 'past'  ? 'selected' : '' ?>>Đã qua hạn</option>
        </select>
        <select name="st" style="height:36px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;">
            <option value="">Mọi trạng thái</option>
            <option value="pending"   <?= $stF === 'pending'   ? 'selected' : '' ?>>Chờ nộp</option>
            <option value="submitted" <?= $stF === 'submitted' ? 'selected' : '' ?>>Đã nộp</option>
            <option value="late"      <?= $stF === 'late'      ? 'selected' : '' ?>>Nộp muộn</option>
            <option value="graded"    <?= $stF === 'graded'    ? 'selected' : '' ?>>Đã có điểm</option>
            <option value="overdue"   <?= $stF === 'overdue'   ? 'selected' : '' ?>>Quá hạn</option>
            <option value="closed"    <?= $stF === 'closed'    ? 'selected' : '' ?>>Đã đóng</option>
        </select>
        <button type="submit" style="height:36px;padding:0 16px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">🔍 Lọc</button>
        <?php if ($courseF || $dlF || $stF): ?>
        <a href="assignments.php" style="height:36px;display:inline-flex;align-items:center;padding:0 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#64748b;text-decoration:none;">✕ Xóa lọc</a>
        <?php endif; ?>
        <span style="font-size:12px;color:#94a3b8;margin-left:auto;">Hiển thị <?= count($assignments) ?> / <?= $totalAsg ?> bài tập</span>
    </form>
</div>

<?php if (empty($assignments)): ?>
<div class="card" style="padding:40px;text-align:center;color:#94a3b8;">
    <div style="font-size:32px;margin-bottom:8px;">📋</div>
    <p><?= $totalAsg > 0 ? 'Không có bài tập nào khớp bộ lọc.' : 'Chưa có bài tập nào trong học kỳ này.' ?></p>
    <?php if ($totalAsg > 0): ?>
    <a href="assignments.php" style="font-size:13px;color:#3b82f6;">Xóa bộ lọc</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:14px;">
    <?php foreach ($assignments as $asg):
        $isOverdue   = strtotime($asg['due_date']) < time();
        $hasSubmit   = $asg['submission_id'] !== null;
        $isGraded    = $isPublished($asg);
        $isClosed    = $asg['status'] === 'Closed';
        $isLate      = $hasSubmit && !empty($asg['submitted_at'])
                     && strtotime($asg['submitted_at']) > strtotime($asg['due_date']);
        $timeLeft    = strtotime($asg['due_date']) - time();
        $urgentClass = !$hasSubmit && !$isClosed && $timeLeft < 86400 && $timeLeft > 0 ? 'border-left:4px solid #ef4444;' : '';
    ?>
    <div class="card" style="padding:20px 24px;<?= $urgentClass ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                    <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:4px;"><?= h($asg['course_code']) ?></span>
                    <span style="font-size:11px;color:#64748b;"><?= h($asg['section_code']) ?></span>
                    <?php if ($isClosed): ?>
                    <span style="background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;">🔒 Đã đóng</span>
                    <?php endif; ?>
                    <?php if ($isGraded): ?>
                    <span class="status-pill status-pill--complete" style="font-size:10px;">✅ Đã có điểm</span>
                    <?php elseif ($hasSubmit && $isLate): ?>
                    <span class="status-pill status-pill--absent" style="font-size:10px;">⏱️ Nộp muộn</span>
                    <?php elseif ($hasSubmit): ?>
                    <span class="status-pill status-pill--enrolled" style="font-size:10px;">📤 Đã nộp</span>
                    <?php elseif ($isOverdue): ?>
                    <span class="status-pill status-pill--absent" style="font-size:10px;">⏰ Quá hạn</span>
                    <?php else: ?>
                    <span style="background:#fef9c3;color:#854d0e;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;">Chờ nộp</span>
                    <?php endif; ?>
                </div>
                <h3 style="font-size:16px;font-weight:700;color:#001634;margin-bottom:6px;"><?= h($asg['title']) ?></h3>
                <?php if ($asg['description']): ?>
                <p style="font-size:13px;color:#44474e;margin-bottom:8px;line-height:1.5;"><?= nl2br(h($asg['description'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($asg['instructions'])): ?>
                <div style="font-size:12px;color:#334155;margin-bottom:8px;padding:8px 12px;background:#f8fafc;border-left:3px solid #102b50;border-radius:6px;">
                    <strong>📝 Yêu cầu bài nộp:</strong><br><?= nl2br(h($asg['instructions'])) ?>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:16px;font-size:12px;color:#64748b;flex-wrap:wrap;">
                    <span>📅 Hạn nộp: <strong style="color:<?= $isOverdue&&!$hasSubmit?'#ef4444':'#334155' ?>"><?= fmt_dt($asg['due_date']) ?></strong></span>
                    <?php if ($isLate): ?><span style="color:#b91c1c;">⏱️ Nộp lúc <?= fmt_dt($asg['submitted_at']) ?></span><?php endif; ?>
                    <span>📊 Điểm tối đa: <?= number_format((float)$asg['max_score'], 0) ?></span>
                    <?php
                    // Show attachments from AssignmentAttachment table
                    $atts = $asgAttachments[$asg['assignment_id']] ?? [];
                    if (!empty($atts)):
                        foreach ($atts as $att):
                    ?>
                    <span>📎 <a href="../../download.php?type=assignment_attachment&id=<?= (int)$att['attachment_id'] ?>" download style="color:#3b82f6;">
                        <?= h($att['file_name']) ?> (<?= $att['file_size'] ? round($att['file_size']/1024,1).'KB' : '' ?>)
                    </a></span>
                    <?php endforeach; ?>
                    <?php elseif ($asg['attachment_name']): ?>
                    <span>📎 <a href="../../download.php?type=assignment&id=<?= (int)$asg['assignment_id'] ?>" download style="color:#3b82f6;"><?= h($asg['attachment_name']) ?></a></span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Score badge if graded -->
            <?php if ($isGraded): ?>
            <div style="text-align:center;padding:12px 16px;background:#f0fdf4;border-radius:10px;min-width:80px;">
                <div style="font-size:24px;font-weight:800;color:#059669;"><?= number_format((float)$asg['sub_score'], 1) ?></div>
                <div style="font-size:11px;color:#64748b;">/ <?= number_format((float)$asg['max_score'], 0) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Submission feedback if graded -->
        <?php if ($isGraded && $asg['sub_feedback']): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:8px;border-left:3px solid #3b82f6;">
            <div style="font-size:12px;font-weight:600;color:#1e40af;margin-bottom:4px;">💬 Nhận xét của giảng viên:</div>
            <div style="font-size:13px;color:#334155;"><?= nl2br(h($asg['sub_feedback'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Submission info if already submitted -->
        <?php if ($hasSubmit): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;font-size:12px;color:#166534;">
            📤 Đã nộp (v<?= (int)$asg['sub_version'] ?>): <strong><?= h($asg['sub_file']) ?></strong>
            lúc <?= fmt_dt($asg['submitted_at']) ?>
            <?php
            $subAtts = $subAttachments[$asg['submission_id']] ?? [];
            if (!empty($subAtts)):
            ?>
            <br>Tổng cộng <?= count($subAtts) ?> file:
            <?php foreach ($subAtts as $sa): ?>
            <a href="../../download.php?type=submission_attachment&id=<?= (int)$sa['sub_attachment_id'] ?>" download style="color:#3b82f6;">
                <?= h($sa['file_name']) ?>
            </a>
            <?php if (next($subAtts)) echo '•'; ?>
            <?php endforeach; ?>
            <?php elseif ($asg['sub_path']): ?>
            <br><a href="../../download.php?type=submission&id=<?= (int)$asg['submission_id'] ?>" download style="color:#3b82f6;">
                Tải file đã nộp
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Upload form (not graded; assignment open; overdue only if late submissions are allowed) -->
        <?php if (!$isGraded && !$isClosed && (!$isOverdue || $allowLate)): ?>
        <div style="margin-top:14px;padding:14px;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1;">
            <div style="font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">
                <?= $hasSubmit ? '🔄 Nộp lại bài' : '📤 Nộp bài' ?>
                <?php if ($isOverdue): ?><span style="color:#ef4444;font-size:11px;margin-left:6px;">(Quá hạn – có thể bị trừ điểm)</span><?php endif; ?>
            </div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="submit_assignment" value="1" />
                <input type="hidden" name="assignment_id"    value="<?= (int)$asg['assignment_id'] ?>" />
                <input type="hidden" name="enrollment_id"    value="<?= (int)$asg['enrollment_id'] ?>" />
                <input type="file" name="submission_files[]" required multiple
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.jpg,.jpeg,.png,.txt,.py,.java,.cpp,.c"
                       style="font-size:13px;" />
                <button type="submit"
                        style="padding:8px 18px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    Nộp bài
                </button>
                <span style="font-size:11px;color:#94a3b8;">Tối đa 20 MB/file • Có thể chọn nhiều file • PDF, Word, ZIP, ảnh, code…</span>
            </form>
        </div>
        <?php elseif ($isGraded): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#eff6ff;border-radius:8px;font-size:12px;color:#1e40af;">
            🔒 Bài đã được chấm và công bố điểm, không thể nộp lại.
        </div>
        <?php elseif ($isClosed): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#fef2f2;border-radius:8px;font-size:12px;color:#b91c1c;">
            🔒 Bài tập đã đóng, giảng viên không nhận thêm bài nộp.
        </div>
        <?php elseif ($isOverdue): ?>
        <div style="margin-top:12px;padding:10px 14px;background:#fef2f2;border-radius:8px;font-size:12px;color:#b91c1c;">
            ⛔ Đã quá hạn nộp và hệ thống không cho phép nộp muộn.
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
