<?php
/**
 * UniPortal – RegistrationService
 *
 * Central rules for course offerings and period-based registration:
 *   * structured weekly schedule + conflict detection
 *   * dynamic opening threshold = floor(min_enrollment / 2) + 1
 *   * atomic registration with row locking (no over-enrollment / races)
 *   * automatic confirmation, capacity closure and period finalisation
 *   * audit logging and student notifications
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AcademicAlertService.php';

class RegistrationService {

    // ─────────────────────────────────────────────────────────
    // Schedule helpers
    // ─────────────────────────────────────────────────────────

    public static function openingThreshold(int $minEnrollment): int {
        $min = max(1, $minEnrollment);
        return (int)floor($min / 2) + 1;
    }

    /** @return int[] ISO weekday numbers (1=Mon … 7=Sun), unique + sorted */
    public static function normalizeDays($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw)) return [];
        $days = [];
        foreach ($raw as $d) {
            $n = (int)$d;
            if ($n >= 1 && $n <= 7) $days[$n] = true;
        }
        $out = array_keys($days);
        sort($out);
        return $out;
    }

    public static function daysToString(array $days): string {
        $days = self::normalizeDays($days);
        return implode(',', $days);
    }

    public static function normalizeTime(?string $time): ?string {
        $time = trim((string)$time);
        if ($time === '') return null;
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) return null;
        $h = (int)$m[1];
        $i = (int)$m[2];
        $s = isset($m[3]) ? (int)$m[3] : 0;
        if ($h > 23 || $i > 59 || $s > 59) return null;
        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    public static function scheduleText(array $days, ?string $start, ?string $end): string {
        $days = self::normalizeDays($days);
        $start = self::normalizeTime($start);
        $end   = self::normalizeTime($end);
        if (empty($days) || !$start || !$end) return '';
        $label = implode(' & ', array_map(static fn($d) => self::dayLabel($d), $days));
        return $label . ' ' . substr($start, 0, 5) . '-' . substr($end, 0, 5);
    }

    public static function dayLabel(int $day): string {
        return match ($day) {
            1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5',
            5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'CN',
            default => '',
        };
    }

    /**
     * Parse a legacy schedule string such as "Thứ 2 & 4 07:30-09:00".
     * @return array{days:int[],start:?string,end:?string}
     */
    public static function parseScheduleText(?string $text): array {
        $text = trim((string)$text);
        $out  = ['days' => [], 'start' => null, 'end' => null];
        if ($text === '') return $out;

        if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/u', $text, $m)) {
            $out['start'] = self::normalizeTime($m[1]);
            $out['end']   = self::normalizeTime($m[2]);
        }

        if (preg_match('/(?:Thứ|Thu)\s*([2-7](?:\s*(?:&|,|và|\+)\s*[2-7])*)/iu', $text, $m)) {
            preg_match_all('/[2-7]/', $m[1], $dm);
            // Vietnamese "Thứ 2" is Monday (ISO 1), "Thứ 7" is Saturday (ISO 6).
            $out['days'] = self::normalizeDays(array_map(static fn($n) => (int)$n - 1, $dm[0] ?? []));
        } elseif (preg_match('/\bCN\b/u', $text)) {
            $out['days'] = [7];
        }

        return $out;
    }

    /**
     * Weekly slots for a class section, using structured columns first and
     * falling back to the legacy free-text schedule.
     * @return array<int, array{day:int,start:string,end:string}>
     */
    public static function sectionSlots(array $section): array {
        $days  = self::normalizeDays($section['study_days'] ?? '');
        $start = self::normalizeTime($section['start_time'] ?? null);
        $end   = self::normalizeTime($section['end_time'] ?? null);

        if (empty($days) || !$start || !$end) {
            $parsed = self::parseScheduleText($section['schedule'] ?? '');
            $days   = $parsed['days'];
            $start  = $parsed['start'];
            $end    = $parsed['end'];
        }
        if (empty($days) || !$start || !$end || $start >= $end) return [];

        $slots = [];
        foreach ($days as $d) {
            $slots[] = ['day' => $d, 'start' => $start, 'end' => $end];
        }
        return $slots;
    }

    public static function slotsOverlap(array $a, array $b): bool {
        return (int)$a['day'] === (int)$b['day']
            && $a['start'] < $b['end']
            && $a['end'] > $b['start'];
    }

    public static function sectionsOverlap(array $a, array $b): bool {
        foreach (self::sectionSlots($a) as $sa) {
            foreach (self::sectionSlots($b) as $sb) {
                if (self::slotsOverlap($sa, $sb)) return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────
    // Offering validation (admin)
    // ─────────────────────────────────────────────────────────

    /**
     * Validate an offering/section payload and detect lecturer, room and
     * duplicate-section conflicts.
     *
     * @return array{ok:bool,errors:string[],data:array}
     */
    public static function validateSection(PDO $pdo, array $in, ?int $excludeSectionId = null): array {
        $errors = [];
        $data   = [
            'course_id'    => (int)($in['course_id'] ?? 0),
            'semester_id'  => (int)($in['semester_id'] ?? 0),
            'lecturer_id'  => (int)($in['lecturer_id'] ?? 0),
            'section_code' => strtoupper(trim((string)($in['section_code'] ?? ''))),
            'room'         => trim((string)($in['room'] ?? '')),
            'min_enrollment' => (int)($in['min_enrollment'] ?? 48),
            'max_enrollment' => (int)($in['max_enrollment'] ?? 64),
            'study_days'   => self::normalizeDays($in['study_days'] ?? []),
            'start_time'   => self::normalizeTime($in['start_time'] ?? null),
            'end_time'     => self::normalizeTime($in['end_time'] ?? null),
            'reg_open'     => trim((string)($in['reg_open'] ?? '')),
            'reg_close'    => trim((string)($in['reg_close'] ?? '')),
        ];

        if ($data['course_id'] < 1)   $errors[] = 'Vui lòng chọn môn học.';
        if ($data['semester_id'] < 1) $errors[] = 'Vui lòng chọn học kỳ.';
        if ($data['lecturer_id'] < 1) $errors[] = 'Vui lòng chọn giảng viên.';
        if ($data['section_code'] === '') {
            $errors[] = 'Vui lòng nhập mã lớp học phần.';
        } elseif (mb_strlen($data['section_code']) > 20) {
            $errors[] = 'Mã lớp tối đa 20 ký tự.';
        }
        if ($data['min_enrollment'] < 1 || $data['min_enrollment'] > 200) {
            $errors[] = 'Sĩ số tối thiểu phải từ 1 đến 200.';
        }
        if ($data['max_enrollment'] < 1 || $data['max_enrollment'] > 200) {
            $errors[] = 'Sĩ số tối đa phải từ 1 đến 200.';
        }
        if ($data['max_enrollment'] < $data['min_enrollment']) {
            $errors[] = 'Sĩ số tối đa phải lớn hơn hoặc bằng sĩ số tối thiểu.';
        }
        if (empty($data['study_days'])) {
            $errors[] = 'Vui lòng chọn ít nhất một ngày học.';
        }
        if (!$data['start_time'] || !$data['end_time']) {
            $errors[] = 'Giờ bắt đầu và giờ kết thúc không hợp lệ.';
        } elseif ($data['start_time'] >= $data['end_time']) {
            $errors[] = 'Giờ kết thúc phải sau giờ bắt đầu.';
        }

        if ($data['reg_open'] !== '') {
            $ts = strtotime($data['reg_open']);
            if ($ts === false) $errors[] = 'Thời gian mở đăng ký không hợp lệ.';
            else $data['reg_open'] = date('Y-m-d H:i:s', $ts);
        } else {
            $data['reg_open'] = null;
        }
        if ($data['reg_close'] !== '') {
            $ts = strtotime($data['reg_close']);
            if ($ts === false) $errors[] = 'Thời gian đóng đăng ký không hợp lệ.';
            else $data['reg_close'] = date('Y-m-d H:i:s', $ts);
        } else {
            $data['reg_close'] = null;
        }
        if ($data['reg_open'] && $data['reg_close'] && $data['reg_close'] <= $data['reg_open']) {
            $errors[] = 'Thời gian đóng đăng ký phải sau thời gian mở đăng ký.';
        }

        if ($errors) return ['ok' => false, 'errors' => $errors, 'data' => $data];

        $course = $pdo->prepare('SELECT course_id, course_code, course_name FROM Course WHERE course_id=:id AND is_active=TRUE LIMIT 1');
        $course->execute([':id' => $data['course_id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) $errors[] = 'Môn học không tồn tại hoặc đã ngừng mở.';

        $sem = $pdo->prepare('SELECT semester_id, semester_name, academic_year FROM Semester WHERE semester_id=:id LIMIT 1');
        $sem->execute([':id' => $data['semester_id']]);
        if (!$sem->fetch()) $errors[] = 'Học kỳ không hợp lệ.';

        $lec = $pdo->prepare(
            "SELECT l.lecturer_id, l.full_name FROM Lecturer l
             JOIN Account a ON a.lecturer_id = l.lecturer_id
             WHERE l.lecturer_id=:id AND l.status='Active' AND a.role='Lecturer' AND a.is_active=1
             LIMIT 1"
        );
        $lec->execute([':id' => $data['lecturer_id']]);
        $lecRow = $lec->fetch();
        if (!$lecRow) $errors[] = 'Giảng viên không hợp lệ hoặc đã ngừng hoạt động.';

        if ($errors) return ['ok' => false, 'errors' => $errors, 'data' => $data];

        $dup = $pdo->prepare(
            'SELECT section_id FROM ClassSection
             WHERE section_code=:code AND semester_id=:sem
               AND (:exclude = 0 OR section_id <> :exclude2) LIMIT 1'
        );
        $dup->execute([':code' => $data['section_code'], ':sem' => $data['semester_id'],
                       ':exclude' => (int)($excludeSectionId ?? 0), ':exclude2' => (int)($excludeSectionId ?? 0)]);
        if ($dup->fetch()) $errors[] = 'Mã lớp ' . $data['section_code'] . ' đã tồn tại trong học kỳ này.';

        $newSection = [
            'section_id'  => (int)($excludeSectionId ?? 0),
            'course_id'   => $data['course_id'],
            'semester_id' => $data['semester_id'],
            'lecturer_id' => $data['lecturer_id'],
            'room'        => $data['room'],
            'study_days'  => self::daysToString($data['study_days']),
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'section_code'=> $data['section_code'],
            'schedule'    => self::scheduleText($data['study_days'], $data['start_time'], $data['end_time']),
        ];

        $q = $pdo->prepare(
            "SELECT cs.section_id, cs.section_code, cs.course_id, cs.lecturer_id, cs.room,
                    cs.schedule, cs.study_days, cs.start_time, cs.end_time,
                    c.course_code, c.course_name, l.full_name AS lecturer_name
             FROM ClassSection cs
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE cs.semester_id = :sem
               AND cs.section_status <> 'Cancelled'
               AND (:exclude = 0 OR cs.section_id <> :exclude2)"
        );
        $q->execute([':sem' => $data['semester_id'],
                     ':exclude' => (int)($excludeSectionId ?? 0), ':exclude2' => (int)($excludeSectionId ?? 0)]);
        $others = $q->fetchAll();

        foreach ($others as $other) {
            if (!self::sectionsOverlap($newSection, $other)) continue;

            if ((int)$other['lecturer_id'] === $data['lecturer_id']) {
                $errors[] = 'Trùng lịch giảng viên ' . $lecRow['full_name']
                    . ': lớp ' . $other['section_code'] . ' (' . $other['course_code'] . ') đã dạy cùng khung giờ.';
            }
            if ($data['room'] !== '' && strcasecmp(trim((string)$other['room']), $data['room']) === 0) {
                $errors[] = 'Trùng phòng ' . $data['room'] . ': lớp ' . $other['section_code']
                    . ' (' . $other['course_code'] . ') đã dùng phòng này cùng khung giờ.';
            }
            if ((int)$other['course_id'] === $data['course_id']
                && (int)$other['lecturer_id'] === $data['lecturer_id']) {
                $errors[] = 'Lớp ' . $other['section_code'] . ' của cùng môn và giảng viên đã trùng lịch.';
            }
        }

        return ['ok' => empty($errors), 'errors' => array_values(array_unique($errors)), 'data' => $data];
    }

    // ─────────────────────────────────────────────────────────
    // Counts and statuses
    // ─────────────────────────────────────────────────────────

    public static function enrolledCount(PDO $pdo, int $sectionId): int {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM Enrollment WHERE section_id=:sid AND status IN ('Enrolled','Completed')"
        );
        $s->execute([':sid' => $sectionId]);
        return (int)$s->fetchColumn();
    }

    /** @return array{label:string,bg:string,fg:string} */
    public static function statusMeta(string $status): array {
        return match ($status) {
            'Confirmed'    => ['label' => 'Đã mở lớp',       'bg' => '#d1fae5', 'fg' => '#065f46'],
            'Insufficient' => ['label' => 'Không đủ sĩ số',  'bg' => '#fee2e2', 'fg' => '#b91c1c'],
            'Cancelled'    => ['label' => 'Đã hủy',          'bg' => '#f1f5f9', 'fg' => '#64748b'],
            default        => ['label' => 'Chờ mở lớp',      'bg' => '#fef9c3', 'fg' => '#854d0e'],
        };
    }

    /**
     * Human readable capacity state, e.g.
     * "25/64 sinh viên đăng ký – Lớp đủ điều kiện mở."
     * @return array{text:string,short:string,state:string,threshold:int,pct:int}
     */
    public static function capacityState(array $section, int $count): array {
        $min  = max(1, (int)($section['min_enrollment'] ?? 48));
        $max  = max($min, (int)($section['max_enrollment'] ?? ($section['capacity'] ?? 64)));
        $thr  = self::openingThreshold($min);
        $pct  = $max > 0 ? (int)min(100, round($count / $max * 100)) : 0;

        if ($count >= $max) {
            $state = 'full';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Đã đủ sĩ số tối đa, đóng đăng ký.';
            $short = 'Đã đầy';
        } elseif ($count >= $thr) {
            $state = 'open';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Lớp đủ điều kiện mở (ngưỡng ' . $thr . ').';
            $short = 'Đủ điều kiện mở';
        } else {
            $state = 'pending';
            $text  = $count . '/' . $max . ' sinh viên đăng ký – Chưa đủ ngưỡng mở (cần ' . $thr . ').';
            $short = 'Chờ mở lớp';
        }
        return ['text' => $text, 'short' => $short, 'state' => $state, 'threshold' => $thr, 'pct' => $pct];
    }

    public static function refreshPeriodStatuses(PDO $pdo): void {
        try {
            $pdo->exec("UPDATE registrationperiod SET status='Open'
                        WHERE status='Upcoming' AND NOW() BETWEEN start_at AND end_at");
            $pdo->exec("UPDATE registrationperiod SET status='Closed'
                        WHERE status='Open' AND NOW() > end_at");
        } catch (Throwable) {}
    }

    /** @return array<int,array> open/upcoming periods the student may join */
    public static function eligiblePeriodsForStudent(PDO $pdo, string $studentId): array {
        self::refreshPeriodStatuses($pdo);
        $stmt = $pdo->prepare(
            "SELECT rp.*, sem.semester_name, sem.academic_year, sem.status AS semester_status,
                    sem.start_date, sem.end_date
             FROM registrationperiod rp
             JOIN semester sem ON sem.semester_id = rp.semester_id
             WHERE rp.status IN ('Open','Upcoming')
               AND sem.status <> 'Completed'
               AND (
                    NOT EXISTS (SELECT 1 FROM registrationperiodmajor rpm WHERE rpm.period_id = rp.period_id)
                    OR EXISTS (
                        SELECT 1 FROM registrationperiodmajor rpm
                        JOIN student s ON s.major_id = rpm.major_id
                        WHERE rpm.period_id = rp.period_id AND s.student_id = :sid
                    )
               )
             ORDER BY (rp.status='Open') DESC, sem.start_date ASC, rp.start_at ASC"
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Create the regular AttendanceSession rows for a section that just became
     * Confirmed but has no official TeachingSchedule yet. Without this the
     * lecturer would have no sessions to take attendance or attach
     * assignments/grades to.
     */
    public static function ensureSessionsForSection(PDO $pdo, int $sectionId): int {
        $q = $pdo->prepare(
            'SELECT cs.section_id, cs.semester_id, cs.lecturer_id, cs.room, cs.section_status,
                    cs.schedule, cs.study_days, cs.start_time, cs.end_time,
                    sem.start_date, sem.end_date
             FROM ClassSection cs
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             WHERE cs.section_id = :sid LIMIT 1'
        );
        $q->execute([':sid' => $sectionId]);
        $section = $q->fetch();
        if (!$section || $section['section_status'] !== 'Confirmed') return 0;

        // TeachingSchedule::syncSessions owns session generation once an
        // official schedule exists; never duplicate it here.
        $has = $pdo->prepare('SELECT 1 FROM TeachingSchedule WHERE section_id = :sid LIMIT 1');
        $has->execute([':sid' => $sectionId]);
        if ($has->fetch()) return 0;
        $has = $pdo->prepare('SELECT 1 FROM AttendanceSession WHERE section_id = :sid LIMIT 1');
        $has->execute([':sid' => $sectionId]);
        if ($has->fetch()) return 0;

        $slots = self::sectionSlots($section);
        if (empty($slots)) return 0;

        $start = new DateTimeImmutable($section['start_date']);
        $today = new DateTimeImmutable(date('Y-m-d'));
        if ($today > $start) $start = $today;
        $end = new DateTimeImmutable($section['end_date']);
        if ($start > $end) return 0;

        $insert = $pdo->prepare(
            "INSERT INTO AttendanceSession
                (section_id, schedule_id, lecturer_id, session_date, start_time, end_time,
                 room, topic, session_type, status)
             VALUES (:sid, NULL, :lid, :d, :st, :en, :room, NULL, 'Regular', 'Scheduled')
             ON DUPLICATE KEY UPDATE session_id = session_id"
        );

        $inserted = 0;
        foreach ($slots as $slot) {
            $offset = ((int)$slot['day'] - (int)$start->format('N') + 7) % 7;
            $d = $start->modify('+' . $offset . ' days');
            while ($d <= $end) {
                $insert->execute([
                    ':sid'  => $sectionId,
                    ':lid'  => (int)$section['lecturer_id'],
                    ':d'    => $d->format('Y-m-d'),
                    ':st'   => $slot['start'],
                    ':en'   => $slot['end'],
                    ':room' => $section['room'] !== '' ? $section['room'] : null,
                ]);
                $inserted += $insert->rowCount() > 0 ? 1 : 0;
                $d = $d->modify('+7 days');
            }
        }
        return $inserted;
    }

    public static function periodIsOpen(array $period): bool {
        $now = time();
        return $period['status'] === 'Open'
            && $now >= strtotime($period['start_at'])
            && $now <= strtotime($period['end_at']);
    }

    public static function studentMajor(PDO $pdo, string $studentId): array|false {
        $stmt = $pdo->prepare(
            'SELECT s.student_id, s.major_id, s.admission_year, s.department_id,
                    m.major_code, m.major_name, d.department_code, d.department_name
             FROM student s
             LEFT JOIN major m ON m.major_id = s.major_id
             LEFT JOIN department d ON d.department_id = s.department_id
             WHERE s.student_id = :sid LIMIT 1'
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────
    // Atomic registration
    // ─────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,message:string,section:?array,status_changed:bool}
     */
    public static function register(PDO $pdo, string $studentId, int $accountId, int $sectionId, ?int $periodId = null): array {
        if ($sectionId < 1) return ['ok' => false, 'message' => 'Lớp học phần không hợp lệ.', 'section' => null, 'status_changed' => false];

        $student = self::studentMajor($pdo, $studentId);
        if (!$student) return ['ok' => false, 'message' => 'Không tìm thấy sinh viên.', 'section' => null, 'status_changed' => false];
        if (!$student['major_id']) return ['ok' => false, 'message' => 'Tài khoản chưa được gán ngành học.', 'section' => null, 'status_changed' => false];

        $statusChanged = false;
        $section = null;
        $confirmedNow = false;

        try {
            $pdo->beginTransaction();

            // Period: explicit id or the student's currently open period.
            if ($periodId) {
                $qp = $pdo->prepare(
                    "SELECT rp.* FROM registrationperiod rp
                     WHERE rp.period_id = :pid FOR UPDATE"
                );
                $qp->execute([':pid' => $periodId]);
            } else {
                $qp = $pdo->prepare(
                    "SELECT rp.* FROM registrationperiod rp
                     WHERE rp.status='Open' AND NOW() BETWEEN rp.start_at AND rp.end_at
                       AND (NOT EXISTS (SELECT 1 FROM registrationperiodmajor rpm WHERE rpm.period_id=rp.period_id)
                            OR EXISTS (SELECT 1 FROM registrationperiodmajor rpm
                                       WHERE rpm.period_id=rp.period_id AND rpm.major_id=:mid))
                     ORDER BY rp.start_at DESC LIMIT 1 FOR UPDATE"
                );
                $qp->execute([':mid' => (int)$student['major_id']]);
            }
            $period = $qp->fetch();

            if (!$period) {
                throw new RuntimeException('Hiện không có đợt đăng ký nào đang mở.');
            }
            if ($period['status'] !== 'Open' || time() < strtotime($period['start_at']) || time() > strtotime($period['end_at'])) {
                throw new RuntimeException('Ngoài thời gian đăng ký của đợt này.');
            }

            $majorRestricted = (int)$pdo->query(
                'SELECT COUNT(*) FROM registrationperiodmajor WHERE period_id=' . (int)$period['period_id']
            )->fetchColumn() > 0;
            if ($majorRestricted) {
                $qm = $pdo->prepare(
                    'SELECT 1 FROM registrationperiodmajor WHERE period_id=:pid AND major_id=:mid LIMIT 1'
                );
                $qm->execute([':pid' => $period['period_id'], ':mid' => (int)$student['major_id']]);
                if (!$qm->fetch()) throw new RuntimeException('Ngành của bạn không thuộc đợt đăng ký này.');
            }

            $hasSectionMapping = (int)$pdo->query(
                'SELECT COUNT(*) FROM registrationperiodsection WHERE period_id=' . (int)$period['period_id']
            )->fetchColumn() > 0;

            // Lock the section row: serialises concurrent registrations.
            $qs = $pdo->prepare(
                'SELECT cs.*, c.course_code, c.course_name, c.credits, c.prerequisite_course_id,
                        c.is_active AS course_active, l.full_name AS lecturer_name,
                        sem.semester_id, sem.semester_name, sem.academic_year
                 FROM ClassSection cs
                 JOIN Course c ON c.course_id = cs.course_id
                 JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
                 JOIN Semester sem ON sem.semester_id = cs.semester_id
                 WHERE cs.section_id = :sid FOR UPDATE'
            );
            $qs->execute([':sid' => $sectionId]);
            $section = $qs->fetch();

            if (!$section) throw new RuntimeException('Lớp học phần không tồn tại.');
            if ((int)$section['semester_id'] !== (int)$period['semester_id']) {
                throw new RuntimeException('Lớp học phần không thuộc học kỳ của đợt đăng ký.');
            }
            if ($hasSectionMapping) {
                $qps = $pdo->prepare(
                    'SELECT 1 FROM registrationperiodsection WHERE period_id=:pid AND section_id=:sid LIMIT 1'
                );
                $qps->execute([':pid' => $period['period_id'], ':sid' => $sectionId]);
                if (!$qps->fetch()) throw new RuntimeException('Lớp này không nằm trong danh sách mở đăng ký.');
            }
            if ($section['section_status'] === 'Cancelled') throw new RuntimeException('Lớp học phần đã bị hủy.');
            if ($section['section_status'] === 'Insufficient') throw new RuntimeException('Lớp không đủ sĩ số để mở.');
            if (!(int)$section['course_active']) throw new RuntimeException('Môn học đã ngừng mở đăng ký.');

            // Course must belong to the student's curriculum.
            $qc = $pdo->prepare(
                'SELECT 1 FROM student s
                 JOIN curriculum cur ON cur.major_id = s.major_id AND cur.admission_year = s.admission_year
                 JOIN curriculumcourse cc ON cc.curriculum_id = cur.curriculum_id
                 WHERE s.student_id = :sid AND cc.course_id = :cid LIMIT 1'
            );
            $qc->execute([':sid' => $studentId, ':cid' => (int)$section['course_id']]);
            if (!$qc->fetch()) throw new RuntimeException('Môn học không thuộc chương trình đào tạo của bạn.');

            // Already completed this course?
            $qcomp = $pdo->prepare(
                "SELECT 1 FROM Enrollment e JOIN ClassSection cs ON cs.section_id=e.section_id
                 WHERE e.student_id=:sid AND cs.course_id=:cid AND e.status='Completed' LIMIT 1"
            );
            $qcomp->execute([':sid' => $studentId, ':cid' => (int)$section['course_id']]);
            if ($qcomp->fetch()) throw new RuntimeException('Bạn đã hoàn thành môn ' . $section['course_code'] . '.');

            // Already taking the course in this semester (another section)?
            $qsame = $pdo->prepare(
                "SELECT cs.section_code FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 WHERE e.student_id=:sid AND cs.course_id=:cid AND cs.semester_id=:sem
                   AND e.status='Enrolled' LIMIT 1"
            );
            $qsame->execute([':sid' => $studentId, ':cid' => (int)$section['course_id'], ':sem' => (int)$section['semester_id']]);
            if ($same = $qsame->fetch()) {
                throw new RuntimeException('Bạn đã đăng ký môn ' . $section['course_code'] . ' ở lớp ' . $same['section_code'] . '.');
            }

            // Existing row for this exact section.
            $qexist = $pdo->prepare('SELECT enrollment_id, status FROM Enrollment WHERE student_id=:sid AND section_id=:sec LIMIT 1');
            $qexist->execute([':sid' => $studentId, ':sec' => $sectionId]);
            $existing = $qexist->fetch();
            if ($existing && $existing['status'] !== 'Withdrawn') {
                throw new RuntimeException('Bạn đã đăng ký lớp này rồi.');
            }

            // Prerequisite: must be completed with a passing final score.
            if (!empty($section['prerequisite_course_id'])) {
                $qpre = $pdo->prepare(
                    "SELECT e.enrollment_id
                     FROM Enrollment e
                     JOIN ClassSection cs ON cs.section_id=e.section_id
                     JOIN Assessment a ON a.section_id=cs.section_id
                     JOIN Grade g ON g.enrollment_id=e.enrollment_id AND g.assessment_id=a.assessment_id
                     WHERE e.student_id=:sid AND cs.course_id=:pre AND e.status='Completed' AND g.score IS NOT NULL
                     GROUP BY e.enrollment_id
                     HAVING ROUND(SUM(g.score * a.weight_percent / 100) / 10, 1) >= 4.0
                     LIMIT 1"
                );
                $qpre->execute([':sid' => $studentId, ':pre' => (int)$section['prerequisite_course_id']]);
                if (!$qpre->fetch()) {
                    $qpn = $pdo->prepare('SELECT course_code FROM Course WHERE course_id=:id LIMIT 1');
                    $qpn->execute([':id' => (int)$section['prerequisite_course_id']]);
                    throw new RuntimeException('Chưa đạt môn tiên quyết ' . (string)$qpn->fetchColumn() . '.');
                }
            }

            // Schedule conflict with any other enrolled section in this semester.
            $qen = $pdo->prepare(
                "SELECT cs.section_id, cs.section_code, cs.schedule, cs.study_days, cs.start_time, cs.end_time,
                        c.course_code
                 FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 JOIN Course c ON c.course_id=cs.course_id
                 WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status='Enrolled'
                   AND cs.section_id <> :sec"
            );
            $qen->execute([':sid' => $studentId, ':sem' => (int)$section['semester_id'], ':sec' => $sectionId]);
            foreach ($qen->fetchAll() as $other) {
                if (self::sectionsOverlap($section, $other)) {
                    throw new RuntimeException('Trùng lịch học với lớp ' . $other['section_code']
                        . ' (' . $other['course_code'] . ').');
                }
            }

            // Capacity check while holding the section lock.
            $count = self::enrolledCount($pdo, $sectionId);
            $max   = max(1, (int)($section['max_enrollment'] ?: ($section['capacity'] ?: 64)));
            if ($count >= $max) {
                throw new RuntimeException('Lớp đã đủ sĩ số tối đa ' . $max . '.');
            }

            if ($existing) {
                $pdo->prepare("UPDATE Enrollment SET status='Enrolled', enrollment_date=NOW() WHERE enrollment_id=:eid")
                    ->execute([':eid' => $existing['enrollment_id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO Enrollment (student_id, section_id, status, enrollment_date)
                     VALUES (:sid, :sec, 'Enrolled', NOW())"
                )->execute([':sid' => $studentId, ':sec' => $sectionId]);
            }

            $count++;
            $threshold = self::openingThreshold((int)($section['min_enrollment'] ?: 48));

            if ($section['section_status'] === 'Pending' && $count >= $threshold) {
                $pdo->prepare(
                    "UPDATE ClassSection
                     SET section_status='Confirmed', status_changed_at=NOW(),
                         status_note=:note
                     WHERE section_id=:sid AND section_status='Pending'"
                )->execute([
                    ':note' => 'Tự động mở lớp khi đạt ngưỡng ' . $threshold . ' sinh viên.',
                    ':sid'  => $sectionId,
                ]);
                $statusChanged = true;
                $confirmedNow  = true;
            }

            AcademicAlertService::writeAuditLog(
                $pdo, $accountId, 'STUDENT_REGISTER', 'Enrollment', $studentId,
                null,
                json_encode([
                    'period_id'  => (int)$period['period_id'],
                    'section_id' => $sectionId,
                    'course'     => $section['course_code'],
                    'count_after'=> $count,
                ], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();

            self::recalculateTuition($pdo, $studentId, (int)$section['semester_id']);

            NotificationService::send(
                $accountId, 'Registration',
                '✅ Đăng ký thành công: ' . $section['course_code'],
                'Bạn đã đăng ký lớp ' . $section['section_code'] . ' – ' . $section['course_name'],
                '/UniPortal/public/assets/student/registration.php'
            );

            if ($confirmedNow) {
                try { self::ensureSessionsForSection($pdo, $sectionId); } catch (Throwable) {}
                self::notifySectionStudents(
                    $pdo, $sectionId,
                    '🎉 Lớp đã đủ điều kiện mở: ' . $section['section_code'],
                    'Lớp ' . $section['section_code'] . ' đã đạt ngưỡng mở lớp. Lịch học chính thức đã có trong thời khóa biểu.',
                );
            }

            return [
                'ok'             => true,
                'message'        => 'Đã đăng ký lớp ' . $section['section_code'] . ' – ' . $section['course_name'] . '.',
                'section'        => $section,
                'status_changed' => $statusChanged,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage(), 'section' => $section, 'status_changed' => false];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function drop(PDO $pdo, string $studentId, int $accountId, int $sectionId, ?int $periodId = null): array {
        try {
            $pdo->beginTransaction();

            if ($periodId) {
                $qp = $pdo->prepare('SELECT * FROM registrationperiod WHERE period_id=:pid FOR UPDATE');
                $qp->execute([':pid' => $periodId]);
                $period = $qp->fetch();
                if (!$period) throw new RuntimeException('Đợt đăng ký không tồn tại.');
                if ($period['status'] !== 'Open'
                    || time() < strtotime($period['start_at'])
                    || time() > strtotime($period['end_at'])) {
                    throw new RuntimeException('Ngoài thời gian đăng ký của đợt này.');
                }
            }

            $qs = $pdo->prepare(
                'SELECT cs.section_id, cs.section_code, c.course_code, cs.semester_id, cs.reg_open, cs.reg_close,
                        sem.reg_open AS sem_reg_open, sem.reg_close AS sem_reg_close
                 FROM ClassSection cs
                 JOIN Course c ON c.course_id = cs.course_id
                 JOIN Semester sem ON sem.semester_id = cs.semester_id
                 WHERE cs.section_id = :sid FOR UPDATE'
            );
            $qs->execute([':sid' => $sectionId]);
            $section = $qs->fetch();
            if (!$section) throw new RuntimeException('Lớp học phần không tồn tại.');
            if ($periodId && (int)$section['semester_id'] !== (int)$period['semester_id']) {
                throw new RuntimeException('Lớp học phần không thuộc học kỳ của đợt đăng ký.');
            }

            $q = $pdo->prepare(
                "SELECT enrollment_id, status FROM Enrollment
                 WHERE student_id=:sid AND section_id=:sec FOR UPDATE"
            );
            $q->execute([':sid' => $studentId, ':sec' => $sectionId]);
            $enr = $q->fetch();
            if (!$enr || $enr['status'] !== 'Enrolled') {
                throw new RuntimeException('Bạn không có đăng ký đang học ở lớp này.');
            }

            $open  = $section['reg_open']  ?: $section['sem_reg_open'];
            $close = $section['reg_close'] ?: $section['sem_reg_close'];
            if ($open && $close && (time() < strtotime($open) || time() > strtotime($close))) {
                throw new RuntimeException('Ngoài thời gian được phép hủy đăng ký.');
            }

            $pdo->prepare("UPDATE Enrollment SET status='Withdrawn' WHERE enrollment_id=:eid")
                ->execute([':eid' => $enr['enrollment_id']]);

            AcademicAlertService::writeAuditLog(
                $pdo, $accountId, 'STUDENT_DROP', 'Enrollment', $studentId,
                json_encode(['section_id' => $sectionId], JSON_UNESCAPED_UNICODE), null
            );

            $pdo->commit();

            self::recalculateTuition($pdo, $studentId, (int)$section['semester_id']);

            NotificationService::send(
                $accountId, 'Registration',
                'Đã hủy đăng ký: ' . $section['section_code'],
                'Bạn đã hủy đăng ký lớp ' . $section['section_code'] . '. Trạng thái mở lớp (nếu đã xác nhận) được giữ nguyên.',
                '/UniPortal/public/assets/student/registration.php'
            );

            return ['ok' => true, 'message' => 'Đã hủy đăng ký lớp ' . $section['section_code'] . '.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────
    // Period finalisation and authorised overrides
    // ─────────────────────────────────────────────────────────

    /**
     * Mark pending sections below the opening threshold as insufficient,
     * confirm any section that already crossed the threshold, then close
     * the period. Confirmed sections never change back automatically.
     *
     * @return array{ok:bool,message:string,confirmed:int,insufficient:int}
     */
    public static function finalizePeriod(PDO $pdo, int $periodId, int $adminAccountId): array {
        $confirmed = 0;
        $insufficient = 0;
        try {
            $pdo->beginTransaction();

            $q = $pdo->prepare('SELECT * FROM registrationperiod WHERE period_id=:id FOR UPDATE');
            $q->execute([':id' => $periodId]);
            $period = $q->fetch();
            if (!$period) throw new RuntimeException('Đợt đăng ký không tồn tại.');

            $sections = $pdo->prepare(
                'SELECT cs.* FROM registrationperiodsection rps
                 JOIN ClassSection cs ON cs.section_id = rps.section_id
                 WHERE rps.period_id = :pid FOR UPDATE'
            );
            $sections->execute([':pid' => $periodId]);
            $rows = $sections->fetchAll();

            // No explicit section mapping means the period applies to the
            // whole semester, so finalisation must consider every section.
            if (empty($rows)) {
                $all = $pdo->prepare(
                    "SELECT * FROM ClassSection
                     WHERE semester_id=:sem AND section_status<>'Cancelled' FOR UPDATE"
                );
                $all->execute([':sem' => (int)$period['semester_id']]);
                $rows = $all->fetchAll();
            }

            foreach ($rows as $section) {
                if ($section['section_status'] !== 'Pending') continue;
                $count = self::enrolledCount($pdo, (int)$section['section_id']);
                $threshold = self::openingThreshold((int)($section['min_enrollment'] ?: 48));

                if ($count >= $threshold) {
                    $pdo->prepare(
                        "UPDATE ClassSection SET section_status='Confirmed', status_changed_at=NOW(), status_note=:n
                         WHERE section_id=:sid"
                    )->execute([
                        ':n'   => 'Xác nhận khi chốt đợt: đạt ngưỡng ' . $threshold . '.',
                        ':sid' => (int)$section['section_id'],
                    ]);
                    $confirmed++;
                    try { self::ensureSessionsForSection($pdo, (int)$section['section_id']); } catch (Throwable) {}
                } else {
                    $pdo->prepare(
                        "UPDATE ClassSection SET section_status='Insufficient', status_changed_at=NOW(), status_note=:n
                         WHERE section_id=:sid"
                    )->execute([
                        ':n'   => 'Không đạt ngưỡng mở lớp ' . $threshold . ' khi chốt đợt.',
                        ':sid' => (int)$section['section_id'],
                    ]);
                    $insufficient++;
                    self::notifySectionStudents(
                        $pdo, (int)$section['section_id'],
                        '⚠️ Lớp chưa đủ sĩ số: ' . $section['section_code'],
                        'Lớp ' . $section['section_code'] . ' chưa đạt ngưỡng ' . $threshold
                        . ' sinh viên. Nhà trường sẽ thông báo phương án tiếp theo.',
                    );
                }
            }

            $pdo->prepare("UPDATE registrationperiod SET status='Closed' WHERE period_id=:id")
                ->execute([':id' => $periodId]);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, 'FINALIZE_REGISTRATION_PERIOD', 'RegistrationPeriod', (string)$periodId,
                null,
                json_encode(['confirmed' => $confirmed, 'insufficient' => $insufficient], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();
            return [
                'ok' => true,
                'message' => 'Đã chốt đợt đăng ký: ' . $confirmed . ' lớp được xác nhận, '
                    . $insufficient . ' lớp không đủ sĩ số.',
                'confirmed' => $confirmed,
                'insufficient' => $insufficient,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage(), 'confirmed' => 0, 'insufficient' => 0];
        }
    }

    /**
     * Authorised admin status override (audited). Confirmed/Pending may be
     * set manually; Cancelled/Insufficient notify the enrolled students.
     *
     * @return array{ok:bool,message:string}
     */
    public static function overrideSectionStatus(PDO $pdo, int $sectionId, string $newStatus, string $note, int $adminAccountId): array {
        $allowed = ['Pending','Confirmed','Insufficient','Cancelled'];
        if (!in_array($newStatus, $allowed, true)) return ['ok' => false, 'message' => 'Trạng thái không hợp lệ.'];

        $q = $pdo->prepare('SELECT * FROM ClassSection WHERE section_id=:sid LIMIT 1');
        $q->execute([':sid' => $sectionId]);
        $section = $q->fetch();
        if (!$section) return ['ok' => false, 'message' => 'Lớp học phần không tồn tại.'];

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE ClassSection
                 SET section_status=:st, status_changed_at=NOW(), status_note=:note
                 WHERE section_id=:sid'
            )->execute([':st' => $newStatus, ':note' => mb_substr($note, 0, 255), ':sid' => $sectionId]);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, 'OVERRIDE_SECTION_STATUS', 'ClassSection', (string)$sectionId,
                json_encode(['section_status' => $section['section_status']], JSON_UNESCAPED_UNICODE),
                json_encode(['section_status' => $newStatus, 'note' => $note], JSON_UNESCAPED_UNICODE)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Không thể đổi trạng thái: ' . $e->getMessage()];
        }

        if ($newStatus === 'Confirmed') {
            try { self::ensureSessionsForSection($pdo, $sectionId); } catch (Throwable) {}
        }
        if (in_array($newStatus, ['Cancelled','Insufficient'], true)) {
            $meta = self::statusMeta($newStatus);
            self::notifySectionStudents(
                $pdo, $sectionId,
                '⚠️ Lớp ' . $section['section_code'] . ': ' . $meta['label'],
                $note !== '' ? $note : 'Nhà trường đã cập nhật trạng thái lớp học phần.',
            );
        }
        return ['ok' => true, 'message' => 'Đã cập nhật trạng thái lớp ' . $section['section_code'] . ' thành "' . self::statusMeta($newStatus)['label'] . '".'];
    }

    // ─────────────────────────────────────────────────────────
    // Notifications + tuition
    // ─────────────────────────────────────────────────────────

    public static function notifySectionStudents(PDO $pdo, int $sectionId, string $title, string $body, string $link = ''): void {
        try {
            $q = $pdo->prepare(
                "SELECT a.account_id FROM Enrollment e
                 JOIN Account a ON a.student_id = e.student_id
                 WHERE e.section_id=:sid AND e.status='Enrolled' AND a.is_active=1"
            );
            $q->execute([':sid' => $sectionId]);
            foreach ($q->fetchAll() as $row) {
                NotificationService::send((int)$row['account_id'], 'Registration', $title, $body,
                    $link !== '' ? $link : '/UniPortal/public/assets/student/registration.php');
            }
        } catch (Throwable) {}
    }

    public static function recalculateTuition(PDO $pdo, string $studentId, int $semesterId): void {
        try {
            $q = $pdo->prepare(
                "SELECT COALESCE(SUM(c.credits),0) FROM Enrollment e
                 JOIN ClassSection cs ON cs.section_id=e.section_id
                 JOIN Course c ON c.course_id=cs.course_id
                 WHERE e.student_id=:sid AND cs.semester_id=:sem AND e.status IN ('Enrolled','Completed')"
            );
            $q->execute([':sid' => $studentId, ':sem' => $semesterId]);
            $credits = (int)$q->fetchColumn();

            $rate = SystemConfig::getInt('tuition_per_credit_vnd', 500000);
            if ($rate <= 0) $rate = 500000;
            $amount = $credits * $rate;

            $pdo->prepare(
                'INSERT INTO Tuition (student_id, semester_id, credits, amount_vnd, due_date, status)
                 VALUES (:sid, :sem, :tc, :amount,
                         DATE_ADD((SELECT start_date FROM Semester WHERE semester_id=:sem2), INTERVAL 30 DAY),
                         \'Unpaid\')
                 ON DUPLICATE KEY UPDATE credits=VALUES(credits), amount_vnd=VALUES(amount_vnd)'
            )->execute([
                ':sid' => $studentId, ':sem' => $semesterId, ':sem2' => $semesterId,
                ':tc' => $credits, ':amount' => $amount,
            ]);
        } catch (Throwable) {}
    }
}
