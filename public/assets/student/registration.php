<?php
/**
 * UniPortal – Đăng ký môn học (period based)
 *
 * Students only see sections that belong to their own curriculum and to the
 * registration periods configured for their major. Capacity, schedule,
 * prerequisite and race-condition rules live in RegistrationService.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/services/NotificationService.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$pdo        = get_db();
$student_id = $_SESSION['student_id']   ?? '';
$account_id = (int)($_SESSION['account_id'] ?? 0);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$student = RegistrationService::studentMajor($pdo, $student_id);

// ── Flash (post/redirect/get) ─────────────────────────────────
$flash = $_SESSION['_reg_flash'] ?? null;
unset($_SESSION['_reg_flash']);

// ── Handle POST: register / cancel ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action     = $_POST['action']     ?? '';
    $section_id = (int)($_POST['section_id'] ?? 0);
    $period_id  = (int)($_POST['period_id']  ?? 0);

    if ($action === 'enroll') {
        if ($period_id < 1) {
            $flash = ['type' => 'error', 'message' => 'Vui lòng chọn đợt đăng ký đang mở.'];
        } else {
            $res   = RegistrationService::register($pdo, $student_id, $account_id, $section_id, $period_id);
            $flash = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
        }
    } elseif ($action === 'drop') {
        $res   = RegistrationService::drop($pdo, $student_id, $account_id, $section_id, $period_id ?: null);
        $flash = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
    } else {
        $flash = ['type' => 'error', 'message' => 'Thao tác không hợp lệ.'];
    }

    $_SESSION['_reg_flash'] = $flash;
    $qs = $period_id > 0 ? '?period_id=' . $period_id : '';
    header('Location: registration.php' . $qs);
    exit;
}

// ── Periods available to this student's major ─────────────────
$periods = $student ? RegistrationService::eligiblePeriodsForStudent($pdo, $student_id) : [];

$requestedPeriodId = (int)($_GET['period_id'] ?? 0);
$selectedPeriod    = null;
foreach ($periods as $p) {
    if ((int)$p['period_id'] === $requestedPeriodId) { $selectedPeriod = $p; break; }
}
if (!$selectedPeriod && $periods) {
    // Prefer the open period, otherwise the nearest upcoming one.
    foreach ($periods as $p) {
        if (RegistrationService::periodIsOpen($p)) { $selectedPeriod = $p; break; }
    }
    $selectedPeriod = $selectedPeriod ?: $periods[0];
}

$isOpen   = $selectedPeriod ? RegistrationService::periodIsOpen($selectedPeriod) : false;
$semId    = $selectedPeriod ? (int)$selectedPeriod['semester_id'] : 0;
$periodId = $selectedPeriod ? (int)$selectedPeriod['period_id'] : 0;

// ── My registrations for the selected semester ────────────────
$mySectionRows = [];
$mySectionIds  = [];
$myCourseIds   = [];
if ($semId) {
    $q = $pdo->prepare(
        "SELECT e.section_id,
                cs.course_id, cs.section_code, cs.section_status, cs.status_note,
                cs.schedule, cs.study_days, cs.start_time, cs.end_time, cs.room,
                cs.min_enrollment, cs.max_enrollment, cs.capacity,
                c.course_code, c.course_name, c.credits,
                l.full_name AS lecturer_name
         FROM Enrollment e
         JOIN ClassSection cs ON cs.section_id = e.section_id
         JOIN Course c ON c.course_id = cs.course_id
         JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
         WHERE e.student_id = :sid AND cs.semester_id = :sem AND e.status = 'Enrolled'
         ORDER BY c.course_code"
    );
    $q->execute([':sid' => $student_id, ':sem' => $semId]);
    $mySectionRows = $q->fetchAll();
    foreach ($mySectionRows as $row) {
        $mySectionIds[] = (int)$row['section_id'];
        $myCourseIds[]  = (int)$row['course_id'];
    }
}

// Courses already completed (blocks duplicates + verifies prerequisites).
$q = $pdo->prepare(
    "SELECT DISTINCT cs.course_id FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     WHERE e.student_id = :sid AND e.status = 'Completed'"
);
$q->execute([':sid' => $student_id]);
$completedCourseIds = array_map('intval', array_column($q->fetchAll(), 'course_id'));

$passedCourseIds = [];
$q = $pdo->prepare(
    "SELECT cs.course_id
     FROM Enrollment e
     JOIN ClassSection cs ON cs.section_id = e.section_id
     JOIN Assessment a ON a.section_id = cs.section_id
     JOIN Grade g ON g.enrollment_id = e.enrollment_id AND g.assessment_id = a.assessment_id
     WHERE e.student_id = :sid AND e.status = 'Completed' AND g.score IS NOT NULL
     GROUP BY e.enrollment_id, cs.course_id
     HAVING ROUND(SUM(g.score * a.weight_percent / 100) / 10, 1) >= 4.0"
);
$q->execute([':sid' => $student_id]);
foreach ($q->fetchAll() as $row) $passedCourseIds[] = (int)$row['course_id'];

// ── Sections offered in the period (curriculum filtered) ──────
$searchQ = trim($_GET['q']    ?? '');
$deptF   = trim($_GET['dept'] ?? '');

$sections = [];
$depts    = [];
if ($semId && $student && !empty($student['major_id'])) {
    $hasSectionMapping = (int)$pdo->query(
        'SELECT COUNT(*) FROM registrationperiodsection WHERE period_id=' . (int)$periodId
    )->fetchColumn() > 0;

    $sql = "SELECT cs.*,
                   c.course_code, c.course_name, c.credits, c.prerequisite_course_id,
                   pc.course_code AS prereq_code, pc.course_name AS prereq_name,
                   d.department_name, l.full_name AS lecturer_name,
                   (SELECT COUNT(*) FROM Enrollment e2
                     WHERE e2.section_id = cs.section_id
                       AND e2.status IN ('Enrolled','Completed')) AS enrolled_count
            FROM ClassSection cs
            JOIN Course c ON c.course_id = cs.course_id
            LEFT JOIN Course pc ON pc.course_id = c.prerequisite_course_id
            JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
            LEFT JOIN Department d ON d.department_id = c.department_id
            WHERE cs.semester_id = :sem
              AND cs.section_status <> 'Cancelled'
              AND c.is_active = 1
              AND EXISTS (
                    SELECT 1 FROM Curriculum cur
                    JOIN CurriculumCourse cc ON cc.curriculum_id = cur.curriculum_id
                    WHERE cur.major_id = :mid AND cur.admission_year = :ay
                      AND cc.course_id = cs.course_id
              )";

    $params = [
        ':sem' => $semId,
        ':mid' => (int)$student['major_id'],
        ':ay'  => (int)$student['admission_year'],
    ];

    if ($hasSectionMapping) {
        $sql .= ' AND EXISTS (
                    SELECT 1 FROM registrationperiodsection rps
                    WHERE rps.period_id = :pid AND rps.section_id = cs.section_id
                  )';
        $params[':pid'] = $periodId;
    }
    if ($searchQ !== '') {
        $sql .= ' AND (c.course_code LIKE :q OR c.course_name LIKE :q OR cs.section_code LIKE :q)';
        $params[':q'] = '%' . $searchQ . '%';
    }
    if ($deptF !== '') {
        $sql .= ' AND c.department_id = :dept';
        $params[':dept'] = (int)$deptF;
    }
    $sql .= ' ORDER BY c.course_code, cs.section_code';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sections = $stmt->fetchAll();

    $depts = $pdo->prepare(
        'SELECT DISTINCT d.department_id, d.department_name
         FROM Department d
         JOIN Course c ON c.department_id = d.department_id
         JOIN ClassSection cs ON cs.course_id = c.course_id
         WHERE cs.semester_id = :sem ORDER BY d.department_name'
    );
    $depts->execute([':sem' => $semId]);
    $depts = $depts->fetchAll();
}

$registeredCredits = array_sum(array_column($mySectionRows, 'credits'));
$confirmedCount    = 0;
foreach ($mySectionRows as $row) {
    if ($row['section_status'] === 'Confirmed') $confirmedCount++;
}

$pageTitle  = 'Đăng ký môn học';
$activePage = 'registration';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Action -->
<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
    <a href="schedule.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;
       background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:13px;font-weight:600;text-decoration:none;">
        🗓 Thời khóa biểu
    </a>
</div>

<?php if ($flash): ?>
<div style="padding:12px 16px;border-radius:10px;font-weight:600;font-size:13px;margin-bottom:12px;
            background:<?= $flash['type'] === 'success' ? '#d1fae5' : '#fee2e2' ?>;
            border:1px solid <?= $flash['type'] === 'success' ? '#6ee7b7' : '#fca5a5' ?>;
            color:<?= $flash['type'] === 'success' ? '#065f46' : '#b91c1c' ?>;">
    <?= $flash['type'] === 'success' ? '✅' : '⚠️' ?> <?= h($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (!$student): ?>
<div class="card" style="padding:32px;text-align:center;color:#64748b;">
    Không tìm thấy thông tin sinh viên.
</div>
<?php elseif (empty($student['major_id'])): ?>
<div class="card" style="padding:32px;text-align:center;color:#854d0e;background:#fef9c3;border:1px solid #fde047;">
    Tài khoản chưa được gán ngành học. Vui lòng liên hệ Phòng Đào tạo để được cập nhật trước khi đăng ký môn.
</div>
<?php elseif (!$periods): ?>
<div class="card" style="padding:32px;text-align:center;color:#64748b;">
    <div style="font-size:28px;margin-bottom:8px;">📭</div>
    Hiện chưa có đợt đăng ký môn học nào dành cho ngành của bạn.
</div>
<?php else: ?>

<!-- Period selector -->
<div class="card" style="padding:14px 18px;margin-bottom:12px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Đợt đăng ký</span>
        <?php foreach ($periods as $p):
            $pOpen = RegistrationService::periodIsOpen($p);
            $pSel  = (int)$p['period_id'] === $periodId;
        ?>
        <a href="registration.php?period_id=<?= (int)$p['period_id'] ?>"
           style="display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:20px;font-size:12px;
                  font-weight:600;text-decoration:none;border:1px solid <?= $pSel ? '#1e40af' : '#e2e8f0' ?>;
                  background:<?= $pSel ? '#1e40af' : '#fff' ?>;color:<?= $pSel ? '#fff' : '#334155' ?>;">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $pOpen ? ($pSel ? '#86efac' : '#10b981') : ($pSel ? '#fde68a' : '#f59e0b') ?>;"></span>
            <?= h($p['period_name']) ?>
            <span style="font-weight:400;opacity:.8;">· <?= $pOpen ? 'Đang mở' : 'Sắp mở' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Period detail -->
<?php if ($selectedPeriod):
    $pStart = strtotime($selectedPeriod['start_at']);
    $pEnd   = strtotime($selectedPeriod['end_at']);
?>
<div style="display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-radius:10px;font-size:13px;margin-bottom:12px;
            background:<?= $isOpen ? '#dbeafe' : '#fef9c3' ?>;
            border:1px solid <?= $isOpen ? '#93c5fd' : '#fde047' ?>;
            color:<?= $isOpen ? '#1e40af' : '#854d0e' ?>;">
    <span style="font-size:16px;line-height:1.2;"><?= $isOpen ? '📝' : '⏰' ?></span>
    <div>
        <div style="font-weight:700;"><?= h($selectedPeriod['semester_name'] . ' · ' . $selectedPeriod['academic_year']) ?></div>
        <div style="margin-top:2px;">
            <?= $isOpen ? 'Đang trong thời gian đăng ký.' : 'Chưa đến thời gian đăng ký. Đợt sẽ mở tự động theo lịch.' ?>
            Thời gian: <?= date('d/m/Y H:i', $pStart) ?> – <?= date('d/m/Y H:i', $pEnd) ?>.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- My registrations -->
<?php if ($semId): ?>
<div class="card" style="padding:18px 20px;margin-bottom:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <div style="font-size:15px;font-weight:700;color:#001634;">📚 Môn đã đăng ký (<?= count($mySectionRows) ?>)</div>
        <div style="font-size:12px;color:#64748b;">
            <strong style="color:#001634;"><?= $registeredCredits ?></strong> tín chỉ ·
            <strong style="color:#059669;"><?= $confirmedCount ?></strong> lớp đã xác nhận mở
        </div>
    </div>
    <?php if (empty($mySectionRows)): ?>
    <div style="padding:18px;text-align:center;color:#94a3b8;font-size:13px;background:#f8fafc;border-radius:8px;">
        Bạn chưa đăng ký môn nào trong học kỳ này.
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:10px;">
        <?php foreach ($mySectionRows as $my):
            $myMeta  = RegistrationService::statusMeta($my['section_status']);
            $mySlots = RegistrationService::sectionSlots($my);
            $mySched = $my['schedule'] ?: RegistrationService::scheduleText(
                array_column($mySlots, 'day'),
                $mySlots[0]['start'] ?? null,
                $mySlots[0]['end'] ?? null
            );
        ?>
        <div style="padding:12px 14px;border:1px solid #e2e8f0;border-left:3px solid <?= h($myMeta['fg']) ?>;border-radius:10px;background:#fff;">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                <div style="min-width:0;">
                    <div style="font-weight:700;font-size:13px;color:#001634;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= h($my['course_name']) ?>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:1px;">
                        <span style="font-family:monospace;"><?= h($my['course_code']) ?></span>
                        · <?= h($my['section_code']) ?> · <?= (int)$my['credits'] ?> TC
                    </div>
                </div>
                <span style="flex-shrink:0;font-size:10px;font-weight:700;padding:3px 8px;border-radius:12px;
                             background:<?= h($myMeta['bg']) ?>;color:<?= h($myMeta['fg']) ?>;">
                    <?= h($myMeta['label']) ?>
                </span>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:7px;line-height:1.5;">
                👨‍🏫 <?= h($my['lecturer_name']) ?><br/>
                🗓 <?= h($mySched ?: 'Chưa xếp lịch') ?><?= $my['room'] ? ' · 📍 ' . h($my['room']) : '' ?>
            </div>
            <?php if ($isOpen): ?>
            <form method="POST" style="margin:8px 0 0;text-align:right;"
                  onsubmit="return confirm('Hủy đăng ký <?= h(addslashes($my['course_code'])) ?>?')">
                <?= csrf_input() ?>
                <input type="hidden" name="action"     value="drop" />
                <input type="hidden" name="section_id" value="<?= (int)$my['section_id'] ?>" />
                <input type="hidden" name="period_id"  value="<?= $periodId ?>" />
                <button type="submit" style="background:#fee2e2;border:none;color:#b91c1c;border-radius:7px;
                        padding:4px 10px;font-size:11px;cursor:pointer;font-weight:600;">Hủy đăng ký</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="filter-ribbon" style="margin-bottom:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="period_id" value="<?= $periodId ?>" />
        <div style="position:relative;">
            <img src="<?= $imgBase ?>icon-search.svg" alt="" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:13px;opacity:.4;" />
            <input type="search" name="q" value="<?= h($searchQ) ?>"
                   placeholder="Tìm mã môn, tên môn, mã lớp..."
                   style="width:280px;height:36px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:0 12px 0 30px;font-size:13px;outline:none;" />
        </div>
        <select name="dept" style="height:36px;padding:0 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="">Tất cả bộ môn</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= (int)$d['department_id'] ?>" <?= $deptF == $d['department_id'] ? 'selected' : '' ?>>
                <?= h($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="height:36px;padding:0 16px;background:#102b50;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Tìm</button>
        <?php if ($searchQ || $deptF): ?>
        <a href="registration.php?period_id=<?= $periodId ?>" style="height:36px;padding:0 12px;display:flex;align-items:center;background:#f1f5f9;border-radius:8px;font-size:13px;text-decoration:none;color:#44474e;">✕ Xóa lọc</a>
        <?php endif; ?>
    </form>
</div>

<!-- Available sections -->
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">
        <strong><?= count($sections) ?></strong> lớp học phần thuộc chương trình đào tạo của bạn
        · Ngưỡng mở lớp = 50% sĩ số tối thiểu + 1
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mã môn / Tên môn học</th>
                    <th style="text-align:center;">TC</th>
                    <th>Mã lớp / Trạng thái</th>
                    <th>Giảng viên</th>
                    <th>Lịch học</th>
                    <th style="min-width:190px;">Sĩ số đăng ký</th>
                    <th style="text-align:center;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sections)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có lớp học phần nào phù hợp với ngành/chương trình của bạn trong đợt này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($sections as $sec):
                    $count      = (int)$sec['enrolled_count'];
                    $max        = max(1, (int)($sec['max_enrollment'] ?: ($sec['capacity'] ?: 64)));
                    $cap        = RegistrationService::capacityState($sec, $count);
                    $meta       = RegistrationService::statusMeta($sec['section_status']);
                    $isEnrolled = in_array((int)$sec['section_id'], $mySectionIds, true);
                    $sameCourse = !$isEnrolled && in_array((int)$sec['course_id'], $myCourseIds, true);
                    $completed  = in_array((int)$sec['course_id'], $completedCourseIds, true);
                    $isFull     = $count >= $max;
                    $blocked    = in_array($sec['section_status'], ['Cancelled', 'Insufficient'], true);

                    $conflictWith = '';
                    if (!$isEnrolled) {
                        foreach ($mySectionRows as $mine) {
                            if ((int)$mine['section_id'] === (int)$sec['section_id']) continue;
                            if (RegistrationService::sectionsOverlap($sec, $mine)) {
                                $conflictWith = $mine['section_code'];
                                break;
                            }
                        }
                    }

                    $prereqOk = true;
                    if (!empty($sec['prerequisite_course_id'])) {
                        $prereqOk = in_array((int)$sec['prerequisite_course_id'], $passedCourseIds, true);
                    }

                    $slots    = RegistrationService::sectionSlots($sec);
                    $schedTxt = $sec['schedule'] ?: RegistrationService::scheduleText(
                        array_column($slots, 'day'),
                        $slots[0]['start'] ?? null,
                        $slots[0]['end'] ?? null
                    );
                    $barColor = $cap['state'] === 'full' ? '#ef4444' : ($cap['state'] === 'open' ? '#10b981' : '#f59e0b');
                ?>
                <tr style="<?= $isEnrolled ? 'background:#f0fdf4;' : '' ?>">
                    <td>
                        <div style="font-weight:700;color:#001634;font-size:13px;"><?= h($sec['course_name']) ?></div>
                        <div style="margin-top:2px;">
                            <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:1px 5px;border-radius:3px;"><?= h($sec['course_code']) ?></span>
                            <?php if ($sec['department_name']): ?>
                            <span style="font-size:11px;color:#94a3b8;margin-left:4px;"><?= h($sec['department_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($sec['prereq_code']): ?>
                        <div style="font-size:11px;margin-top:3px;color:<?= $prereqOk ? '#059669' : '#b45309' ?>;">
                            <?= $prereqOk ? '✓' : '⚠' ?> Tiên quyết: <?= h($sec['prereq_code']) ?><?= $prereqOk ? ' (đã đạt)' : ' (chưa đạt)' ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$sec['credits'] ?></td>
                    <td>
                        <div style="font-family:monospace;font-size:12px;"><?= h($sec['section_code']) ?></div>
                        <span style="display:inline-block;margin-top:3px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;
                                     background:<?= h($meta['bg']) ?>;color:<?= h($meta['fg']) ?>;">
                            <?= h($meta['label']) ?>
                        </span>
                        <?php if ($sec['status_note']): ?>
                        <div style="font-size:10px;color:#94a3b8;margin-top:3px;max-width:150px;"><?= h($sec['status_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#44474e;"><?= h($sec['lecturer_name']) ?></td>
                    <td style="font-size:12px;color:#44474e;">
                        <?= h($schedTxt ?: 'Chưa xếp lịch') ?>
                        <?php if ($sec['room']): ?><br/><span style="font-size:11px;color:#94a3b8;">📍 <?= h($sec['room']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:11px;color:#44474e;line-height:1.4;"><?= h($cap['text']) ?></div>
                        <div style="height:6px;background:#f1f5f9;border-radius:4px;margin-top:5px;overflow:hidden;">
                            <div style="height:100%;width:<?= $cap['pct'] ?>%;background:<?= $barColor ?>;"></div>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:3px;">
                            Tối thiểu <?= (int)$sec['min_enrollment'] ?> · Tối đa <?= $max ?> · Ngưỡng mở <?= $cap['threshold'] ?>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($isEnrolled): ?>
                        <span style="color:#059669;font-weight:700;font-size:12px;">✅ Đã đăng ký</span>
                        <?php elseif ($completed): ?>
                        <span style="color:#64748b;font-size:12px;">Đã hoàn thành</span>
                        <?php elseif ($sameCourse): ?>
                        <span style="color:#94a3b8;font-size:12px;" title="Bạn đã đăng ký môn này ở lớp khác">— Trùng môn</span>
                        <?php elseif ($blocked): ?>
                        <span style="color:#b91c1c;font-size:12px;"><?= h($meta['label']) ?></span>
                        <?php elseif (!$prereqOk): ?>
                        <span style="color:#b45309;font-size:12px;" title="Chưa đạt môn tiên quyết">Thiếu tiên quyết</span>
                        <?php elseif ($conflictWith !== ''): ?>
                        <span style="color:#b91c1c;font-size:12px;" title="Trùng lịch với lớp <?= h($conflictWith) ?>">Trùng lịch</span>
                        <?php elseif ($isFull): ?>
                        <span style="color:#ef4444;font-size:12px;">Hết chỗ</span>
                        <?php elseif (!$isOpen): ?>
                        <span style="color:#94a3b8;font-size:11px;">Chưa mở đợt</span>
                        <?php else: ?>
                        <form method="POST" style="margin:0;"
                              onsubmit="return confirm('Đăng ký lớp <?= h(addslashes($sec['section_code'])) ?>?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action"     value="enroll" />
                            <input type="hidden" name="section_id" value="<?= (int)$sec['section_id'] ?>" />
                            <input type="hidden" name="period_id"  value="<?= $periodId ?>" />
                            <button type="submit"
                                    style="padding:5px 14px;background:#102b50;color:#fff;border:none;border-radius:7px;
                                           font-size:12px;font-weight:600;cursor:pointer;">
                                + Đăng ký
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

</div></main>
<script>
document.addEventListener('click', function(e) {
    var drop = document.getElementById('notifDrop');
    var bell = document.getElementById('notifBell');
    if (drop && bell && !bell.contains(e.target) && !drop.contains(e.target)) {
        drop.style.display = 'none';
    }
});
document.getElementById('notifBell')?.addEventListener('click', function(e) {
    var d = document.getElementById('notifDrop');
    if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none';
    e.stopPropagation();
});
</script>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
