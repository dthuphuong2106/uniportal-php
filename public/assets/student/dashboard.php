<?php
/**
 * UniPortal – Student Dashboard
 * Figma source: node 1:2 (file 54fjXxHFXv2NH83qL9KXly)
 *
 * Path from web root: /UniPortal/public/assets/student/dashboard.php
 * This file is 3 levels deep from public/, so relative asset paths use ../../
 */

// ── Bootstrap ────────────────────────────────────────────────
$root = dirname(__DIR__, 3); // → /UniPortal
require_once $root . '/src/src/helpers/auth.php';
require_once $root . '/src/src/models/Student.php';
require_once $root . '/src/src/models/Gpa.php';

require_student(); // redirects to login if not a Student

// ── Pull session values ───────────────────────────────────────
$student_id      = $_SESSION['student_id']      ?? '';
$full_name       = $_SESSION['full_name']        ?? 'Sinh viên';

// ── Handle logout ─────────────────────────────────────────────
if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: /UniPortal/public/index.php');
    exit;
}

// ── Load data from DB ─────────────────────────────────────────
$currentSemester   = StudentModel::getCurrentSemester();
$semId             = $currentSemester['semester_id'] ?? 0;

$cumData           = GpaModel::getCumulative($student_id);
$cumulativeGpa     = $cumData['cumulative_gpa'];
$earnedCredits     = $cumData['earned_credits'];

$semesterGpa       = $semId ? GpaModel::getSemesterGpa($student_id, $semId) : null;

$gpaHistory        = GpaModel::getGpaHistory($student_id);

$warningCount      = StudentModel::getUnreadWarningCount($student_id);

// ── Derived display values ────────────────────────────────────
$totalCredits      = 130; // from Curriculum; seed shows 130 for all programs
$creditPct         = $totalCredits > 0 ? round($earnedCredits / $totalCredits * 100, 1) : 0;
$remainingCredits  = $totalCredits - $earnedCredits;

$classification    = GpaModel::getClassification($cumulativeGpa);

// GPA progress toward "Giỏi" target (3.20 / 4.00 scale = 80%)
$cumGpaPct         = $cumulativeGpa !== null ? round(($cumulativeGpa / 4.0) * 100, 1) : 0;
$semGpaPct         = $semesterGpa   !== null ? round(($semesterGpa   / 4.0) * 100, 1) : 0;

// Build chart data for JS  (per-semester GPA history)
$chartLabels       = [];
$chartSemGpa       = [];
$chartCumGpa       = [];
$runningCredits    = 0;
$runningPoints     = 0;
foreach ($gpaHistory as $row) {
    // Short label e.g. "HK1 (23-24)"
    $yr    = $row['academic_year'] ?? '';
    $short = preg_replace('/^Học kỳ\s*/i', 'HK', $row['semester_name'] ?? '') . ' (' . $yr . ')';
    $chartLabels[]  = $short;
    $chartSemGpa[]  = (float)($row['semester_gpa'] ?? 0);
    $runningCredits += (int)($row['credits_this_sem'] ?? 0);
    $runningPoints  += (float)($row['semester_gpa'] ?? 0) * (int)($row['credits_this_sem'] ?? 1);
    $cumAtPoint      = $runningCredits > 0 ? round($runningPoints / $runningCredits, 2) : 0;
    $chartCumGpa[]   = $cumAtPoint;
}

// Escape for JS
$jsLabels   = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
$jsSemGpa   = json_encode($chartSemGpa);
$jsCumGpa   = json_encode($chartCumGpa);

// Helpers for safe HTML output
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gpa_fmt(mixed $v): string { return $v !== null ? number_format((float)$v, 2) : '—'; }

// Asset base path (relative from this file's location → ../../assets/)
$IMG = '../../assets/img/';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Tổng quan – UniPortal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="../../assets/css/dashboard.css" />
    <link rel="stylesheet" href="../../assets/css/system.css" />
</head>
<body>
<div class="db-layout">

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
     ═══════════════════════════════════════════════════════════ -->
<aside class="db-sidebar" id="sidebar" aria-label="Navigation">

    <!-- Logo -->
    <div>
        <div class="db-sidebar__logo">
            <img src="<?= $IMG ?>db-logo-vnuis-sidebar.png" alt="VNU-IS" />
        </div>

        <!-- Nav links -->
        <nav class="db-sidebar__nav" aria-label="Student menu">
            <a href="dashboard.php" class="active" aria-current="page">
                <img src="<?= $IMG ?>db-nav-dashboard.svg" alt="" />
                Tổng quan
            </a>
            <a href="gpa.php">
                <img src="<?= $IMG ?>db-nav-grades.svg" alt="" />
                Kết quả học tập
            </a>
            <a href="attendance.php">
                <img src="<?= $IMG ?>db-nav-attendance.svg" alt="" />
                Điểm danh - Chuyên cần
            </a>
            <a href="registration.php">
                <img src="<?= $IMG ?>db-nav-grades.svg" alt="" />
                Đăng ký môn học
            </a>
            <a href="assignments.php">
                <img src="<?= $IMG ?>db-nav-attendance.svg" alt="" />
                Bài tập &amp; Nộp bài
            </a>
            <a href="tuition.php">
                <img src="<?= $IMG ?>db-icon-credits.svg" alt="" />
                Học phí &amp; Thanh toán
            </a>
            <a href="gpa_forecast.php">
                <img src="<?= $IMG ?>db-nav-gpa-plan.svg" alt="" />
                Kế hoạch &amp; Dự báo GPA (AI)
            </a>
            <a href="alerts.php">
                <img src="<?= $IMG ?>db-nav-alerts.svg" alt="" />
                Cảnh báo học tập
            </a>
            <a href="schedule.php">
                <img src="<?= $IMG ?>db-nav-schedule.svg" alt="" />
                Thời khóa biểu
            </a>
            <a href="profile.php">
                <img src="<?= $IMG ?>db-nav-profile.svg" alt="" />
                Hồ sơ cá nhân
            </a>
        </nav>
    </div>

    <!-- Bottom profile + logout -->
    <div class="db-sidebar__footer">
        <div class="db-sidebar__profile-card">
            <div class="profile-info">
                <img class="avatar"
                     src="<?= $IMG ?>db-profile.png"
                     alt="<?= h($full_name) ?>" />
                <div>
                    <div class="name"><?= h($full_name) ?></div>
                    <div class="student-id">MSSV: <?= h($student_id) ?></div>
                </div>
            </div>
            <a href="dashboard.php?logout=1"
               class="logout-btn"
               title="Đăng xuất"
               aria-label="Đăng xuất">
                <img src="<?= $IMG ?>db-icon-logout.svg" alt="Đăng xuất" />
            </a>
        </div>
    </div>

</aside>

<!-- ═══════════════════════════════════════════════════════════
     HEADER
     ═══════════════════════════════════════════════════════════ -->
<header class="db-header" role="banner">
    <div style="display:flex;align-items:center;gap:12px;">
        <!-- Hamburger (mobile) -->
        <button class="db-hamburger" id="hamburger" aria-label="Mở menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <!-- Search -->
        <div class="db-header__search">
            <img class="search-icon"
                 src="<?= $IMG ?>icon-search.svg"
                 alt="" aria-hidden="true" />
            <input type="search"
                   placeholder="Tìm kiếm môn học, thông báo..."
                   aria-label="Tìm kiếm" />
        </div>
    </div>

    <div class="db-header__right">
        <!-- Bell with warning count badge -->
        <button class="db-header__bell"
                aria-label="Thông báo<?= $warningCount > 0 ? ' (' . $warningCount . ' chưa đọc)' : '' ?>">
            <img src="<?= $IMG ?>db-icon-bell.svg" alt="" />
            <?php if ($warningCount > 0): ?>
            <span class="badge" aria-hidden="true">
                <?= min($warningCount, 9) ?><?= $warningCount > 9 ? '+' : '' ?>
            </span>
            <?php endif; ?>
        </button>

        <!-- Profile dropdown trigger -->
        <button class="db-header__profile" id="profileToggle" aria-haspopup="true" aria-expanded="false">
            <img class="avatar"
                 src="<?= $IMG ?>db-profile.png"
                 alt="<?= h($full_name) ?>" />
            <div>
                <div class="name"><?= h($full_name) ?></div>
                <div class="student-id">MSSV: <?= h($student_id) ?></div>
            </div>
            <img class="caret"
                 src="<?= $IMG ?>db-icon-caret.svg"
                 alt="" aria-hidden="true" />
        </button>
    </div>
</header>

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT
     ═══════════════════════════════════════════════════════════ -->
<main class="db-main" id="main-content">
<div class="db-content">

    <!-- ═══════════════════════════════
         A. ACADEMIC KPI CARDS
         ═══════════════════════════════ -->
    <div class="kpi-row" role="region" aria-label="Chỉ số học tập"
         style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">

        <!-- Card 1: Cumulative GPA -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">GPA Tích lũy</span>
                <span class="kpi-card__badge kpi-card__badge--green">
                    <?= h($classification) ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= gpa_fmt($cumulativeGpa) ?></span>
                <span class="kpi-card__unit">/ 4.00</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $cumGpaPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--green"
                         style="width:<?= $cumGpaPct ?>%"></div>
                </div>
                <div class="kpi-card__meta">
                    <img src="<?= $IMG ?>db-icon-gpa-card.svg" alt="" />
                    Mục tiêu Giỏi: 3.20 / 4.00
                </div>
            </div>
        </div>

        <!-- Card 2: Current semester GPA -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">GPA Kỳ hiện tại</span>
                <span class="kpi-card__badge kpi-card__badge--green">
                    <?= $semesterGpa !== null ? GpaModel::getClassification($semesterGpa) : '—' ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= gpa_fmt($semesterGpa) ?></span>
                <span class="kpi-card__unit">/ 4.00</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $semGpaPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--green"
                         style="width:<?= $semGpaPct ?>%"></div>
                </div>
                <div class="kpi-card__meta">
                    <img src="<?= $IMG ?>db-icon-semester-gpa.svg" alt="" />
                    <?= $currentSemester ? h($currentSemester['semester_name']) : 'Học kỳ hiện tại' ?>
                </div>
            </div>
        </div>

        <!-- Card 3: Accumulated credits -->
        <div class="card kpi-card">
            <div class="kpi-card__header">
                <span class="kpi-card__label">Tín chỉ tích lũy</span>
                <span class="kpi-card__badge kpi-card__badge--blue">
                    <?= $remainingCredits > 0 ? 'Còn ' . $remainingCredits . ' TC' : 'Đủ điều kiện' ?>
                </span>
            </div>
            <div class="kpi-card__value">
                <span class="kpi-card__num"><?= h($earnedCredits) ?></span>
                <span class="kpi-card__unit">/ <?= $totalCredits ?> tín chỉ</span>
            </div>
            <div class="kpi-card__footer">
                <div class="progress-bar" role="progressbar"
                     aria-valuenow="<?= $creditPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar__fill progress-bar__fill--navy"
                         style="width:<?= $creditPct ?>%"></div>
                </div>
                <div class="kpi-card__meta">
                    <img src="<?= $IMG ?>db-icon-credits.svg" alt="" />
                    Còn <?= h($remainingCredits) ?> tín chỉ để tốt nghiệp
                </div>
            </div>
        </div>

    </div><!-- /.kpi-row -->

    <!-- ═══════════════════════════════
         C. GPA CHART + DEGREE PROGRESS
         ═══════════════════════════════ -->
    <div class="row-2" role="region" aria-label="Biểu đồ GPA và tiến độ học tập">

        <!-- GPA Trend Chart -->
        <div class="card gpa-chart-card">
            <div class="gpa-chart-card__header">
                <div>
                    <div class="gpa-chart-card__title">Diễn biến GPA qua các học kỳ</div>
                    <div class="gpa-chart-card__sub">
                        Biểu đồ đối chiếu điểm trung bình học kỳ và tích lũy toàn khóa
                    </div>
                </div>
                <div class="chart-tabs" role="group" aria-label="Chọn loại GPA">
                    <button class="chart-tab active" data-chart="semester" id="tabSemGpa">
                        GPA học kỳ
                    </button>
                    <button class="chart-tab" data-chart="cumulative" id="tabCumGpa">
                        GPA tích lũy
                    </button>
                </div>
            </div>
            <div class="chart-canvas-wrap">
                <?php if (empty($gpaHistory)): ?>
                <p class="no-data" style="padding:48px 0;text-align:center;">
                    Chưa có dữ liệu GPA từ các học kỳ trước.
                </p>
                <?php else: ?>
                <canvas id="gpaChartCanvas" role="img"
                        aria-label="Biểu đồ GPA qua các học kỳ"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Degree Progress -->
        <div class="card degree-card" role="region" aria-label="Tiến độ tốt nghiệp">
            <div>
                <div class="degree-card__title">Tiến độ học tập</div>
                <div class="degree-card__sub">Tín chỉ tích lũy / tổng tín chỉ cần đạt</div>
            </div>
            <div class="degree-card__donut">
                <div class="donut-wrap">
                    <canvas id="degreeDonutCanvas" role="img"
                            aria-label="Biểu đồ tiến độ tín chỉ"></canvas>
                    <div class="donut-center">
                        <div class="donut-center__num"><?= $earnedCredits ?></div>
                        <div class="donut-center__unit">/ <?= $totalCredits ?> TC</div>
                    </div>
                </div>
                <div class="degree-legend">
                    <div class="degree-legend__item">
                        <span class="degree-legend__left">
                            <span class="degree-legend__dot" style="background:#102b50;"></span>
                            Đã tích lũy
                        </span>
                        <span class="degree-legend__right"><?= $earnedCredits ?> TC</span>
                    </div>
                    <div class="degree-legend__item">
                        <span class="degree-legend__left">
                            <span class="degree-legend__dot" style="background:#e5eeff;"></span>
                            Còn lại
                        </span>
                        <span class="degree-legend__right"><?= $remainingCredits ?> TC</span>
                    </div>
                    <div class="degree-legend__item" style="margin-top:4px;border-top:1px solid #f1f5f9;padding-top:8px;">
                        <span class="degree-legend__left">Phân loại dự kiến</span>
                        <span class="degree-legend__right"><?= h($classification) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.row-2 -->

</div><!-- /.db-content -->
</main>

<!-- ═══════════════════════════════════════════════════════════
     SCRIPTS
     ═══════════════════════════════════════════════════════════ -->
<!-- Chart.js from CDN (no install required) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"
        integrity="sha256-CN+kcwVxsjgQw0/DnFEBRh7K/KVsP5LK9IUFCcsVjzA="
        crossorigin="anonymous"></script>

<!-- PHP → JS data bridge -->
<script>
window.UNIPORTAL = {
    chartLabels : <?= $jsLabels ?>,
    chartSemGpa : <?= $jsSemGpa ?>,
    chartCumGpa : <?= $jsCumGpa ?>,
    earnedCredits : <?= (int)$earnedCredits ?>,
    totalCredits  : <?= (int)$totalCredits  ?>,
    imgBase       : '../../assets/img/'
};
</script>

<script src="../../assets/js/dashboard.js"></script>
</body>
</html>
