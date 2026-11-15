<?php
/**
 * UniPortal – GPA Planner Model (v2)
 *
 * Calculates required future GPA using credit-weighted formula:
 *   Required = (Target × (Earned + Future) − Current × Earned) ÷ Future
 *
 * Also calculates per-assessment minimum scores for enrolled courses,
 * accounting for already-graded assessments (locked in).
 *
 * Score convention:
 *   Grade.score  = 0–100 scale
 *   GradeScale   = 0–10 scale  (lookup with score/10)
 *   final score  = SUM(score/10 × weight_pct/100) on 10-pt scale
 *
 * Planning data NEVER modifies official Grade or Enrollment records.
 */
require_once __DIR__ . '/../config/database.php';

class GpaPlanner {

    // ─────────────────────────────────────────────────────────
    // Grade Scale helpers
    // ─────────────────────────────────────────────────────────

    /** All GradeScale rows, ordered by grade_point ASC */
    public static function getGradeScale(): array {
        static $cache = null;
        if ($cache === null) {
            $pdo   = get_db();
            $cache = $pdo->query(
                'SELECT grade_scale_id, min_score, max_score, letter_grade, grade_point
                 FROM GradeScale ORDER BY grade_point ASC'
            )->fetchAll();
        }
        return $cache;
    }

    /**
     * Map a 10-pt score → GradeScale row.
     * Uses the existing GradeScale; never hardcodes thresholds.
     */
    public static function scoreToGrade(float $score10, array $scale): array {
        $best = null;
        foreach ($scale as $row) {
            if ($score10 >= (float)$row['min_score'] && $score10 <= (float)$row['max_score']) {
                $best = $row;
            }
        }
        // If no exact match (e.g. exactly 10.0), take the highest tier
        if ($best === null) {
            foreach (array_reverse($scale) as $row) {
                if ($score10 >= (float)$row['min_score']) { $best = $row; break; }
            }
        }
        return $best ?? ['letter_grade' => 'F', 'grade_point' => 0.0,
                         'min_score' => 0.0, 'max_score' => 3.99];
    }

    /**
     * Find the lowest GradeScale tier whose grade_point >= $targetGpa.
     * Returns [letter_grade, grade_point, min_score_10, min_score_100].
     */
    public static function gpaToMinGrade(array $scale, float $targetGpa): array {
        $result = null;
        foreach ($scale as $row) {
            if ((float)$row['grade_point'] >= $targetGpa - 0.001) {
                if ($result === null || (float)$row['grade_point'] < (float)$result['grade_point']) {
                    $result = $row;
                }
            }
        }
        $result ??= end($scale);     // fallback: highest tier
        return [
            'letter_grade'  => $result['letter_grade'],
            'grade_point'   => (float)$result['grade_point'],
            'min_score_10'  => (float)$result['min_score'],
            'min_score_100' => (float)$result['min_score'] * 10,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Courses for future planning (not yet enrolled or completed)
    // ─────────────────────────────────────────────────────────

    /**
     * Returns courses from the student's curriculum that have not yet been
     * enrolled in or completed. Used to fill out future semester planning.
     */
    public static function getCoursesForPlanning(string $student_id): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT c.course_id, c.course_code, c.course_name, c.credits,
                    cc.semester_recommended, cc.course_type
             FROM Student s
             JOIN Department d ON d.department_id = s.department_id
             JOIN Curriculum cur ON cur.major_code = d.department_code
                 AND cur.admission_year = s.admission_year
             JOIN CurriculumCourse cc ON cc.curriculum_id = cur.curriculum_id
             JOIN Course c ON c.course_id = cc.course_id
             WHERE s.student_id = :sid
               AND c.is_active = TRUE
               AND c.course_id NOT IN (
                   SELECT cs2.course_id
                   FROM Enrollment e2
                   JOIN ClassSection cs2 ON cs2.section_id = e2.section_id
                   WHERE e2.student_id = :sid2
                     AND e2.status IN (\'Completed\', \'Enrolled\')
               )
             ORDER BY cc.semester_recommended ASC, c.course_code ASC'
        );
        $stmt->execute([':sid' => $student_id, ':sid2' => $student_id]);
        return $stmt->fetchAll();
    }

    // ─────────────────────────────────────────────────────────
    // Core formula
    // ─────────────────────────────────────────────────────────

    /**
     * Calculate required future GPA.
     *
     * @param float|null $currentGpa   Current cumulative GPA (null = no data)
     * @param int        $earnedCrd    Credits already completed
     * @param int        $futureCrd    Credits in plan (remaining)
     * @param float      $targetGpa    Target cumulative GPA
     * @return array {
     *   required_gpa, raw_required_gpa, feasibility,
     *   max_possible_gpa, projected_cum_gpa, is_achievable
     * }
     */
    public static function calcRequiredGpa(
        ?float $currentGpa,
        int    $earnedCrd,
        int    $futureCrd,
        float  $targetGpa
    ): array {
        // No future credits
        if ($futureCrd <= 0) {
            $feas = ($currentGpa !== null && $currentGpa >= $targetGpa) ? 'achieved' : 'no_future';
            return [
                'required_gpa'      => null,
                'raw_required_gpa'  => null,
                'feasibility'       => $feas,
                'max_possible_gpa'  => $currentGpa,
                'projected_cum_gpa' => $currentGpa,
                'is_achievable'     => $feas === 'achieved',
            ];
        }

        // No completed credits yet — target equals required future GPA
        if ($currentGpa === null || $earnedCrd === 0) {
            $feas = $targetGpa <= 4.0 ? 'achievable' : 'unachievable';
            return [
                'required_gpa'      => round(min(4.0, $targetGpa), 2),
                'raw_required_gpa'  => round($targetGpa, 4),
                'feasibility'       => $feas,
                'max_possible_gpa'  => 4.0,
                'projected_cum_gpa' => round(min($targetGpa, 4.0), 2),
                'is_achievable'     => $feas === 'achievable',
            ];
        }

        // Already achieved
        if ($currentGpa >= $targetGpa) {
            $maxPoss = ($currentGpa * $earnedCrd + 4.0 * $futureCrd) / ($earnedCrd + $futureCrd);
            return [
                'required_gpa'      => 0.0,
                'raw_required_gpa'  => 0.0,
                'feasibility'       => 'achieved',
                'max_possible_gpa'  => round($maxPoss, 2),
                'projected_cum_gpa' => round($currentGpa, 2),
                'is_achievable'     => true,
            ];
        }

        // Main formula
        $totalCrd = $earnedCrd + $futureCrd;
        $required = ($targetGpa * $totalCrd - $currentGpa * $earnedCrd) / $futureCrd;

        // Max possible cumulative GPA (perfect 4.0 future)
        $maxPoss  = ($currentGpa * $earnedCrd + 4.0 * $futureCrd) / $totalCrd;

        // Projected cumulative if student achieves exactly $required
        $reqCapped  = min(4.0, max(0.0, $required));
        $projected  = ($currentGpa * $earnedCrd + $reqCapped * $futureCrd) / $totalCrd;

        $feasibility = match(true) {
            $required > 4.0        => 'unachievable',
            $required >= 3.70      => 'very_difficult',
            $required >= 3.20      => 'difficult',
            default                => 'achievable',
        };

        return [
            'required_gpa'      => $reqCapped,
            'raw_required_gpa'  => round($required, 4),
            'feasibility'       => $feasibility,
            'max_possible_gpa'  => round($maxPoss, 2),
            'projected_cum_gpa' => round($projected, 2),
            'is_achievable'     => $required <= 4.0,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Enrolled courses with real partial grade detail
    // ─────────────────────────────────────────────────────────

    /**
     * Returns all Enrolled courses in the active semester, plus:
     *   - each assessment with current score (if graded)
     *   - what score the student needs in remaining assessments
     *     to hit $requiredCourseGpa in that course
     *   - the minimum score per remaining assessment (uniform distribution)
     */
    public static function getEnrolledCoursesDetail(
        string $student_id,
        float  $requiredCourseGpa
    ): array {
        $pdo   = get_db();
        $scale = self::getGradeScale();

        // All Enrolled courses in Active/Upcoming semester
        $stmt = $pdo->prepare(
            'SELECT e.enrollment_id, e.section_id,
                    c.course_id, c.course_code, c.course_name, c.credits,
                    cs.section_code,
                    sem.semester_name, sem.academic_year
             FROM Enrollment e
             JOIN ClassSection cs  ON cs.section_id  = e.section_id
             JOIN Course c         ON c.course_id    = cs.course_id
             JOIN Semester sem     ON sem.semester_id = cs.semester_id
             WHERE e.student_id = :sid
               AND e.status     = \'Enrolled\'
               AND sem.status   IN (\'Active\', \'Upcoming\')
             ORDER BY c.course_code ASC'
        );
        $stmt->execute([':sid' => $student_id]);
        $enrollments = $stmt->fetchAll();

        $results = [];

        foreach ($enrollments as $enr) {
            // All assessments + existing grade for this enrollment
            $aStmt = $pdo->prepare(
                'SELECT a.assessment_id, a.assessment_name,
                        a.weight_percent, a.max_score, a.due_date,
                        g.score, g.graded_at
                 FROM Assessment a
                 LEFT JOIN Grade g
                        ON g.enrollment_id  = :eid
                       AND g.assessment_id  = a.assessment_id
                 WHERE a.section_id = :sec
                 ORDER BY a.assessment_id ASC'
            );
            $aStmt->execute([':eid' => $enr['enrollment_id'], ':sec' => $enr['section_id']]);
            $assessments = $aStmt->fetchAll();

            // Accumulate graded vs ungraded weights + points
            $gradedWeight   = 0.0;
            $gradedPoints   = 0.0;   // SUM(score/10 × weight/100) for graded
            $ungradedWeight = 0.0;

            foreach ($assessments as $asm) {
                $w = (float)$asm['weight_percent'];
                if ($asm['score'] !== null) {
                    $s10           = (float)$asm['score'] / 10;
                    $gradedPoints += $s10 * ($w / 100);
                    $gradedWeight += $w;
                } else {
                    $ungradedWeight += $w;
                }
            }

            // Current partial score on 10-pt scale (for already-graded portion)
            $currentScore10 = $gradedWeight > 0
                ? $gradedPoints / ($gradedWeight / 100)
                : null;

            // Target final score for this course
            $targetTier     = self::gpaToMinGrade($scale, $requiredCourseGpa);
            $targetScore10  = $targetTier['min_score_10'];

            // Points still needed from ungraded assessments:
            //   needed = targetScore10 - gradedPoints
            // Uniform score per ungraded assessment:
            //   neededRemainingScore10 = needed / (ungradedWeight/100)
            $neededContrib  = $targetScore10 - $gradedPoints;
            $neededRemScore = null;
            if ($ungradedWeight > 0.0) {
                $raw            = $neededContrib / ($ungradedWeight / 100);
                $neededRemScore = max(0.0, min(10.0, $raw));
            }

            // Per-assessment breakdown
            $asmDetails = [];
            foreach ($assessments as $asm) {
                $isGraded = $asm['score'] !== null;
                $gradeRow = $isGraded
                    ? self::scoreToGrade((float)$asm['score'] / 10, $scale)
                    : null;

                // For ungraded: needed score (uniform distribution across all ungraded)
                $neededScore = $isGraded ? null : $neededRemScore;

                $asmDetails[] = [
                    'assessment_id'    => (int)$asm['assessment_id'],
                    'assessment_name'  => $asm['assessment_name'],
                    'weight_percent'   => (float)$asm['weight_percent'],
                    'max_score'        => (float)$asm['max_score'],
                    'due_date'         => $asm['due_date'],
                    // Graded
                    'is_graded'        => $isGraded,
                    'score_100'        => $isGraded ? (float)$asm['score'] : null,
                    'score_10'         => $isGraded ? round((float)$asm['score'] / 10, 2) : null,
                    'letter_grade'     => $gradeRow['letter_grade'] ?? null,
                    'grade_point'      => $gradeRow ? (float)$gradeRow['grade_point'] : null,
                    // Required (ungraded only)
                    'needed_score_10'  => $neededScore !== null ? round($neededScore, 2) : null,
                    'needed_score_100' => $neededScore !== null ? round($neededScore * 10, 1) : null,
                    'needed_letter'    => $neededScore !== null
                        ? self::scoreToGrade($neededScore, $scale)['letter_grade']
                        : null,
                    'is_overdue'       => $asm['due_date'] && strtotime($asm['due_date']) < time(),
                ];
            }

            // Current Grade row (from fully-weighted current score)
            $curGradeRow = null;
            if ($gradedWeight >= 100) {
                $curGradeRow = self::scoreToGrade($gradedPoints, $scale);
            } elseif ($currentScore10 !== null) {
                $curGradeRow = self::scoreToGrade($currentScore10, $scale);
            }

            // Feasibility for this course
            // If ungradedWeight > 0 and neededRemScore was capped at 10.0
            // it means the original required > 10 → unachievable
            $rawNeeded = $ungradedWeight > 0
                ? $neededContrib / ($ungradedWeight / 100)
                : 0.0;
            $feasible = ($ungradedWeight <= 0) || ($rawNeeded <= 10.0);

            $results[] = [
                'enrollment_id'          => (int)$enr['enrollment_id'],
                'section_id'             => (int)$enr['section_id'],
                'section_code'           => $enr['section_code'],
                'course_code'            => $enr['course_code'],
                'course_name'            => $enr['course_name'],
                'credits'                => (int)$enr['credits'],
                'semester_name'          => $enr['semester_name'],
                'academic_year'          => $enr['academic_year'],
                // Progress
                'graded_weight'          => round($gradedWeight, 1),
                'ungraded_weight'        => round($ungradedWeight, 1),
                'graded_points'          => round($gradedPoints, 3),
                // Current state
                'current_score_10'       => $currentScore10 !== null ? round($currentScore10, 2) : null,
                'current_letter'         => $curGradeRow['letter_grade'] ?? null,
                'current_gpa'            => $curGradeRow ? (float)$curGradeRow['grade_point'] : null,
                // Target
                'target_score_10'        => $targetScore10,
                'target_score_100'       => $targetScore10 * 10,
                'target_letter'          => $targetTier['letter_grade'],
                'target_gpa'             => $targetTier['grade_point'],
                // Required from remaining
                'needed_remaining_score_10'  => $neededRemScore !== null ? round($neededRemScore, 2) : null,
                'needed_remaining_score_100' => $neededRemScore !== null ? round($neededRemScore * 10, 1) : null,
                'needed_remaining_letter'    => $neededRemScore !== null
                    ? self::scoreToGrade($neededRemScore, $scale)['letter_grade']
                    : null,
                'is_feasible'            => $feasible,
                'assessments'            => $asmDetails,
            ];
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────
    // Grade distribution for future (not-yet-enrolled) courses
    // ─────────────────────────────────────────────────────────

    /**
     * Given a list of future courses and a required weighted GPA, assign
     * the minimum grade tier to each course (using GradeScale).
     */
    public static function distributeGrades(
        array $courses,
        float $requiredGpa,
        array $scale
    ): array {
        if (empty($courses)) {
            return ['courses' => [], 'total_credits' => 0, 'weighted_gpa' => 0.0, 'feasible' => true];
        }

        $totalCrd = array_sum(array_column($courses, 'credits'));

        // Sort scale descending by grade_point for upgrade passes
        $scaleDsc = array_reverse($scale);
        $gradePoints = array_unique(array_map(fn($r) => (float)$r['grade_point'], $scale));
        rsort($gradePoints);

        $results = [];
        $totalPts = 0.0;

        foreach ($courses as $c) {
            $crd    = (int)$c['credits'];
            // Find lowest grade_point >= requiredGpa
            $picked = null;
            foreach ($scale as $row) {
                if ((float)$row['grade_point'] >= $requiredGpa - 0.001) {
                    if ($picked === null || (float)$row['grade_point'] < (float)$picked['grade_point']) {
                        $picked = $row;
                    }
                }
            }
            $picked ??= end($scale);

            $results[] = [
                'course_id'      => $c['course_id'] ?? null,
                'course_code'    => $c['course_code'],
                'course_name'    => $c['course_name'],
                'credits'        => $crd,
                'target_letter'  => $picked['letter_grade'],
                'target_gpa'     => (float)$picked['grade_point'],
                'min_score_10'   => (float)$picked['min_score'],
                'min_score_100'  => (float)$picked['min_score'] * 10,
                'contribution'   => (float)$picked['grade_point'] * $crd,
                'is_enrolled'    => isset($c['enrollment_id']),
            ];
            $totalPts += (float)$picked['grade_point'] * $crd;
        }

        $weightedGpa = $totalCrd > 0 ? round($totalPts / $totalCrd, 2) : 0.0;
        $needed      = $requiredGpa * $totalCrd;
        $feasible    = $totalPts >= $needed - 0.01;

        return [
            'courses'       => $results,
            'total_credits' => $totalCrd,
            'total_points'  => round($totalPts, 2),
            'weighted_gpa'  => $weightedGpa,
            'feasible'      => $feasible,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Multi-semester projection
    // ─────────────────────────────────────────────────────────

    /**
     * Split remaining credits into semesters of ~$semSize credits each
     * and project the required GPA per semester and cumulative.
     */
    public static function projectSemesters(
        ?float $currentGpa,
        int    $earnedCrd,
        int    $remainingCrd,
        float  $targetGpa,
        int    $semSize = 15
    ): array {
        if ($remainingCrd <= 0) return [];

        $semesters  = [];
        $runEarned  = $earnedCrd;
        $runGpa     = $currentGpa ?? 0.0;
        $remaining  = $remainingCrd;
        $semNum     = 1;

        while ($remaining > 0 && $semNum <= 10) {
            $semCrd = min($semSize, $remaining);
            $calc   = self::calcRequiredGpa($runGpa, $runEarned, $remaining, $targetGpa);

            // When the target is already met, assume the student maintains
            // the current GPA. Using required_gpa = 0 here would project an
            // artificial decline, which is not a meaningful forecast.
            if (($calc['feasibility'] ?? '') === 'achieved') {
                $reqGpa = $runGpa;
            } else {
                $reqGpa = min(4.0, max(0.0, (float)($calc['required_gpa'] ?? $targetGpa)));
            }

            // Project cumulative after this semester assuming student achieves $reqGpa
            $projected = ($runGpa * $runEarned + $reqGpa * $semCrd) / ($runEarned + $semCrd);

            $semesters[] = [
                'sem_num'        => $semNum,
                'credits'        => $semCrd,
                'required_gpa'   => round($reqGpa, 2),
                'projected_cum'  => round($projected, 2),
                'feasibility'    => $calc['feasibility'],
            ];

            $runGpa     = $projected;
            $runEarned += $semCrd;
            $remaining -= $semCrd;
            $semNum++;
        }

        return $semesters;
    }

    // ─────────────────────────────────────────────────────────
    // Scenario persistence
    // ─────────────────────────────────────────────────────────

    public static function saveScenario(
        string  $studentId,
        string  $name,
        float   $targetGpa,
        string  $targetLabel,
        int     $futureCredits,
        ?float  $requiredGpa,
        string  $feasibility
    ): int {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'INSERT INTO GPAPlanScenario
                (student_id, scenario_name, target_gpa, target_label,
                 future_credits, required_gpa, feasibility)
             VALUES (:sid,:name,:tgpa,:tlabel,:fcr,:rgpa,:feas)'
        );
        $stmt->execute([
            ':sid'    => $studentId,
            ':name'   => $name,
            ':tgpa'   => $targetGpa,
            ':tlabel' => $targetLabel,
            ':fcr'    => $futureCredits,
            ':rgpa'   => $requiredGpa,
            ':feas'   => $feasibility,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function getScenarios(string $studentId): array {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT * FROM GPAPlanScenario WHERE student_id=:sid ORDER BY created_at DESC LIMIT 10'
        );
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetchAll();
    }

    public static function deleteScenario(string $studentId, int $scenarioId): bool {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'DELETE FROM GPAPlanScenario WHERE scenario_id=:sid AND student_id=:stu'
        );
        return $stmt->execute([':sid' => $scenarioId, ':stu' => $studentId]);
    }
}
