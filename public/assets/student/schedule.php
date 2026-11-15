<?php
/**
 * UniPortal – Thời khóa biểu (Student)
 *
 * Read-only view of the official timetable. Only confirmed sessions are
 * shown as scheduled; cancelled sessions stay visible with a "Đã hủy"
 * marker and approved make-up sessions appear as new entries. Pending
 * lecturer requests are never displayed here.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/TeachingSchedule.php';
require_once $root . '/src/src/services/RegistrationService.php';

require_student();
if (isset($_GET['logout'])) { auth_logout(); header('Location: /UniPortal/public/index.php'); exit; }

$studentId = $_SESSION['student_id'] ?? '';
$pdo       = get_db();

$currentSem = StudentModel::getCurrentSemester();
$semId      = (int)($_GET['sem'] ?? ($currentSem['semester_id'] ?? 0));

$semesters = $pdo->query(
    'SELECT semester_id, semester_name, academic_year, status
     FROM Semester ORDER BY start_date DESC'
)->fetchAll();

$selSem = null;
foreach ($semesters as $sem) { if ((int)$sem['semester_id'] === $semId) { $selSem = $sem; break; } }

$sessions = $semId ? TeachingSchedule::getStudentTimetable($studentId, $semId) : [];
$weekly   = $semId ? TeachingSchedule::getStudentWeeklyPattern($studentId, $semId) : [];

$today = date('Y-m-d');
$view  = $_GET['view'] ?? 'upcoming';
if (!in_array($view, ['upcoming', 'past', 'all'], true)) $view = 'upcoming';

$visible = array_values(array_filter($sessions, function ($s) use ($view, $today) {
    if ($view === 'all') return true;
    if ($view === 'past') return $s['session_date'] < $today;
    return $s['session_date'] >= $today;
}));

$upcomingCount = count(array_filter($sessions, fn($s) => $s['session_date'] >= $today && $s['status'] !== 'Cancelled'));
$cancelledCount = count(array_filter($sessions, fn($s) => $s['status'] === 'Cancelled'));
$makeupCount = count(array_filter($sessions, fn($s) => $s['session_type'] === 'Makeup' && $s['status'] !== 'Cancelled'));

$nextSession = null;
foreach ($sessions as $s) {
    if ($s['session_date'] >= $today && $s['status'] !== 'Cancelled') { $nextSession = $s; break; }
}

$byDay = [];
foreach ($weekly as $w) { $byDay[(int)$w['day_of_week']][] = $w; }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$dayNames = ['1' => 'Thứ 2', '2' => 'Thứ 3', '3' => 'Thứ 4', '4' => 'Thứ 5', '5' => 'Thứ 6', '6' => 'Thứ 7', '7' => 'Chủ nhật'];
$dayShort = ['Mon' => 'T2', 'Tue' => 'T3', 'Wed' => 'T4', 'Thu' => 'T5', 'Fri' => 'T6', 'Sat' => 'T7', 'Sun' => 'CN'];

$pageTitle  = 'Thời khóa biểu';
$activePage = 'schedule';
$imgBase    = '../../assets/img/';
$assetBase  = '../../assets/';
$warningCnt = StudentModel::getUnreadWarningCount($studentId);
require_once __DIR__ . '/../partials/layout.php';
?>

<div style="display:flex;justify-content:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
    <a href="registration.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;
       background:#fff;border:1px solid #e2e8f0;color:#334155;font-size:13px;font-weight:600;text-decoration:none;">
        📝 Đăng ký môn học
    </a>
</div>

<?php if ($cancelledCount > 0): ?>
<div class="card" style="padding:14px 20px;border-left:4px solid #ef4444;background:#fff5f5;">
    <div style="font-size:13px;color:#b91c1c;font-weight:600;">
        🚫 Có <?= $cancelledCount ?> buổi học đã được hủy trong học kỳ này. Các buổi đã hủy vẫn hiển thị để bạn đối chiếu.
    </div>
</div>
<?php endif; ?>

<!-- Semester + view filters -->
<div class="card" style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label style="font-size:13px;font-weight:600;color:#64748b;">Học kỳ:</label>
        <select name="sem" onchange="this.form.submit()"
                style="height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;outline:none;">
            <?php foreach ($semesters as $sem): ?>
            <option value="<?= (int)$sem['semester_id'] ?>" <?= (int)$sem['semester_id'] === $semId ? 'selected' : '' ?>>
                <?= h($sem['semester_name'] . ' – ' . $sem['academic_year']) ?><?= $sem['status'] === 'Active' ? ' (hiện tại)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="view" value="<?= h($view) ?>" />
    </form>
    <div style="display:flex;gap:6px;">
        <?php foreach (['upcoming' => 'Sắp tới', 'past' => 'Đã qua', 'all' => 'Tất cả'] as $k => $lbl): ?>
        <a href="?sem=<?= $semId ?>&view=<?= $k ?>"
           class="pill <?= $view === $k ? 'pill--blue' : '' ?>"
           style="<?= $view === $k ? '' : 'background:#f1f5f9;color:#64748b;' ?>text-decoration:none;">
            <?= $lbl ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;">
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Tổng buổi trong học kỳ</div>
        <div style="font-size:28px;font-weight:800;color:#001634;margin-top:4px;"><?= count($sessions) ?></div>
        <div style="font-size:11px;color:#94a3b8;">buổi đã được xếp lịch</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Buổi sắp tới</div>
        <div style="font-size:28px;font-weight:800;color:#1e40af;margin-top:4px;"><?= $upcomingCount ?></div>
        <div style="font-size:11px;color:#94a3b8;">chưa diễn ra</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Buổi học bù</div>
        <div style="font-size:28px;font-weight:800;color:#4338ca;margin-top:4px;"><?= $makeupCount ?></div>
        <div style="font-size:11px;color:#94a3b8;">đã được duyệt</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Buổi đã hủy</div>
        <div style="font-size:28px;font-weight:800;color:#b91c1c;margin-top:4px;"><?= $cancelledCount ?></div>
        <div style="font-size:11px;color:#94a3b8;">theo thông báo chính thức</div>
    </div>
</div>

<?php if ($nextSession): ?>
<div class="card" style="padding:16px 20px;display:flex;align-items:center;gap:14px;border-left:4px solid #3b82f6;">
    <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;">Buổi học kế tiếp</div>
    <div style="font-size:13px;color:#001634;font-weight:700;">
        <?= h($nextSession['course_code']) ?> – <?= h($nextSession['course_name']) ?>
    </div>
    <div style="font-size:12px;color:#64748b;">
        <?= $dayShort[date('D', strtotime($nextSession['session_date']))] ?? '' ?>,
        <?= date('d/m/Y', strtotime($nextSession['session_date'])) ?> •
        <?= substr($nextSession['start_time'], 0, 5) ?>–<?= substr($nextSession['end_time'], 0, 5) ?>
        <?= $nextSession['room'] ? ' • 📍 ' . h($nextSession['room']) : '' ?>
    </div>
</div>
<?php endif; ?>

<!-- Weekly pattern -->
<div class="card">
    <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:15px;font-weight:700;color:#001634;">🗓️ Lịch tuần chính thức</div>
        <div style="font-size:12px;color:#64748b;"><?= count($weekly) ?> lớp trong học kỳ</div>
    </div>
    <?php if (empty($weekly)): ?>
    <div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px;">
        Chưa có lịch tuần chính thức cho các lớp đã đăng ký.
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px;padding:16px;overflow-x:auto;">
        <?php for ($d = 1; $d <= 7; $d++): ?>
        <div style="background:#f8fafc;border-radius:10px;padding:10px;min-height:120px;">
            <div style="font-size:12px;font-weight:800;color:#001634;margin-bottom:8px;<?= $d === (int)date('N') ? 'color:#1e40af;' : '' ?>">
                <?= $dayNames[$d] ?><?= $d === (int)date('N') ? ' •' : '' ?>
            </div>
            <?php if (empty($byDay[$d])): ?>
            <div style="font-size:11px;color:#cbd5e1;">Không có lớp</div>
            <?php else: foreach ($byDay[$d] as $w): ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-left:3px solid #3b82f6;border-radius:8px;padding:8px;margin-bottom:6px;">
                <div style="font-size:12px;font-weight:700;color:#001634;"><?= h($w['course_code']) ?></div>
                <div style="font-size:10px;color:#64748b;"><?= h($w['section_code']) ?></div>
                <div style="font-size:11px;color:#1e40af;font-weight:600;margin-top:3px;">
                    <?= substr($w['start_time'], 0, 5) ?>–<?= substr($w['end_time'], 0, 5) ?>
                </div>
                <div style="font-size:10px;color:#94a3b8;">
                    <?= $w['room'] ? '📍 ' . h($w['room']) : '' ?>
                </div>
                <div style="font-size:10px;color:#94a3b8;"><?= h($w['lecturer_name']) ?></div>
                <?php $wMeta = RegistrationService::statusMeta((string)($w['section_status'] ?? 'Confirmed')); ?>
                <span style="display:inline-block;margin-top:4px;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;
                             background:<?= h($wMeta['bg']) ?>;color:<?= h($wMeta['fg']) ?>;">
                    <?= h($wMeta['label']) ?>
                </span>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endfor; ?>
    </div>
    <div style="padding:0 20px 14px;font-size:11px;color:#94a3b8;">
        Lịch tuần phản ánh buổi học theo lịch chính thức. Buổi đã hủy hoặc học bù được ghi rõ trong danh sách bên dưới.
    </div>
    <?php endif; ?>
</div>

<!-- Session list -->
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="font-size:15px;font-weight:700;color:#001634;">
            📅 Danh sách buổi học
            <span style="font-size:12px;color:#64748b;font-weight:400;">
                (<?= $view === 'upcoming' ? 'sắp tới' : ($view === 'past' ? 'đã qua' : 'tất cả') ?> – <?= count($visible) ?> buổi)
            </span>
        </div>
        <div style="font-size:11px;color:#94a3b8;">Chỉ hiển thị lịch đã được Nhà trường xác nhận</div>
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>NGÀY</th>
                    <th>GIỜ</th>
                    <th>HỌC PHẦN</th>
                    <th>LỚP HỌC PHẦN</th>
                    <th>PHÒNG</th>
                    <th>GIẢNG VIÊN</th>
                    <th>TRẠNG THÁI</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($visible)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8;font-style:italic;">
                    Không có buổi học nào trong mục này.
                </td></tr>
            <?php else: foreach ($visible as $s):
                $isCancelled = $s['status'] === 'Cancelled';
                $isMakeup    = $s['session_type'] === 'Makeup';
                $secStatus   = (string)($s['section_status'] ?? 'Confirmed');
                $isPending   = !$isCancelled && ($secStatus === 'Pending' || $s['status'] === 'Pending');
                $isInsufficient = !$isCancelled && $secStatus === 'Insufficient';
                $secMeta     = RegistrationService::statusMeta($secStatus);
            ?>
                <tr style="<?= $isCancelled ? 'background:#fff5f5;' : ($isPending ? 'background:#fffbeb;' : ($s['session_date'] === $today ? 'background:#eff6ff;' : '')) ?>">
                    <td style="white-space:nowrap;">
                        <div style="font-weight:700;color:<?= $isCancelled ? '#b91c1c' : '#001634' ?>;<?= $isCancelled ? 'text-decoration:line-through;' : '' ?>">
                            <?= date('d/m/Y', strtotime($s['session_date'])) ?>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;">
                            <?= $dayShort[date('D', strtotime($s['session_date']))] ?? '' ?><?= $s['session_date'] === $today ? ' • HÔM NAY' : '' ?>
                        </div>
                    </td>
                    <td style="white-space:nowrap;font-size:12px;color:#44474e;">
                        <?= substr($s['start_time'], 0, 5) ?>–<?= substr($s['end_time'], 0, 5) ?>
                    </td>
                    <td>
                        <div style="font-weight:600;color:#001634;<?= $isCancelled ? 'text-decoration:line-through;' : '' ?>">
                            <?= h($s['course_code']) ?> – <?= h($s['course_name']) ?>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;"><?= (int)$s['credits'] ?> tín chỉ</div>
                    </td>
                    <td style="font-size:12px;color:#64748b;">
                        <?= h($s['section_code']) ?>
                        <?php if ($secStatus !== 'Confirmed' && !$isCancelled): ?>
                        <div style="font-size:10px;color:<?= h($secMeta['fg']) ?>;margin-top:2px;"><?= h($secMeta['label']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= $s['room'] ? h($s['room']) : '—' ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= h($s['lecturer_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($isCancelled): ?>
                        <span class="status-pill status-pill--absent">Đã hủy</span>
                        <?php elseif ($isInsufficient): ?>
                        <span class="status-pill status-pill--absent">Không đủ sĩ số</span>
                        <?php elseif ($isPending): ?>
                        <span class="status-pill status-pill--late">Chờ mở lớp</span>
                        <?php elseif ($isMakeup): ?>
                        <span class="status-pill status-pill--enrolled">Học bù</span>
                        <?php elseif ($s['session_date'] === $today): ?>
                        <span class="status-pill status-pill--late">Hôm nay</span>
                        <?php else: ?>
                        <span class="status-pill status-pill--active">Theo lịch</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($view !== 'past' && $cancelledCount > 0): ?>
<div class="card" style="padding:14px 20px;">
    <div style="font-size:12px;color:#64748b;">
        ℹ️ Buổi học bị hủy không được tính vào điểm danh. Nếu Nhà trường duyệt buổi học bù, buổi mới sẽ xuất hiện trong danh sách với nhãn <strong>Học bù</strong>.
    </div>
</div>
<?php endif; ?>

</div></main>
<script src="../../assets/js/dashboard.js"></script>
</body></html>
