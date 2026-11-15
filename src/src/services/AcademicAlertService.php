<?php
/**
 * UniPortal – Academic Alert Service
 *
 * Core Feature 2: Automatic Academic Warning & Attendance Monitoring
 *
 * Key rules:
 *   - Attendance is checked PER COURSE (per section), not aggregated.
 *   - Prohibition rule: absent/total > 20% (strictly greater than, not >=).
 *   - Three warning levels:
 *       Level 1 – Attendance Attention (absent% >= warn_pct, default 15%)
 *       Level 2 – Critical Attendance Warning (absent% >= ban_pct, default 20%)
 *       Level 3 – Examination Prohibition Risk (absent% > ban_pct, i.e. >20%)
 *   - Warnings are auto-created and auto-resolved per course.
 *   - Duplicate warnings are prevented (one active warning per student+section).
 *   - Students receive notifications immediately.
 *   - All actions are audit-logged.
 *
 * recalcGpa($student_id) → GPA + per-course attendance check + warnings.
 * checkAttendanceForSection($student_id, $section_id) → per-course check.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SystemConfig.php';
require_once __DIR__ . '/NotificationService.php';

class AcademicAlertService {

    // ── Main entry: recalc GPA + check all attendance ─────────
    public static function recalcGpa(string $student_id): array {
        $pdo = get_db();

        // 1. Compute cumulative GPA
        $stmt = $pdo->prepare(
            'SELECT
                ROUND(SUM(gs.grade_point * c.credits) / NULLIF(SUM(c.credits), 0), 2) AS cum_gpa,
                SUM(c.credits) AS earned_credits
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id  = e.section_id
             JOIN Course        c  ON c.course_id    = cs.course_id
             JOIN Assessment    a  ON a.section_id   = cs.section_id
             JOIN Grade         g  ON g.enrollment_id = e.enrollment_id
                                  AND g.assessment_id  = a.assessment_id
             JOIN GradeScale    gs ON (g.score / 10) BETWEEN gs.min_score AND gs.max_score
             WHERE e.student_id = :sid
               AND e.status     = \'Completed\''
        );
        $stmt->execute([':sid' => $student_id]);
        $row   = $stmt->fetch();
        $gpa   = $row['cum_gpa']      !== null ? (float)$row['cum_gpa']  : null;
        $cred  = (int)($row['earned_credits'] ?? 0);

        $warningsCreated = 0;

        // 2. GPA-based warnings (university-wide, not per-course)
        if ($gpa !== null) {
            $warningsCreated += self::checkAndCreateWarning(
                $pdo, $student_id, 'LowGPA',
                SystemConfig::isLowGpa($gpa),
                sprintf(
                    'GPA tích lũy hiện tại là %.2f – dưới ngưỡng cảnh báo %.2f. Cần cải thiện kết quả học tập.',
                    $gpa,
                    SystemConfig::getFloat('gpa_warning_threshold', 2.00)
                )
            );

            $warningsCreated += self::checkAndCreateWarning(
                $pdo, $student_id, 'GraduationRisk',
                $gpa < SystemConfig::getFloat('gpa_suspension_threshold', 1.50),
                sprintf(
                    'GPA tích lũy %.2f thấp nguy hiểm. Có nguy cơ đình chỉ học hoặc không đủ điều kiện tốt nghiệp.',
                    $gpa
                )
            );
        }

        // 3. Per-course attendance check (Active semester)
        $warningsCreated += self::checkAllAttendance($student_id);

        return [
            'gpa'              => $gpa,
            'earned_credits'   => $cred,
            'warnings_created' => $warningsCreated,
        ];
    }

    // ── Check attendance for ALL enrolled sections ─────────────
    // Checks ALL semesters where the student has Enrolled/Completed status
    // AND has actual attendance records — not just the Active semester.
    public static function checkAllAttendance(string $student_id): int {
        $pdo = get_db();

        // Get all sections where student is enrolled AND attendance exists
        $stmt = $pdo->prepare(
            'SELECT DISTINCT e.enrollment_id, e.section_id,
                    c.course_code, c.course_name, c.credits
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c        ON c.course_id   = cs.course_id
             WHERE e.student_id = :sid
               AND e.status IN (\'Enrolled\', \'Completed\')
               AND EXISTS (
                   SELECT 1 FROM Attendance a
                   JOIN AttendanceSession ats ON ats.session_id = a.session_id
                   WHERE a.enrollment_id = e.enrollment_id
                     AND ats.section_id  = e.section_id
               )'
        );
        $stmt->execute([':sid' => $student_id]);
        $sections = $stmt->fetchAll();

        $created = 0;
        foreach ($sections as $sec) {
            $created += self::checkAttendanceForSection(
                $student_id,
                (int)$sec['section_id'],
                (int)$sec['enrollment_id'],
                $sec['course_code'],
                $sec['course_name'],
                (int)$sec['credits']
            );
        }
        return $created;
    }

    // ── Check attendance for a single course/section ─────────
    // Implements the strict >20% rule per course.
    public static function checkAttendanceForSection(
        string $student_id,
        int    $section_id,
        int    $enrollment_id,
        string $course_code,
        string $course_name,
        int    $credits = 3
    ): int {
        $pdo = get_db();

        // Get attendance stats for this enrollment in this section
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*)                                AS total,
                SUM(a.status = 'Absent')                AS absent_count,
                SUM(a.status = 'Late')                  AS late_count,
                SUM(a.status = 'Present')               AS present_count,
                SUM(a.status = 'Excused')                AS excused_count
             FROM Attendance a
             WHERE a.enrollment_id = :eid"
        );
        $stmt->execute([':eid' => $enrollment_id]);
        $att = $stmt->fetch();

        $total  = (int)($att['total'] ?? 0);
        $absent = (int)($att['absent_count'] ?? 0);

        if ($total === 0) return 0; // no attendance recorded yet

        // Calculate absent percentage (only 'Absent' counts — Excused doesn't)
        $absentPct = ($absent / $total) * 100;

        // Configurable thresholds from SystemConfig
        $warnPct  = SystemConfig::getFloat('attendance_warn_pct', 15.0);
        $banPct   = SystemConfig::getFloat('attendance_ban_pct',  20.0);

        // Determine warning level using STRICT > rule (not >=)
        // Level 3: absent% > ban_pct (strictly greater than 20%)
        // Level 2: absent% >= ban_pct (exactly at ban threshold = critical)
        // Level 1: absent% >= warn_pct (approaching)
        $level   = 0;
        $condition = false;
        $msg     = '';

        if ($absentPct > $banPct) {
            // Level 3 – Examination Prohibition Risk
            $level = 3;
            $condition = true;
            $remainingAllowed = (int)floor($total * $banPct / 100) - $absent;
            $msg = sprintf(
                "🚫 CẤM THI RỦI RO: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi (%.1f%%) > %d%%.\n" .
                "Bạn có thể bị CẤM THI môn này theo quy chế đào tạo. Vui lòng liên hệ giảng viên hoặc Phòng Đào tạo.",
                $course_name, $course_code, $absent, $total, $absentPct, (int)$banPct
            );
        } elseif (abs($absentPct - $banPct) < 0.01) {
            // Exactly at 20% — critical but not yet prohibited
            $level = 2;
            $condition = true;
            $msg = sprintf(
                "⚠️ CẢNH BÁO NGHIÊM TRỌNG: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi = %.0f%%.\n" .
                "Bạn đang ở ngưỡng cấm thi (%d%%). Vắng thêm 1 buổi sẽ bị cấm thi!",
                $course_name, $course_code, $absent, $total, $absentPct, (int)$banPct
            );
        } elseif ($absentPct >= $warnPct) {
            // Level 1 – Attention
            $level = 1;
            $condition = true;
            $remainingAllowed = (int)floor($total * $banPct / 100) - $absent;
            $msg = sprintf(
                "📋 Chú ý chuyên cần: Môn %s (%s) — Tỷ lệ vắng %d/%d buổi (%.1f%%).\n" .
                "Còn %d buổi vắng được phép trước khi bị cấm thi. Hãy cải thiện tỷ lệ chuyên cần.",
                $course_name, $course_code, $absent, $total, $absentPct,
                max(0, $remainingAllowed)
            );
        }

        // Find existing per-course Attendance warning for this section
        $find = $pdo->prepare(
            'SELECT warning_id, warning_level FROM AcademicWarning
             WHERE student_id = :sid
               AND warning_type = \'Attendance\'
               AND section_id = :sec
               AND status = \'Active\'
             LIMIT 1'
        );
        $find->execute([':sid' => $student_id, ':sec' => $section_id]);
        $existing = $find->fetch();

        if ($condition && !$existing) {
            // Create new per-course warning
            $ins = $pdo->prepare(
                'INSERT INTO AcademicWarning
                    (student_id, warning_type, message, status, is_read, section_id, warning_level)
                 VALUES (:sid, \'Attendance\', :msg, \'Active\', FALSE, :sec, :lvl)'
            );
            $ins->execute([
                ':sid'  => $student_id,
                ':msg'  => $msg,
                ':sec'  => $section_id,
                ':lvl'  => $level,
            ]);

            // Notify student
            self::notifyStudent($student_id, 'Cảnh báo chuyên cần', $msg);
            self::writeAuditLog($pdo, 25, 'CREATE_ATTENDANCE_WARNING',
                'AcademicWarning', $student_id, null,
                json_encode(['section_id' => $section_id, 'level' => $level, 'course' => $course_code]));
            return 1;
        }

        if ($condition && $existing) {
            // Update existing warning if level changed
            if ((int)$existing['warning_level'] !== $level) {
                $upd = $pdo->prepare(
                    'UPDATE AcademicWarning SET message = :msg, warning_level = :lvl, is_read = FALSE
                     WHERE warning_id = :wid'
                );
                $upd->execute([':msg' => $msg, ':lvl' => $level, ':wid' => $existing['warning_id']]);
                self::notifyStudent($student_id, 'Cập nhật cảnh báo chuyên cần', $msg);
            }
            return 0;
        }

        if (!$condition && $existing) {
            // Auto-resolve when attendance improves
            $res = $pdo->prepare(
                'UPDATE AcademicWarning SET status = \'Resolved\', is_read = TRUE
                 WHERE warning_id = :wid'
            );
            $res->execute([':wid' => $existing['warning_id']]);
            self::writeAuditLog($pdo, 25, 'RESOLVE_ATTENDANCE_WARNING',
                'AcademicWarning', $student_id,
                json_encode(['section_id' => $section_id]), null);
            return 0;
        }

        return 0;
    }

    // ── Check condition → create or auto-resolve (non-attendance) ─
    private static function checkAndCreateWarning(
        PDO    $pdo,
        string $student_id,
        string $warning_type,
        bool   $condition,
        string $message
    ): int {
        $find = $pdo->prepare(
            'SELECT warning_id FROM AcademicWarning
             WHERE student_id=:sid AND warning_type=:type AND status=\'Active\'
               AND section_id IS NULL
             LIMIT 1'
        );
        $find->execute([':sid' => $student_id, ':type' => $warning_type]);
        $existing = $find->fetchColumn();

        if ($condition && !$existing) {
            $ins = $pdo->prepare(
                'INSERT INTO AcademicWarning (student_id, warning_type, message, status, is_read)
                 VALUES (:sid, :type, :msg, \'Active\', FALSE)'
            );
            $ins->execute([':sid' => $student_id, ':type' => $warning_type, ':msg' => $message]);

            self::notifyStudent($student_id,
                $warning_type === 'LowGPA' ? 'GPA thấp' : 'Nguy cơ tốt nghiệp',
                $message);
            self::writeAuditLog($pdo, 25, 'CREATE_WARNING', 'AcademicWarning', $student_id,
                null, json_encode(['type' => $warning_type, 'msg' => $message]));
            return 1;
        }

        if (!$condition && $existing) {
            $res = $pdo->prepare(
                'UPDATE AcademicWarning SET status=\'Resolved\', is_read=TRUE WHERE warning_id=:wid'
            );
            $res->execute([':wid' => $existing]);
        }

        return 0;
    }

    // ── Notify student helper ──────────────────────────────────
    private static function notifyStudent(string $student_id, string $title, string $body): void {
        try {
            $pdo = get_db();
            $accStmt = $pdo->prepare('SELECT account_id FROM Account WHERE student_id=:sid LIMIT 1');
            $accStmt->execute([':sid' => $student_id]);
            $accId = (int)$accStmt->fetchColumn();
            if ($accId) {
                NotificationService::send($accId, 'Warning',
                    '⚠️ ' . $title, $body,
                    '/UniPortal/public/assets/student/alerts.php');
            }
        } catch (Throwable) {}
    }

    // ── Trigger after grade save ──────────────────────────────
    public static function afterGradeSave(int $enrollment_id): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT e.student_id, e.section_id, c.course_code, c.course_name, c.credits
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 JOIN Course c ON c.course_id = cs.course_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $stmt->execute([':eid' => $enrollment_id]);
            $row = $stmt->fetch();
            if ($row) {
                self::recalcGpa($row['student_id']);
            }
        } catch (Throwable) {}
    }

    // ── Trigger after attendance save ─────────────────────────
    // Checks attendance for the specific section that was updated.
    public static function afterAttendanceSave(int $enrollment_id): void {
        try {
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'SELECT e.student_id, e.section_id, c.course_code, c.course_name, c.credits
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id = e.section_id
                 JOIN Course c ON c.course_id = cs.course_id
                 WHERE e.enrollment_id=:eid LIMIT 1'
            );
            $stmt->execute([':eid' => $enrollment_id]);
            $row = $stmt->fetch();
            if ($row) {
                self::checkAttendanceForSection(
                    $row['student_id'],
                    (int)$row['section_id'],
                    $enrollment_id,
                    $row['course_code'],
                    $row['course_name'],
                    (int)$row['credits']
                );
            }
        } catch (Throwable) {}
    }

    // ── Get attendance risk summary for a student in a section ─
    // Works for any semester, not just Active.
    public static function getAttendanceRisk(string $student_id, int $section_id): array {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*)                                AS total,
                SUM(a.status = 'Absent')                AS absent,
                SUM(a.status = 'Late')                  AS late,
                SUM(a.status = 'Present')               AS present,
                SUM(a.status = 'Excused')               AS excused
             FROM Attendance a
             JOIN Enrollment e ON e.enrollment_id = a.enrollment_id
             WHERE e.student_id = :sid AND e.section_id = :sec"
        );
        $stmt->execute([':sid' => $student_id, ':sec' => $section_id]);
        $att = $stmt->fetch();

        $total  = (int)($att['total'] ?? 0);
        $absent = (int)($att['absent'] ?? 0);
        $banPct = SystemConfig::getFloat('attendance_ban_pct', 20.0);
        $warnPct = SystemConfig::getFloat('attendance_warn_pct', 15.0);

        $absentPct = $total > 0 ? ($absent / $total) * 100 : 0;
        $banThreshold = (int)floor($total * $banPct / 100);
        $remaining = max(0, $banThreshold - $absent);

        if ($total === 0) {
            return ['level' => 0, 'status' => 'no_data', 'total' => 0, 'absent' => 0,
                    'pct' => 0, 'remaining' => 0];
        }

        $level = 0; $status = 'ok';
        if ($absentPct > $banPct) { $level = 3; $status = 'prohibition'; }
        elseif (abs($absentPct - $banPct) < 0.01) { $level = 2; $status = 'critical'; }
        elseif ($absentPct >= $warnPct) { $level = 1; $status = 'attention'; }

        return [
            'level'        => $level,
            'status'       => $status,
            'total'        => $total,
            'absent'       => $absent,
            'late'         => (int)($att['late'] ?? 0),
            'present'      => (int)($att['present'] ?? 0),
            'excused'      => (int)($att['excused'] ?? 0),
            'pct'          => round($absentPct, 1),
            'ban_pct'      => $banPct,
            'ban_threshold'=> $banThreshold,
            'remaining'    => $remaining,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────
    private static function getActiveSemesterId(PDO $pdo): ?int {
        $row = $pdo->query(
            "SELECT semester_id FROM Semester WHERE status='Active' ORDER BY start_date DESC LIMIT 1"
        )->fetch();
        return $row ? (int)$row['semester_id'] : null;
    }

    public static function writeAuditLog(
        PDO    $pdo,
        int    $actor_id,
        string $action,
        string $target_entity,
        string $target_id,
        ?string $old_json = null,
        ?string $new_json = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO AuditLog
                    (actor_id, action, target_entity, target_id,
                     old_value_json, new_value_json, ip_address)
                 VALUES (:actor, :action, :entity, :target,
                         :old, :new, :ip)'
            );
            $stmt->execute([
                ':actor'  => $actor_id,
                ':action' => $action,
                ':entity' => $target_entity,
                ':target' => $target_id,
                ':old'    => $old_json,
                ':new'    => $new_json,
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {}
    }
}
