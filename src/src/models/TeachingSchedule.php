<?php
/**
 * UniPortal – Teaching Schedule model
 *
 * Owns the official weekly teaching schedule (Admin), automatic session
 * generation, and the lecturer request workflow (cancellation / make-up),
 * including admin approval, notifications and audit logging.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AcademicAlertService.php';

class TeachingSchedule {

    public const DAYS = [
        1 => 'Thứ 2',
        2 => 'Thứ 3',
        3 => 'Thứ 4',
        4 => 'Thứ 5',
        5 => 'Thứ 6',
        6 => 'Thứ 7',
        7 => 'Chủ nhật',
    ];

    public const DAY_SHORT = [
        1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN',
    ];

    // ─────────────────────────────────────────────────────────
    // Reference data
    // ─────────────────────────────────────────────────────────

    public static function getSemesters(): array {
        $pdo = get_db();
        return $pdo->query(
            'SELECT semester_id, semester_name, academic_year, start_date, end_date, status
             FROM Semester ORDER BY start_date DESC'
        )->fetchAll();
    }

    public static function getSections(int $semesterId, ?int $courseId = null): array {
        $pdo = get_db();
        $sql = 'SELECT cs.section_id, cs.section_code, cs.course_id, cs.semester_id,
                       cs.lecturer_id, cs.room, cs.schedule, cs.capacity,
                       c.course_code, c.course_name, c.department_id,
                       d.department_code, d.department_name,
                       l.full_name AS lecturer_name, l.email AS lecturer_email,
                       sem.semester_name, sem.academic_year,
                       sem.start_date, sem.end_date,
                       (SELECT COUNT(*) FROM Enrollment e
                         WHERE e.section_id = cs.section_id
                           AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count,
                       ts.schedule_id, ts.day_of_week, ts.start_time AS schedule_start,
                       ts.end_time AS schedule_end, ts.room AS schedule_room,
                       ts.effective_start, ts.effective_end, ts.status AS schedule_status
                FROM ClassSection cs
                JOIN Course c ON c.course_id = cs.course_id
                JOIN Semester sem ON sem.semester_id = cs.semester_id
                LEFT JOIN Department d ON d.department_id = c.department_id
                JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
                LEFT JOIN TeachingSchedule ts ON ts.section_id = cs.section_id
                WHERE cs.semester_id = :sem';
        $params = [':sem' => $semesterId];
        if ($courseId) {
            $sql .= ' AND cs.course_id = :cid';
            $params[':cid'] = $courseId;
        }
        $sql .= ' ORDER BY c.course_code ASC, cs.section_code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getCourses(?int $semesterId = null): array {
        $pdo = get_db();
        if ($semesterId) {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT c.course_id, c.course_code, c.course_name
                 FROM Course c
                 JOIN ClassSection cs ON cs.course_id = c.course_id
                 WHERE cs.semester_id = :sem
                 ORDER BY c.course_code ASC'
            );
            $stmt->execute([':sem' => $semesterId]);
            return $stmt->fetchAll();
        }
        return $pdo->query(
            'SELECT course_id, course_code, course_name FROM Course WHERE is_active = TRUE ORDER BY course_code ASC'
        )->fetchAll();
    }

    public static function getLecturers(): array {
        $pdo = get_db();
        return $pdo->query(
            'SELECT l.lecturer_id, l.full_name, l.email, l.status
             FROM Lecturer l ORDER BY l.full_name ASC'
        )->fetchAll();
    }

    public static function getSchedules(?int $semesterId = null, ?int $courseId = null, ?int $lecturerId = null): array {
        $pdo = get_db();
        $sql = 'SELECT ts.*, cs.section_code, cs.course_id, cs.semester_id AS section_semester,
                       c.course_code, c.course_name,
                       d.department_code, d.department_name,
                       sem.semester_name, sem.academic_year, sem.start_date AS sem_start, sem.end_date AS sem_end,
                       l.full_name AS lecturer_name,
                       (SELECT COUNT(*) FROM AttendanceSession ats WHERE ats.schedule_id = ts.schedule_id) AS session_count,
                       (SELECT COUNT(*) FROM AttendanceSession ats2
                         WHERE ats2.schedule_id = ts.schedule_id AND ats2.session_date >= CURDATE()
                           AND ats2.status = \'Scheduled\') AS upcoming_count,
                       (SELECT COUNT(*) FROM Enrollment e
                         WHERE e.section_id = cs.section_id AND e.status IN (\'Enrolled\',\'Completed\')) AS enrolled_count
                FROM TeachingSchedule ts
                JOIN ClassSection cs ON cs.section_id = ts.section_id
                JOIN Course c ON c.course_id = cs.course_id
                JOIN Semester sem ON sem.semester_id = ts.semester_id
                LEFT JOIN Department d ON d.department_id = c.department_id
                JOIN Lecturer l ON l.lecturer_id = ts.lecturer_id
                WHERE 1=1';
        $params = [];
        if ($semesterId) { $sql .= ' AND ts.semester_id = :sem'; $params[':sem'] = $semesterId; }
        if ($courseId)   { $sql .= ' AND cs.course_id = :cid';   $params[':cid'] = $courseId; }
        if ($lecturerId) { $sql .= ' AND ts.lecturer_id = :lid'; $params[':lid'] = $lecturerId; }
        $sql .= ' ORDER BY sem.start_date DESC, c.course_code ASC, cs.section_code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getScheduleBySection(int $sectionId): array|false {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT ts.*, cs.section_code, cs.course_id, cs.semester_id,
                    c.course_code, c.course_name, l.full_name AS lecturer_name
             FROM TeachingSchedule ts
             JOIN ClassSection cs ON cs.section_id = ts.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = ts.lecturer_id
             WHERE ts.section_id = :sid LIMIT 1'
        );
        $stmt->execute([':sid' => $sectionId]);
        return $stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────
    // Save schedule + generate sessions
    // ─────────────────────────────────────────────────────────

    public static function saveSchedule(array $in, int $adminAccountId): array {
        $pdo = get_db();

        $sectionId = (int)($in['section_id'] ?? 0);
        $lecturerId = (int)($in['lecturer_id'] ?? 0);
        $day = (int)($in['day_of_week'] ?? 0);
        $start = trim((string)($in['start_time'] ?? ''));
        $end = trim((string)($in['end_time'] ?? ''));
        $room = trim((string)($in['room'] ?? ''));
        $effStart = trim((string)($in['effective_start'] ?? ''));
        $effEnd = trim((string)($in['effective_end'] ?? ''));

        if ($sectionId <= 0) return ['ok' => false, 'message' => 'Vui lòng chọn lớp học phần.'];
        if ($lecturerId <= 0) return ['ok' => false, 'message' => 'Vui lòng chọn giảng viên phụ trách.'];
        if ($day < 1 || $day > 7) return ['ok' => false, 'message' => 'Ngày dạy không hợp lệ.'];
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            return ['ok' => false, 'message' => 'Giờ bắt đầu/kết thúc không hợp lệ.'];
        }
        if (strtotime($end) <= strtotime($start)) {
            return ['ok' => false, 'message' => 'Giờ kết thúc phải sau giờ bắt đầu.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effEnd)) {
            return ['ok' => false, 'message' => 'Ngày bắt đầu/kết thúc không hợp lệ.'];
        }
        if ($effEnd < $effStart) {
            return ['ok' => false, 'message' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'];
        }
        $room = substr($room, 0, 30);
        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;

        $secStmt = $pdo->prepare(
            'SELECT cs.section_id, cs.course_id, cs.semester_id, cs.lecturer_id,
                    cs.section_code, sem.start_date, sem.end_date
             FROM ClassSection cs
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             WHERE cs.section_id = :sid LIMIT 1'
        );
        $secStmt->execute([':sid' => $sectionId]);
        $section = $secStmt->fetch();
        if (!$section) return ['ok' => false, 'message' => 'Lớp học phần không tồn tại.'];

        if ($effStart < $section['start_date'] || $effEnd > $section['end_date']) {
            return ['ok' => false, 'message' => 'Khoảng ngày dạy phải nằm trong thời gian của học kỳ (' .
                date('d/m/Y', strtotime($section['start_date'])) . ' – ' .
                date('d/m/Y', strtotime($section['end_date'])) . ').'];
        }

        $lecStmt = $pdo->prepare('SELECT lecturer_id, full_name FROM Lecturer WHERE lecturer_id = :lid LIMIT 1');
        $lecStmt->execute([':lid' => $lecturerId]);
        $lecturer = $lecStmt->fetch();
        if (!$lecturer) return ['ok' => false, 'message' => 'Giảng viên không tồn tại.'];

        try {
            $pdo->beginTransaction();

            // Admin's schedule form is also the authoritative lecturer assignment.
            $oldLecturer = (int)$section['lecturer_id'];
            if ($oldLecturer !== $lecturerId) {
                $pdo->prepare('UPDATE ClassSection SET lecturer_id = :lid WHERE section_id = :sid')
                    ->execute([':lid' => $lecturerId, ':sid' => $sectionId]);
                AcademicAlertService::writeAuditLog(
                    $pdo, $adminAccountId, 'ASSIGN_LECTURER', 'ClassSection', (string)$sectionId,
                    json_encode(['lecturer_id' => $oldLecturer], JSON_UNESCAPED_UNICODE),
                    json_encode(['lecturer_id' => $lecturerId, 'via' => 'teaching_schedule'], JSON_UNESCAPED_UNICODE)
                );
            }

            $existing = self::getScheduleBySection($sectionId);
            if ($existing) {
                $pdo->prepare(
                    'UPDATE TeachingSchedule
                     SET semester_id = :sem, lecturer_id = :lid, day_of_week = :dow,
                         start_time = :st, end_time = :en, room = :room,
                         effective_start = :es, effective_end = :ee, status = \'Active\'
                     WHERE schedule_id = :id'
                )->execute([
                    ':sem' => (int)$section['semester_id'], ':lid' => $lecturerId, ':dow' => $day,
                    ':st' => $start, ':en' => $end, ':room' => ($room === '' ? null : $room),
                    ':es' => $effStart, ':ee' => $effEnd, ':id' => (int)$existing['schedule_id'],
                ]);
                $scheduleId = (int)$existing['schedule_id'];
                $action = 'UPDATE_SCHEDULE';
            } else {
                $pdo->prepare(
                    'INSERT INTO TeachingSchedule
                        (section_id, semester_id, lecturer_id, day_of_week, start_time, end_time,
                         room, effective_start, effective_end, status, created_by)
                     VALUES (:sid, :sem, :lid, :dow, :st, :en, :room, :es, :ee, \'Active\', :by)'
                )->execute([
                    ':sid' => $sectionId, ':sem' => (int)$section['semester_id'], ':lid' => $lecturerId,
                    ':dow' => $day, ':st' => $start, ':en' => $end,
                    ':room' => ($room === '' ? null : $room), ':es' => $effStart, ':ee' => $effEnd,
                    ':by' => $adminAccountId,
                ]);
                $scheduleId = (int)$pdo->lastInsertId();
                $action = 'CREATE_SCHEDULE';
            }

            $schedule = self::getScheduleBySection($sectionId);
            $result = self::syncSessions($schedule);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, $action, 'TeachingSchedule', (string)$scheduleId,
                null,
                json_encode([
                    'section_id' => $sectionId,
                    'lecturer_id' => $lecturerId,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'room' => $room,
                    'effective_start' => $effStart,
                    'effective_end' => $effEnd,
                    'generated' => $result['inserted'],
                    'updated' => $result['updated'],
                    'removed' => $result['removed'],
                ], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Đã lưu lịch giảng dạy: tạo mới ' . $result['inserted'] .
                    ' buổi, cập nhật ' . $result['updated'] .
                    ($result['removed'] ? ', gỡ ' . $result['removed'] . ' buổi chưa diễn ra' : '') . '.',
                'schedule_id' => $scheduleId,
                'sessions' => $result,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Không thể lưu lịch: ' . $e->getMessage()];
        }
    }

    /**
     * Generate missing sessions for every matching weekday in the effective
     * range. Future generated sessions that no longer match the new weekly
     * pattern are removed only when they have no attendance and no requests.
     * Past sessions and any session with attendance are never touched.
     */
    public static function syncSessions(array $schedule): array {
        $pdo = get_db();
        $dates = self::targetDates($schedule);
        $dateSet = array_fill_keys($dates, true);
        $start = strlen($schedule['start_time']) === 5 ? $schedule['start_time'] . ':00' : $schedule['start_time'];
        $end = strlen($schedule['end_time']) === 5 ? $schedule['end_time'] . ':00' : $schedule['end_time'];
        $startHm = substr($start, 0, 5);

        $find = $pdo->prepare(
            'SELECT session_id, schedule_id, status, session_date, start_time
             FROM AttendanceSession
             WHERE section_id = :sid AND session_date = :d AND start_time = :st LIMIT 1'
        );
        $attCount = $pdo->prepare('SELECT COUNT(*) FROM Attendance WHERE session_id = :sid');
        $reqCount = $pdo->prepare('SELECT COUNT(*) FROM ScheduleChangeRequest WHERE session_id = :sid');
        $insert = $pdo->prepare(
            'INSERT INTO AttendanceSession
                (section_id, schedule_id, lecturer_id, session_date, start_time, end_time,
                 room, topic, session_type, status)
             VALUES (:sid, :tsid, :lid, :d, :st, :en, :room, NULL, \'Regular\', \'Scheduled\')'
        );
        $updateMeta = $pdo->prepare(
            'UPDATE AttendanceSession
             SET schedule_id = :tsid, lecturer_id = :lid, room = :room
             WHERE session_id = :sid'
        );

        $inserted = 0; $updated = 0;
        $today = date('Y-m-d');

        foreach ($dates as $date) {
            $find->execute([':sid' => (int)$schedule['section_id'], ':d' => $date, ':st' => $start]);
            $row = $find->fetch();

            if ($row) {
                $attCount->execute([':sid' => (int)$row['session_id']]);
                $hasAtt = (int)$attCount->fetchColumn() > 0;
                // Update schedule metadata only for sessions that have no
                // recorded attendance (past records stay intact).
                if (!$hasAtt && $date >= $today) {
                    $updateMeta->execute([
                        ':tsid' => (int)$schedule['schedule_id'],
                        ':lid' => (int)$schedule['lecturer_id'],
                        ':room' => $schedule['room'],
                        ':sid' => (int)$row['session_id'],
                    ]);
                    $updated++;
                }
                continue;
            }

            $insert->execute([
                ':sid' => (int)$schedule['section_id'],
                ':tsid' => (int)$schedule['schedule_id'],
                ':lid' => (int)$schedule['lecturer_id'],
                ':d' => $date,
                ':st' => $start,
                ':en' => $end,
                ':room' => $schedule['room'],
            ]);
            $inserted++;
        }

        // Remove stale future generated sessions (safe cleanup only).
        $future = $pdo->prepare(
            'SELECT session_id, session_date, start_time, status
             FROM AttendanceSession
             WHERE schedule_id = :tsid AND session_date > CURDATE()'
        );
        $future->execute([':tsid' => (int)$schedule['schedule_id']]);
        $removed = 0;
        foreach ($future->fetchAll() as $row) {
            $hm = substr($row['start_time'], 0, 5);
            $matches = isset($dateSet[$row['session_date']]) && $hm === $startHm;
            if ($matches) continue;
            if ($row['status'] === 'Cancelled') continue;
            $attCount->execute([':sid' => (int)$row['session_id']]);
            if ((int)$attCount->fetchColumn() > 0) continue;
            $reqCount->execute([':sid' => (int)$row['session_id']]);
            if ((int)$reqCount->fetchColumn() > 0) continue;

            $pdo->prepare('DELETE FROM AttendanceSession WHERE session_id = :sid')
                ->execute([':sid' => (int)$row['session_id']]);
            $removed++;
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'removed' => $removed, 'dates' => $dates];
    }

    private static function targetDates(array $schedule): array {
        $dates = [];
        $start = new DateTimeImmutable($schedule['effective_start']);
        $end = new DateTimeImmutable($schedule['effective_end']);
        $targetDow = (int)$schedule['day_of_week'];
        $offset = ($targetDow - (int)$start->format('N') + 7) % 7;
        $d = $start->modify('+' . $offset . ' days');
        while ($d <= $end) {
            $dates[] = $d->format('Y-m-d');
            $d = $d->modify('+7 days');
        }
        return $dates;
    }

    // ─────────────────────────────────────────────────────────
    // Lecturer sessions
    // ─────────────────────────────────────────────────────────

    public static function getLecturerSessions(int $lecturerId, int $semesterId, ?int $sectionId = null): array {
        $pdo = get_db();
        $sql = 'SELECT ats.session_id, ats.section_id, ats.session_date, ats.start_time, ats.end_time,
                       ats.room, ats.topic, ats.session_type, ats.status, ats.cancelled_at,
                       ats.original_session_id,
                       cs.section_code, cs.course_id,
                       c.course_code, c.course_name,
                       COALESCE(ats.lecturer_id, cs.lecturer_id) AS lecturer_id,
                       l.full_name AS lecturer_name,
                       (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = ats.session_id) AS recorded_count,
                       (SELECT COUNT(*) FROM Enrollment e
                         WHERE e.section_id = ats.section_id
                           AND e.status IN (\'Enrolled\',\'Completed\')) AS total_students,
                       (SELECT COUNT(*) FROM ScheduleChangeRequest r
                         WHERE r.session_id = ats.session_id AND r.request_type = \'Cancellation\'
                           AND r.status = \'Pending\') AS pending_cancel,
                       (SELECT COUNT(*) FROM ScheduleChangeRequest r2
                         WHERE r2.session_id = ats.session_id AND r2.request_type = \'Makeup\'
                           AND r2.status = \'Pending\') AS pending_makeup,
                       (SELECT COUNT(*) FROM ScheduleChangeRequest r3
                         WHERE r3.session_id = ats.session_id AND r3.request_type = \'Cancellation\'
                           AND r3.status = \'Approved\') AS approved_cancel
                FROM AttendanceSession ats
                JOIN ClassSection cs ON cs.section_id = ats.section_id
                JOIN Course c ON c.course_id = cs.course_id
                LEFT JOIN Lecturer l ON l.lecturer_id = COALESCE(ats.lecturer_id, cs.lecturer_id)
                WHERE cs.lecturer_id = :lid AND cs.semester_id = :sem';
        $params = [':lid' => $lecturerId, ':sem' => $semesterId];
        if ($sectionId) { $sql .= ' AND ats.section_id = :sid'; $params[':sid'] = $sectionId; }
        $sql .= ' ORDER BY ats.session_date ASC, ats.start_time ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getLecturerSession(int $sessionId, int $lecturerId): array|false {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.*, cs.section_code, cs.course_id, c.course_code, c.course_name,
                    cs.semester_id, sem.start_date, sem.end_date,
                    COALESCE(ats.lecturer_id, cs.lecturer_id) AS assigned_lecturer_id
             FROM AttendanceSession ats
             JOIN ClassSection cs ON cs.section_id = ats.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             WHERE ats.session_id = :sess AND cs.lecturer_id = :lid LIMIT 1'
        );
        $stmt->execute([':sess' => $sessionId, ':lid' => $lecturerId]);
        return $stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────
    // Request workflow
    // ─────────────────────────────────────────────────────────

    public static function createCancellationRequest(int $sessionId, int $lecturerId, int $accountId, string $reason): array {
        $pdo = get_db();
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return ['ok' => false, 'message' => 'Vui lòng nhập lý do xin nghỉ (ít nhất 5 ký tự).'];
        }
        $session = self::getLecturerSession($sessionId, $lecturerId);
        if (!$session) return ['ok' => false, 'message' => 'Buổi học không hợp lệ hoặc bạn không phụ trách lớp này.'];
        if ($session['status'] === 'Cancelled') return ['ok' => false, 'message' => 'Buổi học đã được hủy.'];
        if ($session['session_date'] < date('Y-m-d')) {
            return ['ok' => false, 'message' => 'Chỉ có thể đề xuất nghỉ cho buổi học hôm nay hoặc trong tương lai.'];
        }

        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM ScheduleChangeRequest
             WHERE session_id = :sid AND request_type = \'Cancellation\'
               AND status IN (\'Pending\',\'Approved\')'
        );
        $dup->execute([':sid' => $sessionId]);
        if ((int)$dup->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Buổi học này đã có đề xuất nghỉ đang chờ duyệt hoặc đã được duyệt.'];
        }

        $pdo->prepare(
            'INSERT INTO ScheduleChangeRequest
                (request_type, session_id, section_id, lecturer_id, requested_by, reason)
             VALUES (\'Cancellation\', :sess, :sec, :lid, :by, :reason)'
        )->execute([
            ':sess' => $sessionId, ':sec' => (int)$session['section_id'],
            ':lid' => $lecturerId, ':by' => $accountId, ':reason' => $reason,
        ]);
        $requestId = (int)$pdo->lastInsertId();

        AcademicAlertService::writeAuditLog(
            $pdo, $accountId, 'REQUEST_CANCEL_SESSION', 'ScheduleChangeRequest', (string)$requestId,
            null, json_encode(['session_id' => $sessionId, 'reason' => $reason], JSON_UNESCAPED_UNICODE)
        );

        self::notifyAdmins(
            'Đề xuất nghỉ buổi học',
            $session['course_code'] . ' – ' . $session['section_code'] . ' • ' .
                date('d/m/Y', strtotime($session['session_date'])) . '. Lý do: ' . $reason,
            '/UniPortal/public/assets/admin/schedule_requests.php'
        );

        return ['ok' => true, 'message' => 'Đã gửi đề xuất nghỉ buổi học. Lịch học chính thức chưa thay đổi cho đến khi Admin duyệt.'];
    }

    public static function createMakeupRequest(
        int $sessionId, int $lecturerId, int $accountId,
        string $date, string $start, string $end, string $room, string $reason
    ): array {
        $pdo = get_db();
        $reason = trim($reason);
        $room = substr(trim($room), 0, 30);

        if (mb_strlen($reason) < 5) return ['ok' => false, 'message' => 'Vui lòng nhập lý do học bù (ít nhất 5 ký tự).'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'message' => 'Ngày học bù không hợp lệ.'];
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            return ['ok' => false, 'message' => 'Giờ học bù không hợp lệ.'];
        }
        if (strtotime($end) <= strtotime($start)) return ['ok' => false, 'message' => 'Giờ kết thúc phải sau giờ bắt đầu.'];
        if ($date < date('Y-m-d')) return ['ok' => false, 'message' => 'Ngày học bù không được ở quá khứ.'];

        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;

        $session = self::getLecturerSession($sessionId, $lecturerId);
        if (!$session) return ['ok' => false, 'message' => 'Buổi học không hợp lệ hoặc bạn không phụ trách lớp này.'];
        if ($date < $session['start_date'] || $date > $session['end_date']) {
            return ['ok' => false, 'message' => 'Ngày học bù phải nằm trong học kỳ ' .
                date('d/m/Y', strtotime($session['start_date'])) . ' – ' .
                date('d/m/Y', strtotime($session['end_date'])) . '.'];
        }

        // Make-up is only for a cancelled session or one awaiting/with an
        // approved cancellation ("expected to be cancelled").
        $cancelStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ScheduleChangeRequest
             WHERE session_id = :sid AND request_type = \'Cancellation\'
               AND status IN (\'Pending\',\'Approved\')'
        );
        $cancelStmt->execute([':sid' => $sessionId]);
        $hasCancel = (int)$cancelStmt->fetchColumn() > 0;
        if ($session['status'] !== 'Cancelled' && !$hasCancel) {
            return ['ok' => false, 'message' => 'Chỉ có thể đề xuất học bù cho buổi đã hủy hoặc đang chờ duyệt hủy.'];
        }

        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM ScheduleChangeRequest
             WHERE session_id = :sid AND request_type = \'Makeup\' AND status = \'Pending\''
        );
        $dup->execute([':sid' => $sessionId]);
        if ((int)$dup->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Buổi học này đã có đề xuất học bù đang chờ duyệt.'];
        }

        $clash = $pdo->prepare(
            'SELECT COUNT(*) FROM AttendanceSession
             WHERE section_id = :sec AND session_date = :d AND start_time = :st'
        );
        $clash->execute([':sec' => (int)$session['section_id'], ':d' => $date, ':st' => $start]);
        if ((int)$clash->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Đã có buổi học khác của lớp này trùng ngày và giờ bắt đầu.'];
        }

        $pdo->prepare(
            'INSERT INTO ScheduleChangeRequest
                (request_type, session_id, section_id, lecturer_id, requested_by, reason,
                 proposed_date, proposed_start_time, proposed_end_time, proposed_room)
             VALUES (\'Makeup\', :sess, :sec, :lid, :by, :reason, :d, :st, :en, :room)'
        )->execute([
            ':sess' => $sessionId, ':sec' => (int)$session['section_id'],
            ':lid' => $lecturerId, ':by' => $accountId, ':reason' => $reason,
            ':d' => $date, ':st' => $start, ':en' => $end, ':room' => ($room === '' ? null : $room),
        ]);
        $requestId = (int)$pdo->lastInsertId();

        AcademicAlertService::writeAuditLog(
            $pdo, $accountId, 'REQUEST_MAKEUP_SESSION', 'ScheduleChangeRequest', (string)$requestId,
            null, json_encode([
                'session_id' => $sessionId, 'proposed_date' => $date,
                'proposed_start' => $start, 'proposed_end' => $end, 'room' => $room,
            ], JSON_UNESCAPED_UNICODE)
        );

        self::notifyAdmins(
            'Đề xuất học bù',
            $session['course_code'] . ' – ' . $session['section_code'] . ' • ' .
                date('d/m/Y', strtotime($date)) . ' ' . substr($start, 0, 5) . '–' . substr($end, 0, 5) .
                '. Lý do: ' . $reason,
            '/UniPortal/public/assets/admin/schedule_requests.php'
        );

        return ['ok' => true, 'message' => 'Đã gửi đề xuất học bù. Buổi học bù chỉ được xếp lịch sau khi Admin duyệt.'];
    }

    public static function getRequests(array $filters = []): array {
        $pdo = get_db();
        $sql = 'SELECT r.*, l.full_name AS lecturer_name,
                       c.course_code, c.course_name, cs.section_code, cs.semester_id,
                       sem.semester_name, sem.academic_year,
                       ats.session_date AS original_date, ats.start_time AS original_start,
                       ats.end_time AS original_end, ats.status AS original_status,
                       ats.session_type AS original_type,
                       (SELECT COUNT(*) FROM Attendance a WHERE a.session_id = r.session_id) AS recorded_count,
                       ra.username AS reviewer_name,
                       ca.section_id AS created_section_id
                FROM ScheduleChangeRequest r
                JOIN Lecturer l ON l.lecturer_id = r.lecturer_id
                JOIN ClassSection cs ON cs.section_id = r.section_id
                JOIN Course c ON c.course_id = cs.course_id
                JOIN Semester sem ON sem.semester_id = cs.semester_id
                JOIN AttendanceSession ats ON ats.session_id = r.session_id
                LEFT JOIN Account ra ON ra.account_id = r.reviewed_by
                LEFT JOIN AttendanceSession ca ON ca.session_id = r.created_session_id
                WHERE 1=1';
        $params = [];
        $status = $filters['status'] ?? '';
        if (in_array($status, ['Pending','Approved','Rejected'], true)) {
            $sql .= ' AND r.status = :status'; $params[':status'] = $status;
        }
        $type = $filters['type'] ?? '';
        if (in_array($type, ['Cancellation','Makeup'], true)) {
            $sql .= ' AND r.request_type = :type'; $params[':type'] = $type;
        }
        $semId = (int)($filters['semester_id'] ?? 0);
        if ($semId > 0) { $sql .= ' AND cs.semester_id = :sem'; $params[':sem'] = $semId; }
        $lecId = (int)($filters['lecturer_id'] ?? 0);
        if ($lecId > 0) { $sql .= ' AND r.lecturer_id = :lid'; $params[':lid'] = $lecId; }
        $sql .= ' ORDER BY CASE r.status WHEN \'Pending\' THEN 0 WHEN \'Approved\' THEN 1 ELSE 2 END,
                  r.created_at DESC, r.request_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getLecturerRequests(int $lecturerId): array {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT r.*, c.course_code, c.course_name, cs.section_code,
                    ats.session_date AS original_date, ats.start_time AS original_start,
                    ats.end_time AS original_end, ats.status AS original_status,
                    ats.session_type AS original_type
             FROM ScheduleChangeRequest r
             JOIN ClassSection cs ON cs.section_id = r.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN AttendanceSession ats ON ats.session_id = r.session_id
             WHERE r.lecturer_id = :lid
             ORDER BY CASE r.status WHEN \'Pending\' THEN 0 ELSE 1 END,
                      r.created_at DESC, r.request_id DESC'
        );
        $stmt->execute([':lid' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public static function reviewRequest(int $requestId, int $adminAccountId, string $decision, string $note): array {
        if (!in_array($decision, ['Approved','Rejected'], true)) {
            return ['ok' => false, 'message' => 'Quyết định không hợp lệ.'];
        }
        $note = trim($note);
        if ($decision === 'Rejected' && $note === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập lý do từ chối để lưu vào hồ sơ.'];
        }

        $pdo = get_db();
        try {
            $pdo->beginTransaction();

            $q = $pdo->prepare(
                'SELECT r.*, ats.session_date, ats.start_time, ats.end_time, ats.status AS session_status,
                        ats.session_type, cs.section_code, cs.lecturer_id AS section_lecturer,
                        c.course_code, c.course_name
                 FROM ScheduleChangeRequest r
                 JOIN AttendanceSession ats ON ats.session_id = r.session_id
                 JOIN ClassSection cs ON cs.section_id = r.section_id
                 JOIN Course c ON c.course_id = cs.course_id
                 WHERE r.request_id = :rid FOR UPDATE'
            );
            $q->execute([':rid' => $requestId]);
            $req = $q->fetch();
            if (!$req) { $pdo->rollBack(); return ['ok' => false, 'message' => 'Đề xuất không tồn tại.']; }
            if ($req['status'] !== 'Pending') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Đề xuất này đã được xử lý (' . $req['status'] . ').'];
            }

            $createdSessionId = null;

            if ($decision === 'Approved' && $req['request_type'] === 'Cancellation') {
                if ($req['session_status'] === 'Cancelled') {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Buổi học đã ở trạng thái đã hủy.'];
                }
                $pdo->prepare(
                    'UPDATE AttendanceSession SET status = \'Cancelled\', cancelled_at = NOW()
                     WHERE session_id = :sid'
                )->execute([':sid' => (int)$req['session_id']]);
            }

            if ($decision === 'Approved' && $req['request_type'] === 'Makeup') {
                $dup = $pdo->prepare(
                    'SELECT COUNT(*) FROM AttendanceSession
                     WHERE section_id = :sec AND session_date = :d AND start_time = :st'
                );
                $dup->execute([
                    ':sec' => (int)$req['section_id'], ':d' => $req['proposed_date'],
                    ':st' => $req['proposed_start_time'],
                ]);
                if ((int)$dup->fetchColumn() > 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Không thể duyệt: đã có buổi học trùng ngày và giờ của lớp này.'];
                }
                $pdo->prepare(
                    'INSERT INTO AttendanceSession
                        (section_id, schedule_id, lecturer_id, session_date, start_time, end_time,
                         room, topic, session_type, status, original_session_id)
                     VALUES (:sec, NULL, :lid, :d, :st, :en, :room, :topic, \'Makeup\', \'Scheduled\', :orig)'
                )->execute([
                    ':sec' => (int)$req['section_id'], ':lid' => (int)$req['lecturer_id'],
                    ':d' => $req['proposed_date'], ':st' => $req['proposed_start_time'],
                    ':en' => $req['proposed_end_time'], ':room' => $req['proposed_room'],
                    ':topic' => 'Buổi học bù', ':orig' => (int)$req['session_id'],
                ]);
                $createdSessionId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare(
                'UPDATE ScheduleChangeRequest
                 SET status = :st, admin_note = :note, reviewed_by = :by, reviewed_at = NOW(),
                     created_session_id = :created
                 WHERE request_id = :rid'
            )->execute([
                ':st' => $decision, ':note' => ($note === '' ? null : $note),
                ':by' => $adminAccountId, ':created' => $createdSessionId, ':rid' => $requestId,
            ]);

            AcademicAlertService::writeAuditLog(
                $pdo, $adminAccountId, strtoupper($decision) . '_SCHEDULE_REQUEST',
                'ScheduleChangeRequest', (string)$requestId, null,
                json_encode([
                    'type' => $req['request_type'], 'status' => $decision,
                    'note' => $note, 'created_session_id' => $createdSessionId,
                ], JSON_UNESCAPED_UNICODE)
            );

            $pdo->commit();

            // Notifications outside the transaction (best-effort).
            self::notifyRequestOutcome($req, $decision, $note, $createdSessionId);

            $msg = $decision === 'Approved'
                ? ($req['request_type'] === 'Cancellation'
                    ? 'Đã duyệt nghỉ buổi học. Buổi học được đánh dấu đã hủy và vẫn lưu lịch sử.'
                    : 'Đã duyệt học bù. Buổi học bù đã được thêm vào lịch chính thức.')
                : 'Đã từ chối đề xuất. Lịch học chính thức được giữ nguyên.';
            return ['ok' => true, 'message' => $msg, 'created_session_id' => $createdSessionId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'Không thể xử lý đề xuất: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────
    // Student timetable
    // ─────────────────────────────────────────────────────────

    public static function getStudentTimetable(string $studentId, int $semesterId): array {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT ats.session_id, ats.session_date, ats.start_time, ats.end_time,
                    COALESCE(ats.room, cs.room) AS room,
                    ats.session_type, ats.status, ats.cancelled_at, ats.original_session_id,
                    cs.section_id, cs.section_code, cs.schedule, cs.section_status,
                    c.course_code, c.course_name, c.credits,
                    l.full_name AS lecturer_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             JOIN AttendanceSession ats ON ats.section_id = cs.section_id
             LEFT JOIN Lecturer l ON l.lecturer_id = COALESCE(ats.lecturer_id, cs.lecturer_id)
             WHERE e.student_id = :sid AND e.status IN (\'Enrolled\',\'Completed\')
               AND cs.semester_id = :sem
             ORDER BY ats.session_date ASC, ats.start_time ASC'
        );
        $stmt->execute([':sid' => $studentId, ':sem' => $semesterId]);
        $sessions = $stmt->fetchAll();

        // Sections registered but not yet generated into attendance sessions
        // (new offerings) still belong in the student's timetable.
        require_once __DIR__ . '/../services/RegistrationService.php';
        $missing = $pdo->prepare(
            "SELECT cs.section_id, cs.section_code, cs.schedule, cs.study_days,
                    cs.start_time, cs.end_time, cs.room, cs.section_status,
                    c.course_code, c.course_name, c.credits,
                    l.full_name AS lecturer_name,
                    sem.start_date, sem.end_date
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Semester sem ON sem.semester_id = cs.semester_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE e.student_id = :sid AND e.status IN ('Enrolled','Completed')
               AND cs.semester_id = :sem
               AND NOT EXISTS (SELECT 1 FROM AttendanceSession ats WHERE ats.section_id = cs.section_id)
             ORDER BY c.course_code"
        );
        $missing->execute([':sid' => $studentId, ':sem' => $semesterId]);

        $syntheticId = -1;
        foreach ($missing->fetchAll() as $sec) {
            $slots = RegistrationService::sectionSlots($sec);
            if (empty($slots)) continue;

            $start = new DateTimeImmutable($sec['start_date']);
            $end   = new DateTimeImmutable($sec['end_date']);
            $byDay = [];
            foreach ($slots as $slot) $byDay[(int)$slot['day']][] = $slot;

            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $dow = (int)$d->format('N');
                if (empty($byDay[$dow])) continue;
                foreach ($byDay[$dow] as $slot) {
                    $sessions[] = [
                        'session_id'          => $syntheticId--,
                        'session_date'        => $d->format('Y-m-d'),
                        'start_time'          => $slot['start'],
                        'end_time'            => $slot['end'],
                        'room'                => $sec['room'],
                        'session_type'        => 'Regular',
                        'status'              => $sec['section_status'] === 'Confirmed' ? 'Scheduled' : 'Pending',
                        'cancelled_at'        => null,
                        'original_session_id' => null,
                        'section_id'          => (int)$sec['section_id'],
                        'section_code'        => $sec['section_code'],
                        'schedule'            => $sec['schedule'],
                        'section_status'      => $sec['section_status'],
                        'course_code'         => $sec['course_code'],
                        'course_name'         => $sec['course_name'],
                        'credits'             => $sec['credits'],
                        'lecturer_name'       => $sec['lecturer_name'],
                    ];
                }
            }
        }

        usort($sessions, static fn($a, $b) =>
            [$a['session_date'], $a['start_time']] <=> [$b['session_date'], $b['start_time']]
        );
        return $sessions;
    }

    public static function getStudentWeeklyPattern(string $studentId, int $semesterId): array {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT ts.day_of_week, ts.start_time, ts.end_time, ts.room,
                    ts.section_id, ts.status AS schedule_status,
                    cs.section_code, cs.section_status,
                    c.course_code, c.course_name,
                    l.full_name AS lecturer_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN TeachingSchedule ts ON ts.section_id = cs.section_id AND ts.status = \'Active\'
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = ts.lecturer_id
             WHERE e.student_id = :sid AND e.status IN (\'Enrolled\',\'Completed\')
               AND cs.semester_id = :sem
             ORDER BY ts.day_of_week ASC, ts.start_time ASC'
        );
        $stmt->execute([':sid' => $studentId, ':sem' => $semesterId]);
        $pattern = $stmt->fetchAll();

        require_once __DIR__ . '/../services/RegistrationService.php';
        $missing = $pdo->prepare(
            "SELECT cs.section_id, cs.section_code, cs.schedule, cs.study_days,
                    cs.start_time, cs.end_time, cs.room, cs.section_status,
                    c.course_code, c.course_name,
                    l.full_name AS lecturer_name
             FROM Enrollment e
             JOIN ClassSection cs ON cs.section_id = e.section_id
             JOIN Course c ON c.course_id = cs.course_id
             JOIN Lecturer l ON l.lecturer_id = cs.lecturer_id
             WHERE e.student_id = :sid AND e.status IN ('Enrolled','Completed')
               AND cs.semester_id = :sem
               AND NOT EXISTS (
                    SELECT 1 FROM TeachingSchedule ts
                    WHERE ts.section_id = cs.section_id AND ts.status = 'Active'
               )
             ORDER BY c.course_code"
        );
        $missing->execute([':sid' => $studentId, ':sem' => $semesterId]);

        foreach ($missing->fetchAll() as $sec) {
            foreach (RegistrationService::sectionSlots($sec) as $slot) {
                $pattern[] = [
                    'day_of_week'     => $slot['day'],
                    'start_time'      => $slot['start'],
                    'end_time'        => $slot['end'],
                    'room'            => $sec['room'],
                    'section_id'      => (int)$sec['section_id'],
                    'schedule_status' => 'Active',
                    'section_code'    => $sec['section_code'],
                    'section_status'  => $sec['section_status'],
                    'course_code'     => $sec['course_code'],
                    'course_name'     => $sec['course_name'],
                    'lecturer_name'   => $sec['lecturer_name'],
                ];
            }
        }

        usort($pattern, static fn($a, $b) =>
            [(int)$a['day_of_week'], $a['start_time']] <=> [(int)$b['day_of_week'], $b['start_time']]
        );
        return $pattern;
    }

    // ─────────────────────────────────────────────────────────
    // Notifications
    // ─────────────────────────────────────────────────────────

    private static function notifyAdmins(string $title, string $body, string $link): void {
        $pdo = get_db();
        $stmt = $pdo->query(
            'SELECT account_id FROM Account WHERE role = \'AcademicAffairs\' AND is_active = TRUE'
        );
        foreach ($stmt->fetchAll() as $row) {
            NotificationService::send((int)$row['account_id'], 'Schedule', $title, $body, $link);
        }
    }

    private static function notifyRequestOutcome(array $req, string $decision, string $note, ?int $createdSessionId): void {
        $pdo = get_db();

        $lecAcc = $pdo->prepare('SELECT account_id FROM Account WHERE lecturer_id = :lid AND is_active = TRUE LIMIT 1');
        $lecAcc->execute([':lid' => (int)$req['lecturer_id']]);
        $lecAccountId = (int)($lecAcc->fetchColumn() ?: 0);

        $labels = ['Cancellation' => 'nghỉ buổi học', 'Makeup' => 'học bù'];
        $typeLabel = $labels[$req['request_type']] ?? 'thay đổi lịch';
        $origDate = date('d/m/Y', strtotime($req['session_date']));

        if ($decision === 'Rejected') {
            if ($lecAccountId) {
                NotificationService::send(
                    $lecAccountId, 'Schedule', 'Đề xuất ' . $typeLabel . ' bị từ chối',
                    $req['course_code'] . ' – ' . $req['section_code'] . ' • Buổi ' . $origDate .
                        '. Lý do từ chối: ' . $note,
                    '/UniPortal/public/assets/lecturer/schedule.php'
                );
            }
            return;
        }

        if ($req['request_type'] === 'Cancellation') {
            $body = $req['course_code'] . ' – ' . $req['section_code'] . ' • Buổi học ' . $origDate .
                ' đã được hủy theo đề xuất của giảng viên.' . ($note !== '' ? ' Ghi chú: ' . $note : '');
            if ($lecAccountId) {
                NotificationService::send($lecAccountId, 'Schedule', 'Buổi học đã được hủy',
                    $body, '/UniPortal/public/assets/lecturer/schedule.php');
            }
            self::notifySectionStudents(
                (int)$req['section_id'], 'Lịch học thay đổi: buổi học bị hủy', $body
            );
            return;
        }

        // Approved make-up session
        $date = $req['proposed_date'] ? date('d/m/Y', strtotime($req['proposed_date'])) : '';
        $time = $req['proposed_start_time']
            ? substr($req['proposed_start_time'], 0, 5) . '–' . substr($req['proposed_end_time'], 0, 5)
            : '';
        $body = $req['course_code'] . ' – ' . $req['section_code'] . ' • Buổi học bù ' . $date .
            ' ' . $time . ($req['proposed_room'] ? ' tại ' . $req['proposed_room'] : '') . '.';
        if ($lecAccountId) {
            NotificationService::send($lecAccountId, 'Schedule', 'Buổi học bù đã được duyệt',
                $body, '/UniPortal/public/assets/lecturer/schedule.php');
        }
        self::notifySectionStudents(
            (int)$req['section_id'], 'Lịch học thay đổi: buổi học bù mới', $body
        );
    }

    private static function notifySectionStudents(int $sectionId, string $title, string $body): void {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT a.account_id
             FROM Account a
             JOIN Enrollment e ON e.student_id = a.student_id
             WHERE e.section_id = :sec AND e.status IN (\'Enrolled\',\'Completed\')
               AND a.is_active = TRUE'
        );
        $stmt->execute([':sec' => $sectionId]);
        foreach ($stmt->fetchAll() as $row) {
            NotificationService::send((int)$row['account_id'], 'Schedule', $title, $body,
                '/UniPortal/public/assets/student/schedule.php');
        }
    }
}
