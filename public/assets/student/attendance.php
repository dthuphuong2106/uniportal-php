<?php
/**
 * UniPortal – Điểm danh & Chuyên cần (Attendance)
 * Figma frame: ATT (3:2)
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';
require_once $root . '/src/src/services/AcademicAlertService.php';
require_once $root . '/src/src/services/SystemConfig.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$student_id = $_SESSION['student_id'] ?? '';
$full_name  = $_SESSION['full_name']  ?? '';

$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($currentSem['semester_id'] ?? 0);

$pdo = get_db();

// All semesters for filter
$allSems = $pdo->prepare('SELECT semester_id, semester_name, academic_year FROM Semester ORDER BY start_date DESC');
$allSems->execute();
$semesters = $allSems->fetchAll();

$selSemId = isset($_GET['sem']) ? (int)$_GET['sem'] : $semId;

// Attendance summary per course in the selected semester
function getAttSummary(string $sid, int $sem): array {
    if (!$sem) return [];
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT
            c.course_code, c.course_name, c.credits,
            cs.section_id, cs.section_code,
            COUNT(a.attendance_id)                                                AS total_sessions,
            SUM(a.status = \'Present\')                                           AS present_count,
            SUM(a.status = \'Absent\')                                            AS absent_count,
            SUM(a.status = \'Late\')                                              AS late_count,
            SUM(a.status = \'Excused\')                                           AS excused_count,
            ROUND(100 * SUM(a.status = \'Absent\')
                  / NULLIF(COUNT(a.attendance_id),0), 1)                          AS absent_pct
         FROM Enrollment e
         JOIN ClassSection cs ON cs.section_id = e.section_id
         JOIN Course c        ON c.course_id   = cs.course_id
         LEFT JOIN Attendance a ON a.enrollment_id = e.enrollment_id
         WHERE e.student_id = :sid AND cs.semester_id = :sem
         GROUP BY c.course_code, c.course_name, c.credits, cs.section_id, cs.section_code
         ORDER BY absent_pct DESC'
    );
    $stmt->execute([':sid' => $sid, ':sem' => $sem]);
    return $stmt->fetchAll();
}

// Recent attendance log
function getAttLog(string $sid, int $sem, int $limit = 20): array {
    if (!$sem) return [];
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT
            ats.session_date, ats.start_time, ats.end_time, ats.topic,
            c.course_code, c.course_name,
            cs.room,
            a.status, a.note
         FROM Attendance a
         JOIN Enrollment e    ON e.enrollment_id  = a.enrollment_id
         JOIN ClassSection cs ON cs.section_id    = e.section_id
         JOIN Course c        ON c.course_id      = cs.course_id
         JOIN AttendanceSession ats ON ats.session_id = a.session_id
         WHERE e.student_id = :sid AND cs.semester_id = :sem
         ORDER BY ats.session_date DESC, ats.start_time DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':sid', $sid);
    $stmt->bindValue(':sem', $sem, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

$attSummary = getAttSummary($student_id, $selSemId ?: $semId);
$attLog     = getAttLog($student_id, $selSemId ?: $semId);
$attRate    = GpaModel::getAttendanceRate($student_id, $selSemId ?: $semId);

// Aggregate stats
$totalSessions = array_sum(array_column($attSummary, 'total_sessions'));
$totalPresent  = array_sum(array_column($attSummary, 'present_count'));
$totalAbsent   = array_sum(array_column($attSummary, 'absent_count'));
$totalLate     = array_sum(array_column($attSummary, 'late_count'));

function h($v):string{ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$statusLabel = ['Present'=>'Có mặt','Absent'=>'Vắng','Late'=>'Đi muộn','Excused'=>'Vắng có phép'];
$statusClass = ['Present'=>'present','Absent'=>'absent','Late'=>'late','Excused'=>'excused'];

$pageTitle  = 'Điểm danh - Chuyên cần';
$activePage = 'attendance';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($student_id);
require_once __DIR__ . '/../partials/layout.php';
?>

<!-- Warning card if attendance is low -->
<?php
// Check per-course risks using the >20% rule
$banPct = SystemConfig::getFloat('attendance_ban_pct', 20.0);
$warnPct = SystemConfig::getFloat('attendance_warn_pct', 15.0);
$hasRisk = false; $hasProhibition = false;
foreach ($attSummary as $row) {
    $total = (int)$row['total_sessions'];
    $absent = (int)$row['absent_count'];
    if ($total > 0) {
        $pct = ($absent / $total) * 100;
        if ($pct > $banPct) $hasProhibition = true;
        elseif ($pct >= $warnPct) $hasRisk = true;
    }
}
?>
<?php if ($hasProhibition): ?>
<div class="warning-card warning-card--critical">
    <img class="warning-card__icon" src="<?= $imgBase ?>db-icon-alert-warn.svg" alt="Cảnh báo" />
    <div class="warning-card__body">
        <h3>🚫 CẢNG BÁO CẤM THI</h3>
        <p>Tỷ lệ vắng học của bạn đã vượt quá <strong><?= (int)$banPct ?>%</strong> ở một hoặc nhiều môn.
        Bạn có thể bị <strong>cấm thi</strong> theo quy chế đào tạo. Vui lòng liên hệ giảng viên hoặc Phòng Đào tạo ngay.</p>
    </div>
</div>
<?php elseif ($hasRisk): ?>
<div class="warning-card warning-card--warn">
    <img class="warning-card__icon" src="<?= $imgBase ?>db-icon-alert-info.svg" alt="Chú ý" />
    <div class="warning-card__body">
        <h3>⚠️ Chú ý chuyên cần</h3>
        <p>Tỷ lệ vắng học đang ở mức cảnh báo (≥<?= (int)$warnPct ?>%) ở một số môn.
        Vắng thêm có thể dẫn đến <strong>cấm thi (><?= (int)$banPct ?>%)</strong>. Hãy cải thiện tỷ lệ chuyên cần.</p>
    </div>
</div>
<?php endif; ?>

<!-- Stat summary row -->
<div class="stat-row">
    <div class="stat-mini">
        <div class="stat-mini__label">Tỷ lệ chuyên cần</div>
        <div class="stat-mini__value" style="color:<?= ($attRate ?? 100) < 80 ? '#ef4444' : '#102b50' ?>">
            <?= $attRate !== null ? number_format($attRate,1).'%' : '—' ?>
        </div>
        <div class="stat-mini__sub">Học kỳ hiện tại</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Tổng buổi học</div>
        <div class="stat-mini__value"><?= $totalSessions ?></div>
        <div class="stat-mini__sub">buổi</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Có mặt</div>
        <div class="stat-mini__value" style="color:#059669;"><?= $totalPresent ?></div>
        <div class="stat-mini__sub">buổi</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini__label">Vắng mặt</div>
        <div class="stat-mini__value" style="color:#ef4444;"><?= $totalAbsent ?></div>
        <div class="stat-mini__sub">buổi</div>
    </div>
</div>

<!-- Semester filter -->
<div class="filter-ribbon">
    <span style="font-size:14px;font-weight:600;color:#001634;">Tóm tắt theo môn học</span>
    <form method="GET" style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:13px;font-weight:600;color:#44474e;">Học kỳ:</label>
        <select name="sem" onchange="this.form.submit()">
            <?php foreach ($semesters as $s): ?>
            <option value="<?= h($s['semester_id']) ?>"
                <?= (int)$s['semester_id'] === ($selSemId ?: $semId) ? 'selected' : '' ?>>
                <?= h($s['semester_name']) ?> <?= h($s['academic_year']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- Per-course attendance summary table -->
<div class="card">
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Mã môn</th>
                    <th>Tên môn học</th>
                    <th>TC</th>
                    <th>Tổng buổi</th>
                    <th>Có mặt</th>
                    <th>Vắng</th>
                    <th>Đi muộn</th>
                    <th>Tỷ lệ</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attSummary)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">
                    Không có dữ liệu điểm danh cho học kỳ này.
                </td></tr>
                <?php else: ?>
                <?php foreach ($attSummary as $row):
                    $total  = (int)$row['total_sessions'];
                    $absent = (int)$row['absent_count'];
                    $pct    = $total > 0 ? ($absent / $total) * 100 : 0;
                    $absPct = $total > 0 ? round($pct, 1) : 0;
                    $banThreshold = (int)floor($total * $banPct / 100);
                    $remaining = max(0, $banThreshold - $absent);

                    // Determine risk level using strict > rule
                    if ($total === 0) { $riskClass='present'; $risk='Chưa có dữ liệu'; $color='#94a3b8'; }
                    elseif ($pct > $banPct) { $riskClass='absent'; $risk='🚫 Cấm thi'; $color='#ef4444'; }
                    elseif (abs($pct - $banPct) < 0.01) { $riskClass='absent'; $risk='⚠️ Ngưỡng cấm thi'; $color='#ef4444'; }
                    elseif ($pct >= $warnPct) { $riskClass='late'; $risk='⚠️ Chú ý'; $color='#d97706'; }
                    else { $riskClass='present'; $risk='✅ Tốt'; $color='#059669'; }
                ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= h($row['course_code']) ?></span></td>
                    <td style="font-weight:600;color:#001634;"><?= h($row['course_name']) ?></td>
                    <td style="text-align:center;"><?= h($row['credits']) ?></td>
                    <td style="text-align:center;"><?= $total ?></td>
                    <td style="text-align:center;color:#059669;font-weight:600;"><?= h($row['present_count']) ?></td>
                    <td style="text-align:center;color:#ef4444;font-weight:600;"><?= $absent ?></td>
                    <td style="text-align:center;color:#f59e0b;font-weight:600;"><?= h($row['late_count']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:#e5eeff;border-radius:9999px;overflow:hidden;">
                                <div style="height:100%;width:<?= min(100 - $absPct, 100) ?>%;background:<?= $color ?>;border-radius:9999px;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:<?= $color ?>;white-space:nowrap;">
                                <?= $total > 0 ? number_format(100 - $absPct, 1).'%' : '—' ?>
                            </span>
                        </div>
                        <?php if ($total > 0 && $pct >= $warnPct): ?>
                        <div style="font-size:10px;color:<?= $color ?>;margin-top:2px;">
                            Vắng <?= $absent ?>/<?= $total ?> (<?= $absPct ?>%) • Còn <?= $remaining ?> buổi vắng được
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="status-pill status-pill--<?= $riskClass ?>"><?= h($risk) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent attendance log -->
<div class="card" style="padding:24px;">
    <div style="font-size:18px;font-weight:700;color:#001634;margin-bottom:16px;">Lịch sử điểm danh gần đây</div>
    <?php if (empty($attLog)): ?>
    <div class="empty-state"><div class="empty-state__icon">📋</div><p>Chưa có dữ liệu điểm danh.</p></div>
    <?php else: ?>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Ngày</th><th>Giờ</th><th>Môn học</th><th>Phòng</th><th>Chủ đề</th><th>Trạng thái</th><th>Ghi chú</th></tr>
            </thead>
            <tbody>
                <?php foreach ($attLog as $log): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($log['session_date'])) ?></td>
                    <td style="white-space:nowrap;font-size:12px;"><?= substr($log['start_time'],0,5) ?> – <?= substr($log['end_time'],0,5) ?></td>
                    <td>
                        <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:1px 5px;border-radius:3px;margin-right:4px;"><?= h($log['course_code']) ?></span>
                        <?= h($log['course_name']) ?>
                    </td>
                    <td><?= h($log['room'] ?? '—') ?></td>
                    <td style="font-size:12px;color:#44474e;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($log['topic'] ?? '—') ?></td>
                    <td>
                        <span class="status-pill status-pill--<?= h($statusClass[$log['status']] ?? 'absent') ?>">
                            <?= h($statusLabel[$log['status']] ?? $log['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= h($log['note'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
