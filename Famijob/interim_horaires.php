<?php
require_once 'config.php';
verifierConnexion($db);

$pageLang = famiLang();
if (!function_exists('fjhT')) {
    function fjhT($fr, $nl = null)
    {
        return famiLang() === 'nl' && $nl !== null ? $nl : $fr;
    }
}

$role = getCurrentRole();
if (!in_array($role, ['admin', 'teamcoach'], true)) {
    header('Location: ../index.php');
    exit();
}

$isAdmin = ($role === 'admin');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

ensureDepartmentsTable($db);
ensureStudentAvailabilityTable($db);

try {
    syncDepartmentsFromPlanningDb($db);
} catch (Exception $e) {
    // On garde la liste locale si la base planning n'est pas disponible.
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_shift_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shift_date DATE NOT NULL,
        department_name VARCHAR(120) NOT NULL,
        time_slot VARCHAR(60) NOT NULL,
        seats_required SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        comment TEXT NULL,
        validation_status VARCHAR(20) NOT NULL DEFAULT 'approved',
        validated_by_user_id INT NULL,
        validated_at DATETIME NULL,
        created_by_user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_shift_request (shift_date, department_name, time_slot),
        INDEX idx_shift_date (shift_date),
        INDEX idx_shift_department (department_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$requestColumns = [];
foreach ($db->query('SHOW COLUMNS FROM interim_shift_requests')->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
    $requestColumns[(string) ($columnRow['Field'] ?? '')] = true;
}
if (!isset($requestColumns['validation_status'])) {
    $db->exec("ALTER TABLE interim_shift_requests ADD COLUMN validation_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER comment");
}
if (!isset($requestColumns['validated_by_user_id'])) {
    $db->exec('ALTER TABLE interim_shift_requests ADD COLUMN validated_by_user_id INT NULL AFTER validation_status');
}
if (!isset($requestColumns['validated_at'])) {
    $db->exec('ALTER TABLE interim_shift_requests ADD COLUMN validated_at DATETIME NULL AFTER validated_by_user_id');
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_shift_assignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        seat_number SMALLINT UNSIGNED NOT NULL,
        student_id INT NOT NULL,
        assigned_by_user_id INT NULL,
        agency_name VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_request_seat (request_id, seat_number),
        INDEX idx_request (request_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Migration : supporter l'affectation de personnes non inscrites sur le site (matching par nom, version A).
$assignmentColumns = [];
$assignmentStudentNullable = false;
foreach ($db->query('SHOW COLUMNS FROM interim_shift_assignments')->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
    $field = (string) ($columnRow['Field'] ?? '');
    $assignmentColumns[$field] = true;
    if ($field === 'student_id' && strtoupper((string) ($columnRow['Null'] ?? '')) === 'YES') {
        $assignmentStudentNullable = true;
    }
}
if (!isset($assignmentColumns['external_name'])) {
    $db->exec("ALTER TABLE interim_shift_assignments ADD COLUMN external_name VARCHAR(255) NULL AFTER student_id");
}
if (!$assignmentStudentNullable) {
    // student_id peut etre NULL pour une personne externe (non inscrite) affectee via son nom.
    $db->exec('ALTER TABLE interim_shift_assignments MODIFY COLUMN student_id INT NULL');
}

$agencyName = '';
if (!$isAdmin) {
    $agencyStmt = $db->prepare('SELECT interim FROM utilisateurs WHERE id = ? LIMIT 1');
    $agencyStmt->execute([$currentUserId]);
    $agencyName = trim((string) $agencyStmt->fetchColumn());
}

$message = '';
$pendingConfirm = null; // Confirmation "modale" en attente (mode par nom) : ['message','request_id','student_name']

$today = new DateTimeImmutable('today');
$startMonday = $today->modify('monday this week');
$weekOptions = [];
for ($offset = 0; $offset < 8; $offset++) {
    $weekStart = $startMonday->modify('+' . $offset . ' week');
    $weekEnd = $weekStart->modify('+6 days');
    $weekOptions[$weekStart->format('Y-m-d')] = [
        'start' => $weekStart,
        'end' => $weekEnd,
        'label' => fjhT('Semaine du ', 'Week van ') . $weekStart->format('d/m/Y') . fjhT(' au ', ' tot ') . $weekEnd->format('d/m/Y'),
    ];
}

$selectedWeekKey = (string) ($_GET['week'] ?? array_key_first($weekOptions));
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = array_key_first($weekOptions);
}
$selectedWeek = $weekOptions[$selectedWeekKey];

$departmentFilterOptions = [];
$departmentFilterStmt = $db->query(
    "SELECT department_name
     FROM departments
     WHERE is_active = 1
     ORDER BY department_name ASC"
);
$departmentFilterOptions = $departmentFilterStmt->fetchAll(PDO::FETCH_COLUMN);

$weekdayMap = [
    'Monday' => fjhT('Lundi', 'Maandag'),
    'Tuesday' => fjhT('Mardi', 'Dinsdag'),
    'Wednesday' => fjhT('Mercredi', 'Woensdag'),
    'Thursday' => fjhT('Jeudi', 'Donderdag'),
    'Friday' => fjhT('Vendredi', 'Vrijdag'),
    'Saturday' => fjhT('Samedi', 'Zaterdag'),
    'Sunday' => fjhT('Dimanche', 'Zondag'),
];

$weekDays = [];
$cursor = $selectedWeek['start'];
while ($cursor <= $selectedWeek['end']) {
    $dateKey = $cursor->format('Y-m-d');
    $weekDays[] = [
        'key' => $dateKey,
        'label' => $weekdayMap[$cursor->format('l')] ?? $cursor->format('l'),
        'date' => $cursor->format('d/m/Y'),
    ];
    $cursor = $cursor->modify('+1 day');
}

$validDayFilterValues = array_map(static function ($day) {
    return (string) $day['key'];
}, $weekDays);
$selectedDayFilter = trim((string) ($_GET['day'] ?? 'all'));
if ($selectedDayFilter !== 'all' && !in_array($selectedDayFilter, $validDayFilterValues, true)) {
    $selectedDayFilter = 'all';
}

$selectedDepartmentFilter = trim((string) ($_GET['department'] ?? 'all'));
if ($selectedDepartmentFilter !== 'all' && !in_array($selectedDepartmentFilter, $departmentFilterOptions, true)) {
    $selectedDepartmentFilter = 'all';
}

$selectedVueFilter = trim((string) ($_GET['vue'] ?? 'all'));
if (!in_array($selectedVueFilter, ['all', 'a_pourvoir', 'attribue'], true)) {
    $selectedVueFilter = 'all';
}

$matchingMode = trim((string) ($_GET['matching_mode'] ?? $_POST['matching_mode'] ?? 'name'));
if (!in_array($matchingMode, ['name', 'list'], true)) {
    $matchingMode = 'name';
}

if (!function_exists('interimExtractStartMinutes')) {
    function interimExtractStartMinutes($timeSlot)
    {
        $timeSlot = trim((string) $timeSlot);
        if ($timeSlot === '') {
            return null;
        }

        if (preg_match('/(\d{1,2})\s*(?:h|:)?\s*(\d{0,2})/i', $timeSlot, $matches)) {
            $hours = (int) ($matches[1] ?? 0);
            $minutesRaw = trim((string) ($matches[2] ?? ''));
            $minutes = ($minutesRaw === '') ? 0 : (int) $minutesRaw;

            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return ($hours * 60) + $minutes;
            }
        }

        return null;
    }
}

if (!function_exists('interimAvailabilityCompatible')) {
    function interimAvailabilityCompatible($availabilityStatus, $timeSlot)
    {
        $status = (string) $availabilityStatus;

        if ($status === 'journee') {
            return true;
        }

        if ($status !== 'apres_midi') {
            return false;
        }

        $startMinutes = interimExtractStartMinutes($timeSlot);
        if ($startMinutes === null) {
            return false;
        }

        return $startMinutes >= (13 * 60);
    }
}

if (!function_exists('interimParseTimeSlotDuration')) {
    function interimParseTimeSlotDuration($timeSlot)
    {
        $timeSlot = trim((string) $timeSlot);
        if (!preg_match('/^(\d{1,2})[h:](\d{0,2})\s*-\s*(\d{1,2})[h:](\d{0,2})/i', $timeSlot, $m)) {
            return 0;
        }
        $startMin = (int) $m[1] * 60 + (trim($m[2]) !== '' ? (int) $m[2] : 0);
        $endMin   = (int) $m[3] * 60 + (trim($m[4]) !== '' ? (int) $m[4] : 0);
        if ($endMin <= $startMin) {
            return 0;
        }
        return $endMin - $startMin;
    }
}

if (!function_exists('interimGetRankedCandidatesForRequest')) {
    function interimGetRankedCandidatesForRequest(PDO $db, array $requestRow, $isAdmin, $agencyName)
    {
        $requestId = (int) ($requestRow['id'] ?? 0);
        $shiftDate = (string) ($requestRow['shift_date'] ?? '');
        $departmentName = trim((string) ($requestRow['department_name'] ?? ''));
        $timeSlot = (string) ($requestRow['time_slot'] ?? '');

        if ($requestId <= 0 || $shiftDate === '') {
            return [];
        }

        $studentsSql =
            "SELECT u.id, u.nom, u.prenom, u.interim,
                    COALESCE(sa.availability_status, 'non_renseigne') AS availability_status
             FROM utilisateurs u
             LEFT JOIN student_availabilities sa
                ON sa.user_id = u.id
               AND sa.availability_date = ?
             WHERE u.role = 'etudiant'";
        $studentsParams = [$shiftDate];

        if (!$isAdmin) {
            $studentsSql .= ' AND u.interim = ?';
            $studentsParams[] = (string) $agencyName;
        }

        $studentsSql .= ' ORDER BY u.nom ASC, u.prenom ASC';
        $studentsStmt = $db->prepare($studentsSql);
        $studentsStmt->execute($studentsParams);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            return [];
        }

        $studentIds = array_map(static function ($row) {
            return (int) $row['id'];
        }, $students);
        $studentIds = array_values(array_unique($studentIds));

        $idPlaceholders = implode(', ', array_fill(0, count($studentIds), '?'));

        $priorityMap = [];
        if ($departmentName !== '') {
            $priorityStmt = $db->prepare(
                "SELECT sdl.student_id, MIN(sdl.priority_rank) AS priority_rank
                 FROM student_department_links sdl
                 INNER JOIN departments d ON d.id = sdl.department_id
                 WHERE d.department_name = ?
                   AND sdl.student_id IN ($idPlaceholders)
                 GROUP BY sdl.student_id"
            );
            $priorityStmt->execute(array_merge([$departmentName], $studentIds));

            foreach ($priorityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $priorityMap[(int) $row['student_id']] = max(1, (int) $row['priority_rank']);
            }
        }

        $alreadyOnRequest = [];
        $alreadyOnRequestStmt = $db->prepare(
            'SELECT student_id FROM interim_shift_assignments WHERE request_id = ?'
        );
        $alreadyOnRequestStmt->execute([$requestId]);
        foreach ($alreadyOnRequestStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $alreadyOnRequest[(int) $sid] = true;
        }

        $sameDayCountMap = [];
        $sameDayStmt = $db->prepare(
            "SELECT a.student_id, COUNT(*) AS total_assignments
             FROM interim_shift_assignments a
             INNER JOIN interim_shift_requests r ON r.id = a.request_id
             WHERE r.shift_date = ?
               AND a.student_id IN ($idPlaceholders)
             GROUP BY a.student_id"
        );
        $sameDayStmt->execute(array_merge([$shiftDate], $studentIds));
        foreach ($sameDayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sameDayCountMap[(int) $row['student_id']] = (int) $row['total_assignments'];
        }

        // === Règle 45h/semaine ===
        $newShiftDuration = interimParseTimeSlotDuration($timeSlot);
        $weeklyMinutesMap = [];
        if ($newShiftDuration > 0) {
            $weeklyStmt = $db->prepare(
                "SELECT a.student_id, r.time_slot
                 FROM interim_shift_assignments a
                 INNER JOIN interim_shift_requests r ON r.id = a.request_id
                 WHERE a.student_id IN ($idPlaceholders)
                   AND YEARWEEK(r.shift_date, 1) = YEARWEEK(?, 1)"
            );
            $weeklyStmt->execute(array_merge($studentIds, [$shiftDate]));
            foreach ($weeklyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sid = (int) $row['student_id'];
                $weeklyMinutesMap[$sid] = ($weeklyMinutesMap[$sid] ?? 0) + interimParseTimeSlotDuration($row['time_slot']);
            }
        }

        // === Règle 6 jours consécutifs (semaine en cours + semaine précédente) ===
        $assignedDatesMap = [];
        $windowStart = (new DateTimeImmutable($shiftDate))->modify('-13 days')->format('Y-m-d');
        $consecutiveDatesStmt = $db->prepare(
            "SELECT a.student_id, r.shift_date
             FROM interim_shift_assignments a
             INNER JOIN interim_shift_requests r ON r.id = a.request_id
             WHERE a.student_id IN ($idPlaceholders)
               AND r.shift_date BETWEEN ? AND ?
             GROUP BY a.student_id, r.shift_date"
        );
        $consecutiveDatesStmt->execute(array_merge($studentIds, [$windowStart, $shiftDate]));
        foreach ($consecutiveDatesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) $row['student_id'];
            $assignedDatesMap[$sid][(string) $row['shift_date']] = true;
        }

        $ranked = [];
        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $studentName = trim((string) ($student['prenom'] ?? '')) . ' ' . trim((string) ($student['nom'] ?? ''));

            if (isset($alreadyOnRequest[$studentId])) {
                $ranked[] = [
                    'id' => $studentId,
                    'name' => $studentName,
                    'interim' => (string) ($student['interim'] ?? ''),
                    'availability_status' => (string) ($student['availability_status'] ?? 'non_renseigne'),
                    'priority_rank' => $priorityMap[$studentId] ?? 99,
                    'same_day_count' => (int) ($sameDayCountMap[$studentId] ?? 0),
                    'score' => 999999,
                    'manual_eligible' => false,
                    'eligible' => false,
                    'reason' => 'Déjà affecté sur ce créneau',
                    'manual_reason' => 'Déjà affecté sur ce créneau',
                ];
                continue;
            }

            $availabilityStatus = (string) ($student['availability_status'] ?? 'non_renseigne');
            if ($availabilityStatus === 'matin') {
                $availabilityStatus = 'non_renseigne';
            }

            $priorityRank = $priorityMap[$studentId] ?? 99;
            $sameDayCount = (int) ($sameDayCountMap[$studentId] ?? 0);

            if ($sameDayCount > 0) {
                $ranked[] = [
                    'id' => $studentId,
                    'name' => $studentName,
                    'interim' => (string) ($student['interim'] ?? ''),
                    'availability_status' => $availabilityStatus,
                    'priority_rank' => $priorityRank,
                    'same_day_count' => $sameDayCount,
                    'score' => 999998,
                    'manual_eligible' => false,
                    'eligible' => false,
                    'reason' => 'Déjà affecté ce jour',
                    'manual_reason' => 'Déjà affecté ce jour',
                ];
                continue;
            }

            if ($availabilityStatus === 'indisponible') {
                $ranked[] = [
                    'id' => $studentId,
                    'name' => $studentName,
                    'interim' => (string) ($student['interim'] ?? ''),
                    'availability_status' => $availabilityStatus,
                    'priority_rank' => $priorityRank,
                    'same_day_count' => $sameDayCount,
                    'score' => 999997,
                    'manual_eligible' => false,
                    'eligible' => false,
                    'reason' => 'Indisponible',
                    'manual_reason' => 'Indisponible',
                ];
                continue;
            }

            if (!interimAvailabilityCompatible($availabilityStatus, $timeSlot)) {
                $ranked[] = [
                    'id' => $studentId,
                    'name' => $studentName,
                    'interim' => (string) ($student['interim'] ?? ''),
                    'availability_status' => $availabilityStatus,
                    'priority_rank' => $priorityRank,
                    'same_day_count' => $sameDayCount,
                    'score' => 999997,
                    'manual_eligible' => true,
                    'eligible' => false,
                    'reason' => 'Disponibilité non compatible',
                    'manual_reason' => '',
                ];
                continue;
            }

            // === Vérification 45h/semaine ===
            if ($newShiftDuration > 0) {
                $totalMinutesThisWeek = ($weeklyMinutesMap[$studentId] ?? 0) + $newShiftDuration;
                if ($totalMinutesThisWeek > 45 * 60) {
                    $ranked[] = [
                        'id' => $studentId,
                        'name' => $studentName,
                        'interim' => (string) ($student['interim'] ?? ''),
                        'availability_status' => $availabilityStatus,
                        'priority_rank' => $priorityRank,
                        'same_day_count' => $sameDayCount,
                        'score' => 999996,
                        'manual_eligible' => false,
                        'eligible' => false,
                        'reason' => 'Limite 45h/semaine atteinte (' . round($totalMinutesThisWeek / 60, 1) . 'h prévu)',
                        'manual_reason' => 'Limite 45h/semaine atteinte',
                    ];
                    continue;
                }
            }

            // === Vérification 6 jours consécutifs ===
            $datesForStudent = $assignedDatesMap[$studentId] ?? [];
            $datesForStudent[$shiftDate] = true;
            ksort($datesForStudent);
            $dateList = array_keys($datesForStudent);
            $maxConsecutive = 1;
            $streak = 1;
            for ($di = 1, $diMax = count($dateList); $di < $diMax; $di++) {
                $prev = new DateTimeImmutable($dateList[$di - 1]);
                $curr = new DateTimeImmutable($dateList[$di]);
                if ((int) $curr->diff($prev)->days === 1) {
                    $streak++;
                    if ($streak > $maxConsecutive) {
                        $maxConsecutive = $streak;
                    }
                } else {
                    $streak = 1;
                }
            }
            if ($maxConsecutive > 6) {
                $ranked[] = [
                    'id' => $studentId,
                    'name' => $studentName,
                    'interim' => (string) ($student['interim'] ?? ''),
                    'availability_status' => $availabilityStatus,
                    'priority_rank' => $priorityRank,
                    'same_day_count' => $sameDayCount,
                    'score' => 999995,
                    'manual_eligible' => false,
                    'eligible' => false,
                    'reason' => 'Limite 6 jours consécutifs atteinte',
                    'manual_reason' => 'Limite 6 jours consécutifs atteinte',
                ];
                continue;
            }

            $availabilityPenalty = ($availabilityStatus === 'journee') ? 0 : 20;
            $sameDayPenalty = 0;
            $score = ($priorityRank * 100) + $availabilityPenalty + $sameDayPenalty;

            $ranked[] = [
                'id' => $studentId,
                'name' => $studentName,
                'interim' => (string) ($student['interim'] ?? ''),
                'availability_status' => $availabilityStatus,
                'priority_rank' => $priorityRank,
                'same_day_count' => $sameDayCount,
                'score' => $score,
                'manual_eligible' => true,
                'eligible' => true,
                'reason' => '',
                'manual_reason' => '',
            ];
        }

        usort($ranked, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp((string) $a['name'], (string) $b['name']);
            }
            return ($a['score'] <=> $b['score']);
        });

        return $ranked;
    }
}

if (!function_exists('interimAutoAssignRequest')) {
    function interimAutoAssignRequest(PDO $db, $requestId, $currentUserId, $isAdmin, $agencyName)
    {
        $requestId = (int) $requestId;
        if ($requestId <= 0) {
            return ['assigned' => 0, 'reason' => 'Demande invalide'];
        }

        $db->beginTransaction();
        try {
            $requestStmt = $db->prepare(
                'SELECT id, shift_date, department_name, time_slot, seats_required FROM interim_shift_requests WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $requestStmt->execute([$requestId]);
            $requestRow = $requestStmt->fetch(PDO::FETCH_ASSOC);

            if (!$requestRow) {
                throw new RuntimeException('Demande introuvable');
            }

            $assignedSeatsStmt = $db->prepare(
                'SELECT seat_number FROM interim_shift_assignments WHERE request_id = ? ORDER BY seat_number ASC FOR UPDATE'
            );
            $assignedSeatsStmt->execute([$requestId]);
            $assignedSeats = array_map('intval', $assignedSeatsStmt->fetchAll(PDO::FETCH_COLUMN));

            $seatsRequired = (int) $requestRow['seats_required'];
            $availableSeats = [];
            for ($i = 1; $i <= $seatsRequired; $i++) {
                if (!in_array($i, $assignedSeats, true)) {
                    $availableSeats[] = $i;
                }
            }

            if (empty($availableSeats)) {
                $db->commit();
                return ['assigned' => 0, 'reason' => 'Créneau déjà complet'];
            }

            $rankedCandidates = interimGetRankedCandidatesForRequest($db, $requestRow, $isAdmin, $agencyName);
            $eligibleCandidates = array_values(array_filter($rankedCandidates, static function ($candidate) {
                return !empty($candidate['eligible']);
            }));

            if (empty($eligibleCandidates)) {
                $db->commit();
                return ['assigned' => 0, 'reason' => 'Aucun candidat compatible'];
            }

            $insertAssignStmt = $db->prepare(
                'INSERT INTO interim_shift_assignments (request_id, seat_number, student_id, assigned_by_user_id, agency_name) VALUES (?, ?, ?, ?, ?)'
            );

            $assignedCount = 0;
            foreach ($eligibleCandidates as $candidate) {
                if (empty($availableSeats)) {
                    break;
                }

                $seatNumber = array_shift($availableSeats);
                $candidateAgency = trim((string) ($candidate['interim'] ?? ''));
                $insertAssignStmt->execute([
                    $requestId,
                    $seatNumber,
                    (int) $candidate['id'],
                    (int) $currentUserId,
                    $isAdmin ? $candidateAgency : (string) $agencyName,
                ]);
                $assignedCount++;
            }

            $db->commit();
            return ['assigned' => $assignedCount, 'reason' => ''];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['assigned' => 0, 'reason' => $e->getMessage()];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    if ($isAdmin && isset($_POST['auto_match_request'])) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $result = interimAutoAssignRequest($db, $requestId, $currentUserId, true, $agencyName);
        if ((int) $result['assigned'] > 0) {
            $message = "<div class='alert success'>Auto-matching terminé : " . (int) $result['assigned'] . " place(s) affectée(s) sur ce créneau.</div>";
        } else {
            $reason = trim((string) ($result['reason'] ?? ''));
            $suffix = $reason !== '' ? ' (' . e($reason) . ')' : '';
            $message = "<div class='alert error'>Auto-matching : aucune affectation réalisée{$suffix}.</div>";
        }
    }

    if ($isAdmin && isset($_POST['auto_match_week'])) {
        $weekKey = (string) ($_POST['week'] ?? $selectedWeekKey);
        if (!isset($weekOptions[$weekKey])) {
            $weekKey = $selectedWeekKey;
        }

        $weekToMatch = $weekOptions[$weekKey];
        $weekRequestStmt = $db->prepare(
            "SELECT id
             FROM interim_shift_requests
             WHERE shift_date BETWEEN ? AND ?
               AND validation_status = 'approved'
             ORDER BY shift_date ASC, department_name ASC, time_slot ASC"
        );
        $weekRequestStmt->execute([
            $weekToMatch['start']->format('Y-m-d'),
            $weekToMatch['end']->format('Y-m-d'),
        ]);

        $totalAssigned = 0;
        $processed = 0;
        foreach ($weekRequestStmt->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
            $processed++;
            $result = interimAutoAssignRequest($db, (int) $requestId, $currentUserId, true, $agencyName);
            $totalAssigned += (int) ($result['assigned'] ?? 0);
        }

        if ($totalAssigned > 0) {
            $message = "<div class='alert success'>Auto-matching semaine terminé : {$totalAssigned} place(s) affectée(s) sur {$processed} créneau(x).</div>";
        } else {
            $message = "<div class='alert error'>Auto-matching semaine : aucune nouvelle affectation.</div>";
        }
    }

    if (isset($_POST['assign_student'])) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $studentId = 0;
        $externalName = '';
        $isExternal = false;
        $ambiguousName = false;

        if ($matchingMode === 'name') {
            $studentName = trim((string) ($_POST['student_name'] ?? ''));
            if ($studentName !== '') {
                $studentSearchStmt = $db->prepare(
                    "SELECT id, nom, prenom, interim
                     FROM utilisateurs
                     WHERE role = 'etudiant'
                       AND (
                            LOWER(CONCAT(TRIM(prenom), ' ', TRIM(nom))) = LOWER(?)
                         OR LOWER(CONCAT(TRIM(nom), ' ', TRIM(prenom))) = LOWER(?)
                         OR LOWER(CONCAT(TRIM(prenom), ' ', TRIM(nom))) LIKE LOWER(?)
                         OR LOWER(CONCAT(TRIM(nom), ' ', TRIM(prenom))) LIKE LOWER(?)
                       )
                     ORDER BY nom ASC, prenom ASC
                     LIMIT 5"
                );
                $likeTerm = $studentName . '%';
                $studentSearchStmt->execute([$studentName, $studentName, $likeTerm, $likeTerm]);
                $candidateRows = $studentSearchStmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($candidateRows) === 1) {
                    $studentId = (int) $candidateRows[0]['id'];
                } elseif (count($candidateRows) > 1) {
                    $exactMatches = array_filter($candidateRows, static function ($row) use ($studentName) {
                        $full1 = trim((string) $row['prenom'] . ' ' . $row['nom']);
                        $full2 = trim((string) $row['nom'] . ' ' . $row['prenom']);
                        return strcasecmp($full1, $studentName) === 0 || strcasecmp($full2, $studentName) === 0;
                    });
                    if (count($exactMatches) === 1) {
                        $studentId = (int) array_values($exactMatches)[0]['id'];
                    } else {
                        // Plusieurs inscrits correspondent sans nom complet unique : on demande de preciser.
                        $ambiguousName = true;
                    }
                }

                // Aucun inscrit ne correspond : on affecte la personne en texte libre (version A).
                if ($studentId <= 0 && !$ambiguousName) {
                    $isExternal = true;
                    $externalName = $studentName;
                }
            }
        } else {
            $studentId = (int) ($_POST['student_id'] ?? 0);
        }

        $confirmAssign = isset($_POST['confirm_assign']);

        // === Avertissements "soft" en mode par nom : on demande confirmation (modale Oui/Non) au lieu de bloquer/affecter ===
        if ($matchingMode === 'name' && !$ambiguousName && $requestId > 0 && ($studentId > 0 || $isExternal) && !$confirmAssign) {
            $confInfoStmt = $db->prepare('SELECT shift_date FROM interim_shift_requests WHERE id = ? LIMIT 1');
            $confInfoStmt->execute([$requestId]);
            $confShiftDate = (string) $confInfoStmt->fetchColumn();
            $confReasons = [];

            if ($confShiftDate !== '') {
                if ($isExternal) {
                    // Personne non inscrite deja affectee a un autre creneau ce jour-la
                    $confExtDayStmt = $db->prepare(
                        "SELECT COUNT(*)
                         FROM interim_shift_assignments a
                         INNER JOIN interim_shift_requests r ON r.id = a.request_id
                         WHERE r.shift_date = ?
                           AND a.request_id <> ?
                           AND a.student_id IS NULL
                           AND LOWER(TRIM(a.external_name)) = LOWER(?)"
                    );
                    $confExtDayStmt->execute([$confShiftDate, $requestId, $externalName]);
                    if ((int) $confExtDayStmt->fetchColumn() > 0) {
                        $confReasons[] = fjhT(
                            'Cette personne est déjà affectée à un autre créneau ce jour-là.',
                            'Deze persoon is die dag al aan een ander tijdsblok toegewezen.'
                        );
                    }
                } else {
                    // Inscrit : disponibilites non renseignees ?
                    $confAvailStmt = $db->prepare(
                        'SELECT availability_status FROM student_availabilities WHERE user_id = ? AND availability_date = ? LIMIT 1'
                    );
                    $confAvailStmt->execute([$studentId, $confShiftDate]);
                    $confAvail = (string) $confAvailStmt->fetchColumn();
                    if ($confAvail === 'matin') {
                        $confAvail = 'non_renseigne';
                    }
                    if ($confAvail === '' || $confAvail === 'non_renseigne') {
                        $confReasons[] = fjhT(
                            "Cette personne n'a pas renseigné ses disponibilités pour ce jour.",
                            'Deze persoon heeft zijn/haar beschikbaarheid voor deze dag niet doorgegeven.'
                        );
                    }
                    // Inscrit : deja affecte a un autre creneau ce jour-la ?
                    $confDayStmt = $db->prepare(
                        "SELECT COUNT(*)
                         FROM interim_shift_assignments a
                         INNER JOIN interim_shift_requests r ON r.id = a.request_id
                         WHERE a.student_id = ?
                           AND r.shift_date = ?
                           AND a.request_id <> ?"
                    );
                    $confDayStmt->execute([$studentId, $confShiftDate, $requestId]);
                    if ((int) $confDayStmt->fetchColumn() > 0) {
                        $confReasons[] = fjhT(
                            'Cette personne est déjà affectée à un autre créneau ce jour-là.',
                            'Deze persoon is die dag al aan een ander tijdsblok toegewezen.'
                        );
                    }
                }
            }

            if (!empty($confReasons)) {
                $pendingConfirm = [
                    'message' => implode(' ', $confReasons) . ' ' . fjhT(
                        "Êtes-vous sûr de vouloir l'affecter quand même ?",
                        'Weet u zeker dat u deze persoon toch wilt toewijzen?'
                    ),
                    'request_id' => $requestId,
                    'student_name' => $studentName,
                ];
            }
        }

        if ($pendingConfirm !== null) {
            // Confirmation requise : la modale sera affichee, on n'affecte pas encore.
        } elseif ($ambiguousName) {
            $message = "<div class='alert error'>Plusieurs étudiants correspondent à ce nom. Précisez le nom complet ou utilisez l'onglet Matching par liste.</div>";
        } elseif ($requestId <= 0 || ($studentId <= 0 && !$isExternal)) {
            $message = "<div class='alert error'>Sélection étudiant invalide.</div>";
        } elseif ($isExternal) {
            // === Affectation d'une personne non inscrite sur le site ===
            try {
                $db->beginTransaction();

                $requestLockStmt = $db->prepare(
                    'SELECT id, seats_required FROM interim_shift_requests WHERE id = ? LIMIT 1 FOR UPDATE'
                );
                $requestLockStmt->execute([$requestId]);
                $requestRow = $requestLockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$requestRow) {
                    throw new RuntimeException('Demande introuvable.');
                }

                $assignedSeatsStmt = $db->prepare(
                    'SELECT seat_number FROM interim_shift_assignments WHERE request_id = ? ORDER BY seat_number ASC FOR UPDATE'
                );
                $assignedSeatsStmt->execute([$requestId]);
                $assignedSeats = array_map('intval', $assignedSeatsStmt->fetchAll(PDO::FETCH_COLUMN));

                $seatsRequired = (int) $requestRow['seats_required'];
                $nextSeat = null;
                for ($i = 1; $i <= $seatsRequired; $i++) {
                    if (!in_array($i, $assignedSeats, true)) {
                        $nextSeat = $i;
                        break;
                    }
                }

                if ($nextSeat === null) {
                    throw new RuntimeException('Ce créneau est déjà complet.');
                }

                // Eviter d'ajouter deux fois la meme personne externe sur ce creneau.
                $dupExternalStmt = $db->prepare(
                    "SELECT COUNT(*) FROM interim_shift_assignments
                     WHERE request_id = ? AND student_id IS NULL AND LOWER(TRIM(external_name)) = LOWER(?)"
                );
                $dupExternalStmt->execute([$requestId, $externalName]);
                if ((int) $dupExternalStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Cette personne est déjà assignée sur ce créneau.');
                }

                $insertAssignStmt = $db->prepare(
                    'INSERT INTO interim_shift_assignments (request_id, seat_number, student_id, external_name, assigned_by_user_id, agency_name) VALUES (?, ?, NULL, ?, ?, ?)'
                );
                $insertAssignStmt->execute([
                    $requestId,
                    $nextSeat,
                    $externalName,
                    $currentUserId,
                    $isAdmin ? '' : $agencyName,
                ]);

                $db->commit();
                $message = "<div class='alert success'>Personne (non inscrite) assignée avec succès.</div>";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "<div class='alert error'>" . e($e->getMessage()) . "</div>";
            }
        } else {
            $rhBlockMessage = fjhT(
                "Ce n'est pas possible : cette personne n'est pas disponible pour ce creneau et l'ajouter poserait probleme au niveau du planning. Si vous avez vraiment besoin d'elle, merci de voir directement avec les RH.",
                'Dit is niet mogelijk: deze persoon is niet beschikbaar voor dit tijdsblok en toevoegen zou voor problemen in de planning zorgen. Heeft u deze persoon echt nodig, neem dan rechtstreeks contact op met HR.'
            );
            try {
                $db->beginTransaction();

                $requestLockStmt = $db->prepare(
                    'SELECT id, seats_required, shift_date, time_slot FROM interim_shift_requests WHERE id = ? LIMIT 1 FOR UPDATE'
                );
                $requestLockStmt->execute([$requestId]);
                $requestRow = $requestLockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$requestRow) {
                    throw new RuntimeException('Demande introuvable.');
                }

                $studentStmt = $db->prepare(
                    "SELECT id, nom, prenom, interim
                     FROM utilisateurs
                     WHERE id = ? AND role = 'etudiant'
                     LIMIT 1"
                );
                $studentStmt->execute([$studentId]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$student) {
                    throw new RuntimeException('Étudiant invalide.');
                }

                $studentAvailabilityStmt = $db->prepare(
                    'SELECT availability_status FROM student_availabilities WHERE user_id = ? AND availability_date = ? LIMIT 1'
                );
                $studentAvailabilityStmt->execute([
                    $studentId,
                    (string) ($requestRow['shift_date'] ?? ''),
                ]);
                $studentAvailabilityStatus = (string) $studentAvailabilityStmt->fetchColumn();
                if ($studentAvailabilityStatus === 'matin') {
                    $studentAvailabilityStatus = 'non_renseigne';
                }

                if ($studentAvailabilityStatus === 'indisponible') {
                    throw new RuntimeException($rhBlockMessage);
                }

                $studentInterim = trim((string) ($student['interim'] ?? ''));
                if (!$isAdmin && ($studentInterim === '' || $studentInterim !== $agencyName)) {
                    throw new RuntimeException('Cet étudiant ne fait pas partie de votre agence.');
                }

                $sameDayAssignmentStmt = $db->prepare(
                    "SELECT COUNT(*)
                     FROM interim_shift_assignments a
                     INNER JOIN interim_shift_requests r ON r.id = a.request_id
                     WHERE a.student_id = ?
                       AND r.shift_date = ?"
                );
                $sameDayAssignmentStmt->execute([
                    $studentId,
                    (string) ($requestRow['shift_date'] ?? ''),
                ]);
                // En mode par nom, "deja affecte ce jour" est gere en amont par une confirmation (modale), pas un blocage.
                if ($matchingMode !== 'name' && (int) $sameDayAssignmentStmt->fetchColumn() > 0) {
                    throw new RuntimeException($rhBlockMessage);
                }

                // Vérification 45h/semaine
                $manualShiftDuration = interimParseTimeSlotDuration((string) ($requestRow['time_slot'] ?? ''));
                if ($manualShiftDuration > 0) {
                    $manualWeeklyStmt = $db->prepare(
                        "SELECT COALESCE(SUM(r2.time_slot), '') AS slots
                         FROM interim_shift_assignments a2
                         INNER JOIN interim_shift_requests r2 ON r2.id = a2.request_id
                         WHERE a2.student_id = ?
                           AND YEARWEEK(r2.shift_date, 1) = YEARWEEK(?, 1)"
                    );
                    $manualWeeklyStmt->execute([$studentId, (string) ($requestRow['shift_date'] ?? '')]);
                    $manualWeeklySlots = $db->prepare(
                        "SELECT r2.time_slot
                         FROM interim_shift_assignments a2
                         INNER JOIN interim_shift_requests r2 ON r2.id = a2.request_id
                         WHERE a2.student_id = ?
                           AND YEARWEEK(r2.shift_date, 1) = YEARWEEK(?, 1)"
                    );
                    $manualWeeklySlots->execute([$studentId, (string) ($requestRow['shift_date'] ?? '')]);
                    $manualTotalMinutes = $manualShiftDuration;
                    foreach ($manualWeeklySlots->fetchAll(PDO::FETCH_COLUMN) as $slot) {
                        $manualTotalMinutes += interimParseTimeSlotDuration((string) $slot);
                    }
                    if ($manualTotalMinutes > 45 * 60) {
                        throw new RuntimeException($rhBlockMessage);
                    }
                }

                // Vérification 6 jours consécutifs
                $manualShiftDate = (string) ($requestRow['shift_date'] ?? '');
                $manualWindowStart = (new DateTimeImmutable($manualShiftDate))->modify('-13 days')->format('Y-m-d');
                $manualDatesStmt = $db->prepare(
                    "SELECT r2.shift_date
                     FROM interim_shift_assignments a2
                     INNER JOIN interim_shift_requests r2 ON r2.id = a2.request_id
                     WHERE a2.student_id = ?
                       AND r2.shift_date BETWEEN ? AND ?
                     GROUP BY r2.shift_date"
                );
                $manualDatesStmt->execute([$studentId, $manualWindowStart, $manualShiftDate]);
                $manualDates = [];
                foreach ($manualDatesStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                    $manualDates[(string) $d] = true;
                }
                $manualDates[$manualShiftDate] = true;
                ksort($manualDates);
                $manualDateList = array_keys($manualDates);
                $manualMax = 1;
                $manualStreak = 1;
                for ($mdi = 1, $mdiMax = count($manualDateList); $mdi < $mdiMax; $mdi++) {
                    $mPrev = new DateTimeImmutable($manualDateList[$mdi - 1]);
                    $mCurr = new DateTimeImmutable($manualDateList[$mdi]);
                    if ((int) $mCurr->diff($mPrev)->days === 1) {
                        $manualStreak++;
                        if ($manualStreak > $manualMax) {
                            $manualMax = $manualStreak;
                        }
                    } else {
                        $manualStreak = 1;
                    }
                }
                if ($manualMax > 6) {
                    throw new RuntimeException($rhBlockMessage);
                }

                $assignedSeatsStmt = $db->prepare(
                    'SELECT seat_number FROM interim_shift_assignments WHERE request_id = ? ORDER BY seat_number ASC FOR UPDATE'
                );
                $assignedSeatsStmt->execute([$requestId]);
                $assignedSeats = array_map('intval', $assignedSeatsStmt->fetchAll(PDO::FETCH_COLUMN));

                $seatsRequired = (int) $requestRow['seats_required'];
                $nextSeat = null;
                for ($i = 1; $i <= $seatsRequired; $i++) {
                    if (!in_array($i, $assignedSeats, true)) {
                        $nextSeat = $i;
                        break;
                    }
                }

                if ($nextSeat === null) {
                    throw new RuntimeException('Ce créneau est déjà complet.');
                }

                $alreadyAssignedStmt = $db->prepare(
                    'SELECT COUNT(*) FROM interim_shift_assignments WHERE request_id = ? AND student_id = ?'
                );
                $alreadyAssignedStmt->execute([$requestId, $studentId]);
                if ((int) $alreadyAssignedStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Cet étudiant est déjà assigné sur ce créneau.');
                }

                $insertAssignStmt = $db->prepare(
                    'INSERT INTO interim_shift_assignments (request_id, seat_number, student_id, assigned_by_user_id, agency_name) VALUES (?, ?, ?, ?, ?)'
                );
                $insertAssignStmt->execute([
                    $requestId,
                    $nextSeat,
                    $studentId,
                    $currentUserId,
                    $isAdmin ? $studentInterim : $agencyName,
                ]);

                $db->commit();
                $message = "<div class='alert success'>Étudiant assigné avec succès.</div>";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "<div class='alert error'>" . e($e->getMessage()) . "</div>";
            }
        }
    }

    if (isset($_POST['unassign_student'])) {
        $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($assignmentId <= 0 || $requestId <= 0) {
            $message = "<div class='alert error'>Désaffectation invalide.</div>";
        } else {
            try {
                $db->beginTransaction();
                $assignmentStmt = $db->prepare(
                    "SELECT a.id, a.request_id,
                            CASE WHEN a.student_id IS NULL
                                 THEN TRIM(a.agency_name)
                                 ELSE COALESCE(NULLIF(TRIM(a.agency_name), ''), TRIM(u.interim))
                            END AS owner_agency
                     FROM interim_shift_assignments a
                     LEFT JOIN utilisateurs u ON u.id = a.student_id
                     WHERE a.id = ? AND a.request_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );
                $assignmentStmt->execute([$assignmentId, $requestId]);
                $assignmentRow = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$assignmentRow) {
                    throw new RuntimeException('Affectation introuvable.');
                }

                $ownerAgency = trim((string) ($assignmentRow['owner_agency'] ?? ''));
                if (!$isAdmin && ($ownerAgency === '' || $ownerAgency !== $agencyName)) {
                    throw new RuntimeException('Vous ne pouvez retirer que vos propres affectations.');
                }

                $deleteStmt = $db->prepare('DELETE FROM interim_shift_assignments WHERE id = ? AND request_id = ?');
                $deleteStmt->execute([$assignmentId, $requestId]);
                if ($deleteStmt->rowCount() <= 0) {
                    throw new RuntimeException('Désaffectation non effectuée.');
                }

                $db->commit();
                $message = "<div class='alert success'>Étudiant désaffecté du créneau.</div>";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "<div class='alert error'>" . e($e->getMessage()) . "</div>";
            }
        }
    }
}

$requestsStmt = $db->prepare(
    "SELECT id, shift_date, department_name, time_slot, seats_required, comment
     FROM interim_shift_requests
     WHERE shift_date BETWEEN ? AND ?
    AND validation_status = 'approved'
     ORDER BY shift_date ASC, department_name ASC, time_slot ASC"
);
$requestsStmt->execute([
    $selectedWeek['start']->format('Y-m-d'),
    $selectedWeek['end']->format('Y-m-d'),
]);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

$requestIds = array_map(static function ($row) {
    return (int) $row['id'];
}, $requests);

$assignmentsByRequest = [];
if (!empty($requestIds)) {
    $placeholders = implode(', ', array_fill(0, count($requestIds), '?'));
    $assignmentsStmt = $db->prepare(
        "SELECT a.id AS assignment_id, a.request_id, a.seat_number, a.agency_name, a.external_name,
                u.id AS student_id, u.nom, u.prenom, u.interim
         FROM interim_shift_assignments a
         LEFT JOIN utilisateurs u ON u.id = a.student_id
         WHERE a.request_id IN ($placeholders)
         ORDER BY a.request_id ASC, a.seat_number ASC"
    );
    $assignmentsStmt->execute($requestIds);

    foreach ($assignmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $requestId = (int) $assignment['request_id'];
        if (!isset($assignmentsByRequest[$requestId])) {
            $assignmentsByRequest[$requestId] = [];
        }
        $assignmentsByRequest[$requestId][] = $assignment;
    }
}

$studentOptions = [];
$studentsSql =
    "SELECT id, nom, prenom, interim
     FROM utilisateurs
     WHERE role = 'etudiant'";
$studentParams = [];

if (!$isAdmin) {
    $studentsSql .= ' AND interim = ?';
    $studentParams[] = $agencyName;
}

$studentsSql .= ' ORDER BY nom ASC, prenom ASC';
$studentsStmt = $db->prepare($studentsSql);
$studentsStmt->execute($studentParams);
foreach ($studentsStmt->fetchAll(PDO::FETCH_ASSOC) as $studentRow) {
    $sid = (int) $studentRow['id'];
    $studentOptions[$sid] = [
        'id' => $sid,
        'label' => trim((string) $studentRow['prenom']) . ' ' . trim((string) $studentRow['nom']),
        'interim' => (string) ($studentRow['interim'] ?? ''),
    ];
}

$studentAvailabilityMap = [];
if (!empty($studentOptions)) {
    $studentIds = array_keys($studentOptions);
    $placeholders = implode(', ', array_fill(0, count($studentIds), '?'));
    $params = array_merge(
        [$selectedWeek['start']->format('Y-m-d'), $selectedWeek['end']->format('Y-m-d')],
        $studentIds
    );

    $availabilityStmt = $db->prepare(
        "SELECT user_id, availability_date, availability_status
         FROM student_availabilities
         WHERE availability_date BETWEEN ? AND ?
           AND user_id IN ($placeholders)"
    );
    $availabilityStmt->execute($params);

    foreach ($availabilityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) $row['user_id'];
        $day = (string) $row['availability_date'];
        $status = (string) ($row['availability_status'] ?? 'non_renseigne');
        if ($status === 'matin') {
            $status = 'non_renseigne';
        }
        if (!isset($studentAvailabilityMap[$sid])) {
            $studentAvailabilityMap[$sid] = [];
        }
        $studentAvailabilityMap[$sid][$day] = $status;
    }
}

$statusLabels = [
    'non_renseigne' => 'Non renseigné',
    'indisponible' => 'Indisponible',
    'apres_midi' => 'Après-midi',
    'journee' => 'Journée',
];

$requestsByDate = [];
foreach ($requests as $request) {
    if ($selectedDepartmentFilter !== 'all' && (string) $request['department_name'] !== $selectedDepartmentFilter) {
        continue;
    }

    if ($selectedVueFilter !== 'all') {
        $rid = (int) $request['id'];
        $filledCount = isset($assignmentsByRequest[$rid]) ? count($assignmentsByRequest[$rid]) : 0;
        $isFull = $filledCount >= (int) $request['seats_required'];
        if ($selectedVueFilter === 'a_pourvoir' && $isFull) {
            continue;
        }
        if ($selectedVueFilter === 'attribue' && !$isFull) {
            continue;
        }
    }

    $dateKey = (string) $request['shift_date'];
    if (!isset($requestsByDate[$dateKey])) {
        $requestsByDate[$dateKey] = [];
    }
    $requestsByDate[$dateKey][] = $request;
}

$requestById = [];
foreach ($requests as $request) {
    $requestById[(int) $request['id']] = $request;
}

$remainingByDayDept = [];
foreach ($assignmentsByRequest as $rid => $assignedRows) {
    if (!isset($requestById[(int) $rid])) {
        continue;
    }
    $request = $requestById[(int) $rid];
    $dayKey = (string) $request['shift_date'];
    $deptName = (string) $request['department_name'];
    $remaining = max(0, (int) $request['seats_required'] - count($assignedRows));
    if (!isset($remainingByDayDept[$dayKey])) {
        $remainingByDayDept[$dayKey] = [];
    }
    if (!isset($remainingByDayDept[$dayKey][$deptName])) {
        $remainingByDayDept[$dayKey][$deptName] = 0;
    }
    $remainingByDayDept[$dayKey][$deptName] += $remaining;
}
foreach ($requests as $request) {
    $rid = (int) $request['id'];
    if (isset($assignmentsByRequest[$rid])) {
        continue;
    }
    $dayKey = (string) $request['shift_date'];
    $deptName = (string) $request['department_name'];
    $remaining = max(0, (int) $request['seats_required']);
    if (!isset($remainingByDayDept[$dayKey])) {
        $remainingByDayDept[$dayKey] = [];
    }
    if (!isset($remainingByDayDept[$dayKey][$deptName])) {
        $remainingByDayDept[$dayKey][$deptName] = 0;
    }
    $remainingByDayDept[$dayKey][$deptName] += $remaining;
}

$visibleWeekDays = [];
foreach ($weekDays as $weekDay) {
    $dayKey = (string) $weekDay['key'];
    if ($selectedDayFilter !== 'all' && $dayKey !== $selectedDayFilter) {
        continue;
    }

    if (!isset($requestsByDate[$dayKey]) || empty($requestsByDate[$dayKey])) {
        continue;
    }

    $visibleWeekDays[] = $weekDay;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(fjhT('Horaires Intérim', 'Interim uurroosters')); ?></title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7f6;
            --card: #ffffff;
            --line: #e6ece8;
            --text: #21362a;
            --muted: #63756a;
            --accent: #2d5a37;
            --accent-soft: #edf5ef;
            --warn: #a13e35;
            --ok: #1d6a39;
            --shadow: 0 14px 34px rgba(22, 49, 33, 0.1);
        }

        body {
            margin: 0;
            padding: 24px;
            background: var(--bg);
            font-family: 'Open Sans', sans-serif;
            color: var(--text);
        }

        .page {
            max-width: 1500px;
            margin: 0 auto;
        }

        .hero {
            background: linear-gradient(135deg, #264e35, #3f6b4d);
            color: #fff;
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 22px 24px;
            margin-bottom: 20px;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }

        .hero h1 {
            margin: 8px 0 6px;
            font-size: 1.8rem;
        }

        .hero p {
            margin: 0;
            opacity: 0.95;
            line-height: 1.5;
            max-width: 980px;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .link-pill {
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 14px;
            background: var(--card);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 16px;
            margin-bottom: 18px;
        }

        .toolbar form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cfdad3;
            border-radius: 12px;
            padding: 10px 11px;
            font-size: 0.95rem;
            font-family: inherit;
            background: #fff;
        }

        textarea {
            min-height: 96px;
            resize: vertical;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-soft {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .btn-danger {
            background: #fae4e1;
            color: var(--warn);
        }

        .alert {
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .alert.success {
            background: #dff3e3;
            color: var(--ok);
        }

        .alert.error {
            background: #fae4e1;
            color: var(--warn);
        }

        .layout {
            display: block;
        }

        .card {
            background: var(--card);
            border-radius: 18px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-head {
            background: #f7fbf8;
            border-bottom: 1px solid var(--line);
            padding: 14px 16px;
            font-weight: 700;
        }

        .card-body {
            padding: 16px;
        }

        .helper {
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.5;
        }

        .day-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            margin-bottom: 12px;
            overflow: hidden;
            background: #fff;
        }

        .day-head {
            background: #f7fbf8;
            border-bottom: 1px solid var(--line);
            padding: 10px 12px;
            font-weight: 700;
            color: #2b4f38;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .day-count {
            background: #e8f2ea;
            color: #2d5a37;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 10px 10px;
            text-align: left;
            vertical-align: top;
            font-size: 0.9rem;
        }

        th {
            background: #fbfdfb;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: 0.76rem;
        }

        .slot-meta {
            color: var(--muted);
            font-size: 0.82rem;
            margin-top: 3px;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .badge-open {
            background: #fff2d8;
            color: #8b6400;
        }

        .badge-full {
            background: #dff3e3;
            color: #1d6a39;
        }

        .fill-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }

        .assigned-list {
            margin: 0;
            padding-left: 18px;
        }

        .assigned-list li {
            margin-bottom: 4px;
        }

        .suggestion-list {
            margin: 8px 0 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.4;
        }

        .suggestion-list li {
            margin-bottom: 4px;
        }

        .recap-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .recap-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 14px;
            background: #fbfdfb;
        }

        .recap-title {
            font-weight: 700;
            color: #2b4f38;
            margin-bottom: 8px;
        }

        .recap-chevron { float:right; transition:transform .2s; }
        .recap-toggle.open .recap-chevron { transform:rotate(180deg); }
        .recap-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.88rem;
            margin-bottom: 4px;
        }

        .unassign-form {
            display: inline-block;
            margin-left: 8px;
        }

        .btn-mini {
            border: none;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            background: #ffe9d8;
            color: #8b4f00;
        }

        .empty {
            padding: 16px;
            color: var(--muted);
        }

        .fami-lang-switcher {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.26);
            border-radius: 999px;
            padding: 4px;
        }

        .fami-lang-option {
            display: inline-block;
            text-decoration: none;
            color: #ffffff;
            font-weight: 800;
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            padding: 5px 9px;
            border-radius: 999px;
        }

        .fami-lang-option.is-active {
            background: #ffffff;
            color: var(--accent);
        }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }

        .tab {
            text-decoration: none;
            border-radius: 12px 12px 0 0;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--muted);
            background: #eef3f0;
            border: 1px solid var(--line);
            border-bottom: none;
        }

        .tab.is-active {
            background: var(--card);
            color: var(--accent);
            box-shadow: 0 -3px 0 var(--accent) inset;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 40, 28, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-box {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(15, 40, 25, 0.35);
            max-width: 460px;
            width: 100%;
            padding: 24px;
        }

        .modal-title {
            font-weight: 800;
            font-size: 1.05rem;
            color: var(--accent);
            margin-bottom: 10px;
        }

        .modal-text {
            color: var(--text);
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        @media (max-width: 1200px) {
            .layout {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <div style="text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;opacity:.86;"><?php echo $isAdmin ? e(fjhT('Administration', 'Administratie')) : e(fjhT('Agence intérim', 'Interimkantoor')); ?></div>
                    <h1><?php echo e(fjhT('Horaires à pourvoir', 'In te vullen uurroosters')); ?></h1>
                </div>
                <div class="hero-actions">
                    <?php if ($isAdmin): ?>
                        <a href="interim_horaires_demandes.php" class="link-pill"><?php echo e(fjhT('Demandes horaires', 'Uurroosteraanvragen')); ?></a>
                        <a href="validation_demandes_horaires.php" class="link-pill"><?php echo e(fjhT('Validation demandes', 'Aanvragen valideren')); ?></a>
                    <?php endif; ?>
                    <a href="admin_disponibilites_etudiants.php" class="link-pill"><?php echo e(fjhT('Disponibilités étudiants', 'Beschikbaarheden studenten')); ?></a>
                    <a href="<?php echo $isAdmin ? 'index.php' : 'logout.php'; ?>" class="link-pill"><?php echo $isAdmin ? e(fjhT('Retour accueil', 'Terug naar start')) : e(fjhT('Se déconnecter', 'Uitloggen')); ?></a>
                    <?php echo famiRenderLanguageSwitcher(); ?>
                </div>
            </div>
            <p>
                <?php if ($isAdmin): ?>
                    <?php echo e(fjhT('Crée les besoins horaires en quelques lignes. Les agences voient tous les créneaux, mais ne peuvent compléter que les places encore libres.', 'Maak uurbehoeften in enkele regels. Kantoren zien alle tijdsblokken, maar kunnen alleen vrije plaatsen invullen.')); ?>
                <?php else: ?>
                    <?php echo e(fjhT('Tous les horaires à pourvoir sont visibles. Une place déjà complétée par une autre agence est verrouillée, avec anonymisation des étudiants externes à votre agence.', 'Alle in te vullen uurroosters zijn zichtbaar. Een plaats die al werd ingevuld door een ander kantoor is vergrendeld; studenten van andere kantoren worden geanonimiseerd.')); ?>
                <?php endif; ?>
            </p>
        </section>

        <?php echo $message; ?>

        <?php if ($pendingConfirm !== null): ?>
            <div class="modal-overlay" id="confirmModal">
                <div class="modal-box">
                    <div class="modal-title"><?php echo e(fjhT('Confirmation', 'Bevestiging')); ?></div>
                    <div class="modal-text"><?php echo e($pendingConfirm['message']); ?></div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-soft" onclick="document.getElementById('confirmModal').style.display='none';"><?php echo e(fjhT('Non', 'Nee')); ?></button>
                        <form method="POST" style="display:inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="assign_student" value="1">
                            <input type="hidden" name="request_id" value="<?php echo (int) $pendingConfirm['request_id']; ?>">
                            <input type="hidden" name="matching_mode" value="name">
                            <input type="hidden" name="student_name" value="<?php echo e($pendingConfirm['student_name']); ?>">
                            <input type="hidden" name="confirm_assign" value="1">
                            <button type="submit" class="btn btn-primary"><?php echo e(fjhT('Oui, affecter', 'Ja, toewijzen')); ?></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section class="toolbar">
            <form method="GET">
                <div>
                    <label for="week"><?php echo e(fjhT('Semaine', 'Week')); ?></label>
                    <select id="week" name="week">
                        <?php foreach ($weekOptions as $weekKey => $weekOption): ?>
                            <option value="<?php echo e($weekKey); ?>" <?php echo $selectedWeekKey === $weekKey ? 'selected' : ''; ?>><?php echo e($weekOption['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="day"><?php echo e(fjhT('Jour', 'Dag')); ?></label>
                    <select id="day" name="day">
                        <option value="all" <?php echo $selectedDayFilter === 'all' ? 'selected' : ''; ?>><?php echo e(fjhT('Tous les jours', 'Alle dagen')); ?></option>
                        <?php foreach ($weekDays as $weekDay): ?>
                            <option value="<?php echo e($weekDay['key']); ?>" <?php echo $selectedDayFilter === $weekDay['key'] ? 'selected' : ''; ?>>
                                <?php echo e($weekDay['label']); ?> (<?php echo e($weekDay['date']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="department"><?php echo e(fjhT('Département', 'Afdeling')); ?></label>
                    <select id="department" name="department">
                        <option value="all" <?php echo $selectedDepartmentFilter === 'all' ? 'selected' : ''; ?>><?php echo e(fjhT('Tous les départements', 'Alle afdelingen')); ?></option>
                        <?php foreach ($departmentFilterOptions as $departmentFilterName): ?>
                            <option value="<?php echo e($departmentFilterName); ?>" <?php echo $selectedDepartmentFilter === $departmentFilterName ? 'selected' : ''; ?>>
                                <?php echo e($departmentFilterName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="vue"><?php echo e(fjhT('Vue', 'Weergave')); ?></label>
                    <select id="vue" name="vue">
                        <option value="all" <?php echo $selectedVueFilter === 'all' ? 'selected' : ''; ?>><?php echo e(fjhT('Tous les horaires', 'Alle uurroosters')); ?></option>
                        <option value="a_pourvoir" <?php echo $selectedVueFilter === 'a_pourvoir' ? 'selected' : ''; ?>><?php echo e(fjhT('Encore à pourvoir', 'Nog in te vullen')); ?></option>
                        <option value="attribue" <?php echo $selectedVueFilter === 'attribue' ? 'selected' : ''; ?>><?php echo e(fjhT('Déjà attribués', 'Reeds toegewezen')); ?></option>
                    </select>
                </div>
                <input type="hidden" name="matching_mode" value="<?php echo e($matchingMode); ?>">
                <button type="submit" class="btn btn-soft"><?php echo e(fjhT('Afficher', 'Tonen')); ?></button>
            </form>
            <?php if ($isAdmin): ?>
                <form method="POST" style="display:flex;align-items:end;gap:10px;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="auto_match_week" value="1">
                    <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">
                    <input type="hidden" name="matching_mode" value="<?php echo e($matchingMode); ?>">
                    <button type="submit" class="btn btn-primary"><?php echo e(fjhT('Auto-matching semaine', 'Automatische matching week')); ?></button>
                </form>
            <?php endif; ?>
            <div style="text-align:right;color:var(--muted);line-height:1.5;">
                <strong><?php echo e(fjhT('Période', 'Periode')); ?></strong><br>
                <?php echo $selectedWeek['start']->format('d/m/Y'); ?> - <?php echo $selectedWeek['end']->format('d/m/Y'); ?>
            </div>
        </section>

        <section class="tabs">
            <?php
                $tabQuery = http_build_query([
                    'week' => $selectedWeekKey,
                    'day' => $selectedDayFilter,
                    'department' => $selectedDepartmentFilter,
                    'vue' => $selectedVueFilter,
                ]);
            ?>
            <a href="?<?php echo $tabQuery . '&matching_mode=name'; ?>" class="tab <?php echo $matchingMode === 'name' ? 'is-active' : ''; ?>"><?php echo e(fjhT('Matching par nom', 'Matching op naam')); ?></a>
            <a href="?<?php echo $tabQuery . '&matching_mode=list'; ?>" class="tab <?php echo $matchingMode === 'list' ? 'is-active' : ''; ?>"><?php echo e(fjhT('Matching par liste', 'Matching per lijst')); ?></a>
        </section>

        <section class="layout">
            <div>
                <?php if (!empty($remainingByDayDept)): ?>
                    <section class="card" style="margin-bottom:16px;">
                        <div class="card-head recap-toggle" onclick="toggleRecap(this)" style="cursor:pointer;user-select:none;"><?php echo e(fjhT('Récap des horaires à pourvoir (reste à couvrir)', 'Overzicht van in te vullen uurroosters (nog te dekken)')); ?> <span class="recap-chevron">&#9660;</span></div>
                        <div class="card-body recap-body" style="display:none;">
                            <div class="recap-grid">
                                <?php foreach ($weekDays as $weekDay): ?>
                                    <?php $dayRecap = $remainingByDayDept[$weekDay['key']] ?? []; ?>
                                    <?php if (empty($dayRecap)): ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <div class="recap-card">
                                        <div class="recap-title"><?php echo e($weekDay['label']); ?> <?php echo e($weekDay['date']); ?></div>
                                        <?php foreach ($dayRecap as $deptName => $remainingTotal): ?>
                                            <div class="recap-row">
                                                <span><?php echo e($deptName); ?></span>
                                                <strong><?php echo (int) $remainingTotal; ?> <?php echo e(fjhT('poste(s)', 'plaats(en)')); ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (empty($visibleWeekDays)): ?>
                    <section class="day-card">
                        <div class="empty"><?php echo e(fjhT('Aucun créneau à afficher pour les filtres sélectionnés.', 'Geen tijdsblok om te tonen voor de geselecteerde filters.')); ?></div>
                    </section>
                <?php endif; ?>

                <?php foreach ($visibleWeekDays as $weekDay): ?>
                    <?php
                    $dayRequests = $requestsByDate[$weekDay['key']] ?? [];
                    ?>
                    <section class="day-card">
                        <div class="day-head">
                            <span><?php echo e($weekDay['label']); ?> <?php echo e($weekDay['date']); ?></span>
                            <span class="day-count"><?php echo count($dayRequests); ?> <?php echo e(fjhT('demande(s)', 'aanvraag/aanvragen')); ?></span>
                        </div>

                        <?php if (!empty($dayRequests)): ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th><?php echo e(fjhT('Département / Horaire', 'Afdeling / Uurrooster')); ?></th>
                                            <th><?php echo e(fjhT('État', 'Status')); ?></th>
                                            <th><?php echo e(fjhT('Affectations', 'Toewijzingen')); ?></th>
                                            <th><?php echo e(fjhT('Action', 'Actie')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayRequests as $request): ?>
                                            <?php
                                            $requestId = (int) $request['id'];
                                            $seatsRequired = (int) $request['seats_required'];
                                            $assignments = $assignmentsByRequest[$requestId] ?? [];
                                            $filledSeats = count($assignments);
                                            $remainingSeats = max(0, $seatsRequired - $filledSeats);
                                            $isFull = ($remainingSeats === 0);
                                            $rankedCandidates = !$isFull
                                                ? interimGetRankedCandidatesForRequest($db, $request, $isAdmin, $agencyName)
                                                : [];
                                            $eligibleCandidates = array_values(array_filter($rankedCandidates, static function ($candidate) {
                                                return !empty($candidate['eligible']);
                                            }));
                                            $manualEligibleCandidates = array_values(array_filter($rankedCandidates, static function ($candidate) {
                                                return !empty($candidate['manual_eligible']);
                                            }));
                                            $topSuggestions = array_slice($eligibleCandidates, 0, 3);
                                            $hasManualEligibleCandidates = !empty($manualEligibleCandidates);
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo e($request['department_name']); ?></strong>
                                                    <div class="slot-meta"><?php echo e($request['time_slot']); ?></div>
                                                    <?php if (!empty($request['comment'])): ?>
                                                        <div class="slot-meta"><?php echo e($request['comment']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="badges">
                                                        <span class="badge <?php echo $isFull ? 'badge-full' : 'badge-open'; ?>">
                                                            <?php echo $filledSeats; ?> / <?php echo $seatsRequired; ?> pourvu(s)
                                                        </span>
                                                        <?php if (!$isFull): ?>
                                                            <span class="badge badge-open"><?php echo $remainingSeats; ?> place(s) libre(s)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (empty($assignments)): ?>
                                                        <span class="slot-meta">Aucun étudiant assigné</span>
                                                    <?php else: ?>
                                                        <ul class="assigned-list">
                                                            <?php foreach ($assignments as $assignment): ?>
                                                                <?php
                                                                $isExternalAssign = empty($assignment['student_id']);
                                                                if ($isExternalAssign) {
                                                                    $studentName = trim((string) ($assignment['external_name'] ?? ''));
                                                                    $studentAgency = trim((string) ($assignment['agency_name'] ?? ''));
                                                                } else {
                                                                    $studentName = trim((string) ($assignment['prenom'] ?? '')) . ' ' . trim((string) ($assignment['nom'] ?? ''));
                                                                    $studentAgency = trim((string) ($assignment['interim'] ?? ''));
                                                                }
                                                                $canSeeIdentity = $isAdmin || ($studentAgency !== '' && $studentAgency === $agencyName);
                                                                $canUnassign = $canSeeIdentity;
                                                                ?>
                                                                <li>
                                                                    <?php if ($canSeeIdentity): ?>
                                                                        <?php echo e($studentName); ?>
                                                                        <?php if ($isExternalAssign): ?>
                                                                            <span class="badge badge-open" style="margin-left:4px;"><?php echo e(fjhT('externe', 'extern')); ?></span>
                                                                        <?php endif; ?>
                                                                        <?php if ($isAdmin): ?>
                                                                            <span class="slot-meta">(<?php echo e($studentAgency !== '' ? $studentAgency : ($isExternalAssign ? 'Non inscrit' : 'Sans agence')); ?>)</span>
                                                                        <?php endif; ?>
                                                                        <?php if ($canUnassign): ?>
                                                                            <form method="POST" class="unassign-form" onsubmit="return confirm('Retirer cet étudiant de ce créneau ?');">
                                                                                <?php echo csrfField(); ?>
                                                                                <input type="hidden" name="unassign_student" value="1">
                                                                                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                                                                <input type="hidden" name="assignment_id" value="<?php echo (int) ($assignment['assignment_id'] ?? 0); ?>">
                                                                                <button type="submit" class="btn-mini">Désaffecter</button>
                                                                            </form>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        Pourvu (autre agence)
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!$isFull): ?>
                                                        <?php if ($matchingMode === 'list'): ?>
                                                        <?php if (!empty($topSuggestions)): ?>
                                                            <ul class="suggestion-list">
                                                                <?php foreach ($topSuggestions as $suggestion): ?>
                                                                    <?php
                                                                    $availabilityLabel = $statusLabels[$suggestion['availability_status']] ?? $suggestion['availability_status'];
                                                                    ?>
                                                                    <li>
                                                                        <?php echo e($suggestion['name']); ?>
                                                                        <?php if ($isAdmin): ?>
                                                                            (P<?php echo (int) $suggestion['priority_rank']; ?> - <?php echo e($availabilityLabel); ?>)
                                                                        <?php else: ?>
                                                                            (P<?php echo (int) $suggestion['priority_rank']; ?>)
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php else: ?>
                                                            <div class="slot-meta">Aucun candidat compatible (dispo + département).</div>
                                                        <?php endif; ?>
                                                        <?php endif; ?>

                                                        <form method="POST" class="fill-form">
                                                            <?php echo csrfField(); ?>
                                                            <input type="hidden" name="assign_student" value="1">
                                                            <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                                            <input type="hidden" name="matching_mode" value="<?php echo e($matchingMode); ?>">
                                                            <?php if ($matchingMode === 'name'): ?>
                                                                <div style="font-weight:600;margin-bottom:8px;line-height:1.35;"><?php echo e(fjhT('Entrez le nom et prénom de la personne que vous souhaitez pour cette demande', 'Voer de naam en voornaam in van de gewenste persoon voor deze aanvraag')); ?></div>
                                                                <input type="text" name="student_name" placeholder="<?php echo e(fjhT('Nom et prénom', 'Naam en voornaam')); ?>" autocomplete="off" style="width:100%;padding:22px 18px;font-size:1.35rem;margin-bottom:10px;" required>
                                                            <?php else: ?>
                                                                <select name="student_id" required>
                                                                    <option value=""><?php echo $hasManualEligibleCandidates ? 'Choisir étudiant' : 'Aucun étudiant éligible'; ?></option>
                                                                    <?php foreach ($rankedCandidates as $candidate): ?>
                                                                    <?php
                                                                    $candidateStatusLabel = $statusLabels[$candidate['availability_status']] ?? $candidate['availability_status'];
                                                                    $candidateReason = trim((string) ($candidate['manual_reason'] ?? ''));
                                                                    $candidateLabel = $candidate['name']
                                                                        . ' - P' . (int) $candidate['priority_rank']
                                                                        . ' - ' . $candidateStatusLabel;
                                                                    if (!empty($candidate['manual_eligible']) && empty($candidate['eligible'])) {
                                                                        $candidateLabel .= ' (manuel uniquement)';
                                                                    }
                                                                    if ($candidateReason !== '') {
                                                                        $candidateLabel .= ' (' . $candidateReason . ')';
                                                                    }
                                                                    ?>
                                                                    <option value="<?php echo (int) $candidate['id']; ?>" <?php echo !empty($candidate['manual_eligible']) ? '' : 'disabled'; ?>>
                                                                        <?php echo e($candidateLabel); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                                </select>
                                                            <?php endif; ?>
                                                            <button type="submit" class="btn btn-primary" <?php echo ($matchingMode === 'list' && !$hasManualEligibleCandidates) ? 'disabled' : ''; ?>>Affecter</button>
                                                        </form>

                                                        <?php if ($isAdmin): ?>
                                                            <form method="POST" style="margin-top:8px;">
                                                                <?php echo csrfField(); ?>
                                                                <input type="hidden" name="auto_match_request" value="1">
                                                                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                                                <button type="submit" class="btn btn-soft" style="width:100%;">Auto-matching créneau</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="slot-meta">Créneau verrouillé (complet)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
<script>
function toggleRecap(el) {
    el.classList.toggle('open');
    var body = el.nextElementSibling;
    body.style.display = body.style.display === 'none' ? '' : 'none';
}
</script>
</body>
</html>
