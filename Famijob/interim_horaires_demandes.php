<?php
require_once 'config.php';
verifierConnexion($db);

$pageLang = famiLang();
if (!function_exists('fjdT')) {
    function fjdT($fr, $nl = null)
    {
        return famiLang() === 'nl' && $nl !== null ? $nl : $fr;
    }
}

// Contrôle d'accès FamiJob : admin et teamcoach uniquement
if (!in_array($_SESSION['role'] ?? '', ['admin', 'teamcoach'], true)) {
    header('Location: ../index.php');
    exit();
}

requireAdminOrTeamcoach();

ensureDepartmentsTable($db);
try {
    syncDepartmentsFromPlanningDb($db);
} catch (Exception $e) {
    // Fallback sur la table locale si la base planning est indisponible.
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_shift_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shift_date DATE NOT NULL,
        department_name VARCHAR(120) NOT NULL,
        time_slot VARCHAR(60) NOT NULL,
        seats_required SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        comment TEXT NULL,
        validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
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
    $db->exec("ALTER TABLE interim_shift_requests ADD COLUMN validation_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER comment");
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

// Blocs d'horaires predefinis (personnels a chaque utilisateur, generiques).
$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_shift_blocks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        payload TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_block_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$departmentStmt = $db->query(
    "SELECT department_name
     FROM departments
     WHERE is_active = 1
     ORDER BY department_name ASC"
);
$departmentOptions = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);
// La liste des departements est desormais 100% en base (gestion via admin_departements.php) :
// plus aucun ajout code en dur ici.

$today = new DateTimeImmutable('today');
$startMonday = $today->modify('monday this week');
$weekOptions = [];
for ($offset = 0; $offset < 8; $offset++) {
    $weekStart = $startMonday->modify('+' . $offset . ' week');
    $weekEnd = $weekStart->modify('+6 days');
    $weekOptions[$weekStart->format('Y-m-d')] = [
        'start' => $weekStart,
        'end' => $weekEnd,
        'label' => fjdT('Semaine du ', 'Week van ') . $weekStart->format('d/m/Y') . fjdT(' au ', ' tot ') . $weekEnd->format('d/m/Y'),
    ];
}

$selectedWeekKey = (string) ($_GET['week'] ?? $_POST['week'] ?? array_key_first($weekOptions));
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = array_key_first($weekOptions);
}
$selectedWeek = $weekOptions[$selectedWeekKey];

$message = '';
$createFailed = false;
$createFailedByday = false;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    if (isset($_POST['create_requests'])) {
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $globalComment = trim((string) ($_POST['global_comment'] ?? ''));

        $rowHoraire = $_POST['row_horaire'] ?? [];
        $rowNombre = $_POST['row_nombre'] ?? [];
        $rowDays = $_POST['row_days'] ?? [];
        if (!is_array($rowHoraire)) { $rowHoraire = []; }
        if (!is_array($rowNombre)) { $rowNombre = []; }
        if (!is_array($rowDays)) { $rowDays = []; }

        $weekStartStr = $selectedWeek['start']->format('Y-m-d');
        $weekEndStr = $selectedWeek['end']->format('Y-m-d');

        if (!in_array($departmentName, $departmentOptions, true)) {
            $message = "<div class='alert error'>" . e(fjdT('Département invalide. Choisis un département de la liste.', 'Ongeldige afdeling. Kies een afdeling uit de lijst.')) . "</div>";
            $createFailed = true;
        } else {
            $upsertStmt = $db->prepare(
                "INSERT INTO interim_shift_requests (shift_date, department_name, time_slot, seats_required, comment, validation_status, validated_by_user_id, validated_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, ?)
                 ON DUPLICATE KEY UPDATE
                    seats_required = VALUES(seats_required),
                    comment = VALUES(comment),
                    validation_status = 'pending',
                    validated_by_user_id = NULL,
                    validated_at = NULL,
                    updated_at = CURRENT_TIMESTAMP"
            );

            $createdCount = 0;
            $rowsWithoutDay = 0;
            $hasHoraire = false;

            foreach ($rowHoraire as $idx => $horaireRaw) {
                $timeSlot = trim((string) $horaireRaw);
                if ($timeSlot === '') {
                    continue;
                }
                $hasHoraire = true;

                $seatsRequired = max(1, (int) ($rowNombre[$idx] ?? 1));
                $seatsRequired = min($seatsRequired, 30);

                $daysForRow = $rowDays[$idx] ?? [];
                if (!is_array($daysForRow)) { $daysForRow = []; }
                $validDays = [];
                foreach ($daysForRow as $day) {
                    $day = trim((string) $day);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1
                        && $day >= $weekStartStr && $day <= $weekEndStr
                        && !in_array($day, $validDays, true)) {
                        $validDays[] = $day;
                    }
                }

                if (empty($validDays)) {
                    $rowsWithoutDay++;
                    continue;
                }

                foreach ($validDays as $day) {
                    $upsertStmt->execute([
                        $day,
                        $departmentName,
                        $timeSlot,
                        $seatsRequired,
                        $globalComment !== '' ? $globalComment : null,
                        $currentUserId,
                    ]);
                    $createdCount++;
                }
            }

            if ($createdCount > 0) {
                $message = "<div class='alert success'>" . $createdCount . ' ' . fjdT('demande(s) enregistrée(s).', 'aanvraag/aanvragen geregistreerd.')
                    . ($rowsWithoutDay > 0 ? ' ' . $rowsWithoutDay . ' ' . fjdT('horaire(s) sans jour coché ignoré(s).', 'uurrooster(s) zonder aangevinkte dag genegeerd.') : '')
                    . "</div>";
            } elseif (!$hasHoraire) {
                $message = "<div class='alert error'>" . e(fjdT('Ajoute au moins un horaire dans la grille.', 'Voeg minstens één uurrooster toe in het rooster.')) . "</div>";
                $createFailed = true;
            } else {
                $message = "<div class='alert error'>" . e(fjdT('Coche au moins un jour pour chaque horaire saisi.', 'Vink minstens één dag aan voor elk ingevoerd uurrooster.')) . "</div>";
                $createFailed = true;
            }
        }
    }

    // Onglet "ancienne méthode" : saisie par jour, un horaire par ligne (copier-coller).
    if (isset($_POST['create_requests_byday'])) {
        $departmentName = trim((string) ($_POST['department_name_byday'] ?? ''));
        $globalComment = trim((string) ($_POST['global_comment_byday'] ?? ''));

        $dayText = $_POST['day_text'] ?? [];
        if (!is_array($dayText)) { $dayText = []; }

        $weekStartStr = $selectedWeek['start']->format('Y-m-d');
        $weekEndStr = $selectedWeek['end']->format('Y-m-d');

        if (!in_array($departmentName, $departmentOptions, true)) {
            $message = "<div class='alert error'>" . e(fjdT('Département invalide. Choisis un département de la liste.', 'Ongeldige afdeling. Kies een afdeling uit de lijst.')) . "</div>";
            $createFailedByday = true;
        } else {
            $upsertBydayStmt = $db->prepare(
                "INSERT INTO interim_shift_requests (shift_date, department_name, time_slot, seats_required, comment, validation_status, validated_by_user_id, validated_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, ?)
                 ON DUPLICATE KEY UPDATE
                    seats_required = VALUES(seats_required),
                    comment = VALUES(comment),
                    validation_status = 'pending',
                    validated_by_user_id = NULL,
                    validated_at = NULL,
                    updated_at = CURRENT_TIMESTAMP"
            );

            $createdCount = 0;
            $hasSlot = false;
            foreach ($dayText as $dateKey => $content) {
                $dateKey = trim((string) $dateKey);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey) !== 1
                    || $dateKey < $weekStartStr || $dateKey > $weekEndStr) {
                    continue;
                }

                // Une ligne = un horaire. Deux lignes identiques = 2 personnes (seats).
                $lines = preg_split('/\r\n|\r|\n/', (string) $content);
                $counts = [];
                foreach ($lines as $line) {
                    $slot = trim((string) $line);
                    if ($slot === '') { continue; }
                    $slot = mb_substr($slot, 0, 60);
                    $hasSlot = true;
                    if (!isset($counts[$slot])) { $counts[$slot] = 0; }
                    $counts[$slot]++;
                }

                foreach ($counts as $slot => $cnt) {
                    $seats = max(1, min(30, (int) $cnt));
                    $upsertBydayStmt->execute([
                        $dateKey,
                        $departmentName,
                        $slot,
                        $seats,
                        $globalComment !== '' ? $globalComment : null,
                        $currentUserId,
                    ]);
                    $createdCount++;
                }
            }

            if ($createdCount > 0) {
                $message = "<div class='alert success'>" . $createdCount . ' ' . e(fjdT('créneau(x) enregistré(s).', 'tijdsblok(ken) geregistreerd.')) . "</div>";
            } elseif (!$hasSlot) {
                $message = "<div class='alert error'>" . e(fjdT('Ajoute au moins un horaire dans une journée.', 'Voeg minstens één uurrooster toe in een dag.')) . "</div>";
                $createFailedByday = true;
            }
        }
    }

    if (isset($_POST['save_block'])) {
        $blockName = trim((string) ($_POST['block_name'] ?? ''));
        $decoded = json_decode((string) ($_POST['block_payload'] ?? ''), true);
        $cleanRows = [];
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                $h = trim((string) ($r['h'] ?? ''));
                if ($h === '') {
                    continue;
                }
                $n = max(1, min(30, (int) ($r['n'] ?? 1)));
                $days = [];
                if (isset($r['days']) && is_array($r['days'])) {
                    foreach ($r['days'] as $k) {
                        $k = (int) $k;
                        if ($k >= 0 && $k <= 6 && !in_array($k, $days, true)) {
                            $days[] = $k;
                        }
                    }
                }
                $cleanRows[] = ['h' => $h, 'n' => $n, 'days' => array_values($days)];
            }
        }

        if ($blockName === '' || empty($cleanRows)) {
            $message = "<div class='alert error'>" . e(fjdT('Bloc invalide : donne un nom et au moins un horaire.', 'Ongeldig blok: geef een naam en minstens één uurrooster.')) . "</div>";
        } else {
            $insBlock = $db->prepare('INSERT INTO interim_shift_blocks (user_id, name, payload) VALUES (?, ?, ?)');
            $insBlock->execute([$currentUserId, mb_substr($blockName, 0, 120), json_encode($cleanRows)]);
            $message = "<div class='alert success'>" . e(fjdT('Bloc enregistré.', 'Blok opgeslagen.')) . "</div>";
        }
    }

    if (isset($_POST['delete_block'])) {
        $blockId = (int) ($_POST['block_id'] ?? 0);
        if ($blockId > 0) {
            $delBlock = $db->prepare('DELETE FROM interim_shift_blocks WHERE id = ? AND user_id = ?');
            $delBlock->execute([$blockId, $currentUserId]);
            $message = "<div class='alert success'>" . e(fjdT('Bloc supprimé.', 'Blok verwijderd.')) . "</div>";
        }
    }

    if (isset($_POST['delete_request'])) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM interim_shift_assignments WHERE request_id = ?')->execute([$requestId]);
                $db->prepare('DELETE FROM interim_shift_requests WHERE id = ?')->execute([$requestId]);
                $db->commit();
                $message = "<div class='alert success'>" . e(fjdT('Demande supprimée.', 'Aanvraag verwijderd.')) . "</div>";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "<div class='alert error'>" . e(fjdT('Suppression impossible.', 'Verwijderen mislukt.')) . "</div>";
            }
        }
    }
}

$requestsStmt = $db->prepare(
    "SELECT r.id, r.shift_date, r.department_name, r.time_slot, r.seats_required, r.comment, r.validation_status,
            (SELECT COUNT(*) FROM interim_shift_assignments a WHERE a.request_id = r.id) AS seats_filled
     FROM interim_shift_requests r
     WHERE r.shift_date BETWEEN ? AND ?
     ORDER BY r.shift_date ASC, r.department_name ASC, r.time_slot ASC"
);
$requestsStmt->execute([
    $selectedWeek['start']->format('Y-m-d'),
    $selectedWeek['end']->format('Y-m-d'),
]);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

$weekdayMap = [
    'Monday' => fjdT('Lundi', 'Maandag'),
    'Tuesday' => fjdT('Mardi', 'Dinsdag'),
    'Wednesday' => fjdT('Mercredi', 'Woensdag'),
    'Thursday' => fjdT('Jeudi', 'Donderdag'),
    'Friday' => fjdT('Vendredi', 'Vrijdag'),
    'Saturday' => fjdT('Samedi', 'Zaterdag'),
    'Sunday' => fjdT('Dimanche', 'Zondag'),
];

$requestsByDate = [];
foreach ($requests as $request) {
    $dateKey = (string) $request['shift_date'];
    if (!isset($requestsByDate[$dateKey])) {
        $requestsByDate[$dateKey] = [];
    }
    $requestsByDate[$dateKey][] = $request;
}

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

// Dates de la semaine (lundi -> dimanche) pour la grille et le mapping jour <-> index.
$weekDatesJs = array_map(static function ($wd) {
    return (string) $wd['key'];
}, $weekDays);
$dateToIdx = [];
foreach ($weekDays as $i => $wd) {
    $dateToIdx[(string) $wd['key']] = $i;
}

// Blocs personnels de l'utilisateur (horaires predefinis).
$userBlocks = [];
$blocksStmt = $db->prepare('SELECT id, name, payload FROM interim_shift_blocks WHERE user_id = ? ORDER BY name ASC');
$blocksStmt->execute([$currentUserId]);
foreach ($blocksStmt->fetchAll(PDO::FETCH_ASSOC) as $blockRow) {
    $payload = json_decode((string) $blockRow['payload'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $userBlocks[] = [
        'id' => (int) $blockRow['id'],
        'name' => (string) $blockRow['name'],
        'rows' => $payload,
    ];
}
$blocksForJs = [];
foreach ($userBlocks as $b) {
    $blocksForJs[(string) $b['id']] = ['name' => $b['name'], 'rows' => $b['rows']];
}

// Onglet actif (après un envoi "par jour", on y reste ; conservé aussi au changement de semaine).
$activeTab = 'grid';
if (isset($_POST['create_requests_byday'])) {
    $activeTab = 'byday';
} else {
    $requestedTab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'grid');
    if (in_array($requestedTab, ['grid', 'byday'], true)) {
        $activeTab = $requestedTab;
    }
}
$bydayText = [];
if (isset($_POST['create_requests_byday'])) {
    $rawDayText = $_POST['day_text'] ?? [];
    if (is_array($rawDayText)) {
        foreach ($rawDayText as $k => $v) {
            $bydayText[(string) $k] = (string) $v;
        }
    }
}

// Lignes a reafficher dans la grille apres un POST (erreur de creation ou enregistrement de bloc).
$initialRows = [];
if (isset($_POST['create_requests']) && $createFailed) {
    $rH = $_POST['row_horaire'] ?? [];
    $rN = $_POST['row_nombre'] ?? [];
    $rD = $_POST['row_days'] ?? [];
    if (is_array($rH)) {
        foreach ($rH as $idx => $h) {
            $h = trim((string) $h);
            if ($h === '') {
                continue;
            }
            $days = [];
            $dd = $rD[$idx] ?? [];
            if (is_array($dd)) {
                foreach ($dd as $d) {
                    if (isset($dateToIdx[(string) $d])) {
                        $days[] = $dateToIdx[(string) $d];
                    }
                }
            }
            $initialRows[] = ['h' => $h, 'n' => max(1, (int) ($rN[$idx] ?? 1)), 'days' => array_values($days)];
        }
    }
} elseif (isset($_POST['save_block'])) {
    $decoded = json_decode((string) ($_POST['block_payload'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $r) {
            $h = trim((string) ($r['h'] ?? ''));
            if ($h === '') {
                continue;
            }
            $days = [];
            if (isset($r['days']) && is_array($r['days'])) {
                foreach ($r['days'] as $k) {
                    $k = (int) $k;
                    if ($k >= 0 && $k <= 6) {
                        $days[] = $k;
                    }
                }
            }
            $initialRows[] = ['h' => $h, 'n' => max(1, (int) ($r['n'] ?? 1)), 'days' => array_values($days)];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(fjdT('Demandes Horaires Intérim', 'Interim uurroosteraanvragen')); ?></title>
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

        .page { max-width: 1500px; margin: 0 auto; }

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

        .hero h1 { margin: 8px 0 6px; font-size: 1.8rem; }
        .hero p { margin: 0; opacity: .95; line-height: 1.5; max-width: 980px; }

        .hero-actions { display: flex; gap: 10px; align-items: center; }
        .link-pill {
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 16px;
            font-weight: 700;
            background: rgba(255,255,255,.15);
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .toolbar form { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            font-weight: 700;
        }

        input, select, textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cfdad3;
            border-radius: 12px;
            padding: 10px 11px;
            font-size: .95rem;
            font-family: inherit;
            background: #fff;
        }

        textarea { min-height: 110px; resize: vertical; }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            font-size: .9rem;
        }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-soft { background: var(--accent-soft); color: var(--accent); }
        .btn-danger { background: #fae4e1; color: var(--warn); }

        .alert {
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .alert.success { background: #dff3e3; color: var(--ok); }
        .alert.error { background: #fae4e1; color: var(--warn); }

        .layout {
            display: block;
        }

        .blocks-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
        }

        .blocks-bar .blocks-label {
            font-weight: 700;
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .block-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid #cfdad3;
            border-radius: 999px;
            overflow: hidden;
            background: var(--accent-soft);
        }

        .block-chip .block-use {
            border: none;
            background: transparent;
            color: var(--accent);
            font-weight: 700;
            padding: 7px 12px;
            cursor: pointer;
            font-size: 0.86rem;
        }

        .block-chip .block-del {
            border: none;
            background: transparent;
            color: var(--warn);
            cursor: pointer;
            padding: 7px 10px 7px 4px;
            font-weight: 700;
            line-height: 1;
        }

        .block-add {
            border: 1px dashed var(--accent);
            background: #fff;
            color: var(--accent);
            border-radius: 999px;
            width: 32px;
            height: 32px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            line-height: 1;
        }

        .grid-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        .grid-table th,
        .grid-table td {
            border: 1px solid var(--line);
            padding: 6px 8px;
            text-align: center;
            font-size: 0.86rem;
        }

        .grid-table th {
            background: #f7fbf8;
            color: var(--muted);
            text-transform: none;
            letter-spacing: 0;
            font-size: 0.8rem;
        }

        .grid-table th .th-date {
            display: block;
            font-weight: 600;
            color: var(--accent);
            font-size: 0.74rem;
        }

        .grid-table td.cell-horaire { text-align: left; }
        .grid-table input[type="text"],
        .grid-table input[type="number"] {
            padding: 8px 9px;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .grid-table input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent);
        }

        .grid-table th .col-toggle {
            width: 16px;
            height: 16px;
            opacity: 0.75;
            transition: opacity .15s ease, transform .15s ease;
        }
        .grid-table th:hover .col-toggle { opacity: 1; transform: scale(1.1); }

        /* Boutons raccourcis d'une ligne (7/7, dupliquer, retirer) */
        .grid-table td.row-actions { white-space: nowrap; }
        .mini-group { display: inline-flex; gap: 6px; justify-content: center; }
        .btn-mini {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            height: 30px;
            padding: 0 9px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: #fff;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
            transition: transform .12s ease, box-shadow .12s ease, background .12s ease, color .12s ease, border-color .12s ease;
        }
        .btn-mini:hover { transform: translateY(-1px); box-shadow: 0 5px 12px rgba(22, 49, 33, 0.14); }
        .btn-mini:active { transform: translateY(0); box-shadow: none; }
        .btn-mini.mini-days { background: var(--accent-soft); color: var(--accent); border-color: #cfe3d5; }
        .btn-mini.mini-days:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
        .btn-mini.mini-dup { background: #eef3fb; color: #2f5fa8; border-color: #d5e1f3; font-size: 1rem; }
        .btn-mini.mini-dup:hover { background: #2f5fa8; color: #fff; border-color: #2f5fa8; }
        .btn-mini.mini-del { background: #fae4e1; color: var(--warn); border-color: #f1cfca; font-size: 1.1rem; }
        .btn-mini.mini-del:hover { background: var(--warn); color: #fff; border-color: var(--warn); }

        /* Onglets de mode de saisie (Grille / Par jour) */
        .mode-tabs { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
        .mode-tab {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
            padding: 9px 16px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background .12s ease, color .12s ease, border-color .12s ease;
        }
        .mode-tab:hover { border-color: var(--accent); color: var(--accent); }
        .mode-tab.is-active { background: var(--accent); color: #fff; border-color: var(--accent); }

        /* Onglet "par jour" : sélecteur de jour + une seule zone de saisie */
        .day-picker { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .day-chip {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            min-width: 78px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
            padding: 8px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: border-color .12s ease, background .12s ease, box-shadow .12s ease;
        }
        .day-chip:hover { border-color: var(--accent); box-shadow: 0 4px 10px rgba(22, 49, 33, 0.1); }
        .day-chip .day-chip-label { font-weight: 800; font-size: 0.9rem; }
        .day-chip .day-chip-date { font-size: 0.76rem; color: var(--muted); }
        .day-chip.is-active { background: var(--accent); border-color: var(--accent); }
        .day-chip.is-active .day-chip-label,
        .day-chip.is-active .day-chip-date { color: #fff; }
        .day-chip.has-content::after {
            content: '';
            position: absolute;
            top: 6px;
            right: 6px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
        }
        .day-chip.is-active.has-content::after { background: #fff; }

        .byday-editor { margin-bottom: 6px; }
        .byday-hint { color: var(--muted); font-size: 0.9rem; padding: 6px 0 2px; }
        .byday-pane textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            min-height: 170px;
            resize: vertical;
            font-family: inherit;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text);
        }
        .byday-pane textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }

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

        .card-body { padding: 16px; }

        .helper { margin-top: 10px; color: var(--muted); font-size: .86rem; line-height: 1.5; }

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
            font-size: .78rem;
            font-weight: 700;
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th, td {
            border-bottom: 1px solid var(--line);
            padding: 10px 10px;
            text-align: left;
            vertical-align: top;
            font-size: .9rem;
        }

        th {
            background: #fbfdfb;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: .76rem;
        }

        .slot-meta { color: var(--muted); font-size: .82rem; margin-top: 3px; }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .76rem;
            font-weight: 700;
            background: #eef5ef;
            color: #2d5a37;
        }

        .empty { padding: 16px; color: var(--muted); }

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

        @media (max-width: 1200px) {
            .layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . "/includes/topbar.php"; famijobRibbon($db); ?>
    <div class="page">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <div style="text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;opacity:.86;"><?php echo e(fjdT('Administration', 'Administratie')); ?></div>
                    <h1><?php echo e(fjdT('Demandes horaires', 'Uurroosteraanvragen')); ?></h1>
                </div>
                <div class="hero-actions">
                    <a href="interim_horaires.php" class="link-pill"><?php echo e(fjdT('Remplissage & matching', 'Inplannen & matching')); ?></a>
                    <a href="index.php" class="link-pill"><?php echo e(fjdT('Retour accueil', 'Terug naar start')); ?></a>
                    <?php echo famiRenderLanguageSwitcher(); ?>
                </div>
            </div>
            <p><?php echo e(fjdT("Page dédiée à la création des demandes. Le remplissage et l'auto-matching sont gérés sur la page séparée.", 'Pagina voor het aanmaken van aanvragen. Invullen en automatische matching gebeuren op een aparte pagina.')); ?></p>
        </section>

        <?php echo $message; ?>

        <section class="toolbar">
            <form method="GET">
                <input type="hidden" name="tab" id="weekTabField" value="<?php echo e($activeTab); ?>">
                <div>
                    <label for="week"><?php echo e(fjdT('Semaine (le changement s\'applique automatiquement)', 'Week (wijziging wordt automatisch toegepast)')); ?></label>
                    <select id="week" name="week" onchange="this.form.submit()">
                        <?php foreach ($weekOptions as $weekKey => $weekOption): ?>
                            <option value="<?php echo e($weekKey); ?>" <?php echo $selectedWeekKey === $weekKey ? 'selected' : ''; ?>><?php echo e($weekOption['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <noscript><button type="submit" class="btn btn-soft"><?php echo e(fjdT('Afficher', 'Tonen')); ?></button></noscript>
            </form>
            <div style="text-align:right;color:var(--muted);line-height:1.5;">
                <strong><?php echo e(fjdT('Période', 'Periode')); ?></strong><br>
                <?php echo $selectedWeek['start']->format('d/m/Y'); ?> - <?php echo $selectedWeek['end']->format('d/m/Y'); ?>
            </div>
        </section>

        <section class="layout">
            <section class="card" style="margin-bottom:18px;">
                <div class="card-head"><?php echo e(fjdT('Création rapide', 'Snel aanmaken')); ?></div>
                <div class="card-body">
                    <div class="mode-tabs">
                        <button type="button" class="mode-tab <?php echo $activeTab === 'grid' ? 'is-active' : ''; ?>" data-panel="panel-grid" onclick="switchPanel('panel-grid', this)"><?php echo e(fjdT('Grille (par horaire)', 'Rooster (per uurrooster)')); ?></button>
                        <button type="button" class="mode-tab <?php echo $activeTab === 'byday' ? 'is-active' : ''; ?>" data-panel="panel-byday" onclick="switchPanel('panel-byday', this)"><?php echo e(fjdT('Par jour (copier-coller)', 'Per dag (kopiëren-plakken)')); ?></button>
                    </div>

                    <div id="panel-grid" class="tab-panel" style="display:<?php echo $activeTab === 'grid' ? 'block' : 'none'; ?>;">
                    <form method="POST" id="createForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="create_requests" value="1">
                        <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">

                        <div style="margin-bottom:12px;max-width:420px;">
                            <label for="department_name"><?php echo e(fjdT('Département', 'Afdeling')); ?></label>
                            <select id="department_name" name="department_name" required>
                                <option value=""><?php echo e(fjdT('Sélectionner', 'Selecteren')); ?></option>
                                <?php foreach ($departmentOptions as $departmentName): ?>
                                    <option value="<?php echo e($departmentName); ?>" <?php echo (($_POST['department_name'] ?? '') === $departmentName) ? 'selected' : ''; ?>><?php echo e($departmentName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="blocks-bar">
                            <span class="blocks-label"><?php echo e(fjdT('Mes blocs :', 'Mijn blokken:')); ?></span>
                            <?php foreach ($userBlocks as $b): ?>
                                <span class="block-chip">
                                    <button type="button" class="block-use" onclick="insertBlock('<?php echo (int) $b['id']; ?>')"><?php echo e($b['name']); ?></button>
                                    <button type="button" class="block-del" title="<?php echo e(fjdT('Supprimer le bloc', 'Blok verwijderen')); ?>" onclick="deleteBlock('<?php echo (int) $b['id']; ?>')">&times;</button>
                                </span>
                            <?php endforeach; ?>
                            <button type="button" class="block-add" title="<?php echo e(fjdT('Enregistrer les lignes actuelles comme bloc', 'Huidige regels als blok opslaan')); ?>" onclick="saveBlock()">+</button>
                        </div>

                        <div class="table-wrap">
                            <table class="grid-table">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;min-width:170px;"><?php echo e(fjdT('Horaire', 'Uurrooster')); ?></th>
                                        <th style="width:80px;"><?php echo e(fjdT('Nombre', 'Aantal')); ?></th>
                                        <?php foreach ($weekDays as $dayIndex => $weekDay): ?>
                                            <th>
                                                <?php echo e($weekDay['label']); ?><span class="th-date"><?php echo e($weekDay['date']); ?></span>
                                                <input type="checkbox" class="col-toggle" data-col="<?php echo (int) $dayIndex; ?>" title="<?php echo e(fjdT('Cocher/décocher ce jour pour toutes les lignes', 'Deze dag voor alle regels aan-/uitvinken')); ?>" style="display:block;margin:4px auto 0;">
                                            </th>
                                        <?php endforeach; ?>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="gridBody"></tbody>
                            </table>
                        </div>

                        <button type="button" class="btn btn-soft" style="margin-top:10px;" onclick="addRow()">+ <?php echo e(fjdT('Ajouter un horaire', 'Uurrooster toevoegen')); ?></button>

                        <div style="margin:14px 0;max-width:420px;">
                            <label for="global_comment"><?php echo e(fjdT('Commentaire (optionnel)', 'Opmerking (optioneel)')); ?></label>
                            <input type="text" id="global_comment" name="global_comment" placeholder="<?php echo e(fjdT('Ex : renfort caisse / autonomie requise', 'Bijv.: extra kassa / zelfstandigheid vereist')); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary"><?php echo e(fjdT('Enregistrer les demandes', 'Aanvragen opslaan')); ?></button>
                    </form>

                    <p class="helper">
                        <?php echo e(fjdT('Saisis un horaire, le nombre de personnes, puis coche les jours concernés. Un seul envoi crée toutes les demandes.', 'Voer een uurrooster in, het aantal personen, en vink de betrokken dagen aan. Eén verzending maakt alle aanvragen.')); ?>
                        <br>
                        <?php echo e(fjdT('Un bloc enregistre une liste d’horaires réutilisable : clique dessus pour l’insérer, ou sur « + » pour sauvegarder les lignes actuelles.', 'Een blok bewaart een herbruikbare lijst met uurroosters: klik erop om ze in te voegen, of op « + » om de huidige regels op te slaan.')); ?>
                        <br>
                        <?php echo e(fjdT('Astuce rapidité : « 7/7 » coche toute la ligne, la case sous un jour coche toute la colonne, ⧉ duplique la ligne, et Entrée ajoute une nouvelle ligne.', 'Snelheidstip: « 7/7 » vinkt de hele regel aan, het vakje onder een dag vinkt de hele kolom aan, ⧉ dupliceert de regel, en Enter voegt een nieuwe regel toe.')); ?>
                    </p>

                    <form method="POST" id="saveBlockForm" style="display:none;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="save_block" value="1">
                        <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">
                        <input type="hidden" name="department_name" id="blockDeptField">
                        <input type="hidden" name="block_name" id="blockNameField">
                        <input type="hidden" name="block_payload" id="blockPayloadField">
                    </form>
                    <form method="POST" id="deleteBlockForm" style="display:none;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="delete_block" value="1">
                        <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">
                        <input type="hidden" name="block_id" id="deleteBlockIdField">
                    </form>
                    </div><!-- /panel-grid -->

                    <div id="panel-byday" class="tab-panel" style="display:<?php echo $activeTab === 'byday' ? 'block' : 'none'; ?>;">
                        <form method="POST" id="createFormByday">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="create_requests_byday" value="1">
                            <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">

                            <div style="margin-bottom:12px;max-width:420px;">
                                <label for="department_name_byday"><?php echo e(fjdT('Département', 'Afdeling')); ?></label>
                                <select id="department_name_byday" name="department_name_byday" required>
                                    <option value=""><?php echo e(fjdT('Sélectionner', 'Selecteren')); ?></option>
                                    <?php foreach ($departmentOptions as $bydayDept): ?>
                                        <option value="<?php echo e($bydayDept); ?>" <?php echo (($_POST['department_name_byday'] ?? '') === $bydayDept) ? 'selected' : ''; ?>><?php echo e($bydayDept); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label><?php echo e(fjdT('Jour', 'Dag')); ?></label>
                            <div class="day-picker">
                                <?php foreach ($weekDays as $weekDay): ?>
                                    <button type="button" class="day-chip" data-day="<?php echo e($weekDay['key']); ?>" onclick="selectDay('<?php echo e($weekDay['key']); ?>', this)">
                                        <span class="day-chip-label"><?php echo e($weekDay['label']); ?></span>
                                        <span class="day-chip-date"><?php echo e($weekDay['date']); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="byday-editor">
                                <div class="byday-hint" id="bydayHint"><?php echo e(fjdT('Choisis un jour ci-dessus, puis saisis un horaire par ligne.', 'Kies hierboven een dag en voer één uurrooster per regel in.')); ?></div>
                                <?php foreach ($weekDays as $weekDay): ?>
                                    <div class="byday-pane" id="pane-<?php echo e($weekDay['key']); ?>" style="display:none;">
                                        <label><?php echo e(fjdT('Horaires du', 'Uurroosters van')); ?> <?php echo e($weekDay['label']); ?> <?php echo e($weekDay['date']); ?> — <?php echo e(fjdT('un horaire par ligne', 'één uurrooster per regel')); ?></label>
                                        <textarea name="day_text[<?php echo e($weekDay['key']); ?>]" placeholder="9h30-18h30&#10;10h-19h" oninput="markDayFilled('<?php echo e($weekDay['key']); ?>')"><?php echo e($bydayText[$weekDay['key']] ?? ''); ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin:14px 0;max-width:420px;">
                                <label for="global_comment_byday"><?php echo e(fjdT('Commentaire (optionnel)', 'Opmerking (optioneel)')); ?></label>
                                <input type="text" id="global_comment_byday" name="global_comment_byday" value="<?php echo e((string) ($_POST['global_comment_byday'] ?? '')); ?>" placeholder="<?php echo e(fjdT('Ex : renfort caisse', 'Bijv.: extra kassa')); ?>">
                            </div>

                            <button type="submit" class="btn btn-primary"><?php echo e(fjdT('Enregistrer les demandes', 'Aanvragen opslaan')); ?></button>
                        </form>

                        <p class="helper">
                            <?php echo e(fjdT('Choisis le département, clique sur un jour, puis saisis tes horaires (un par ligne). Passe d’un jour à l’autre : un point vert marque les jours déjà remplis. Deux lignes identiques le même jour = 2 personnes.', 'Kies de afdeling, klik op een dag en voer je uurroosters in (één per regel). Wissel van dag: een groene stip toont de reeds ingevulde dagen. Twee identieke regels op dezelfde dag = 2 personen.')); ?>
                        </p>
                    </div><!-- /panel-byday -->
                </div>
            </section>

            <div>
                <?php foreach ($weekDays as $weekDay): ?>
                    <?php $dayRequests = $requestsByDate[$weekDay['key']] ?? []; ?>
                    <section class="day-card">
                        <div class="day-head">
                            <span><?php echo e($weekDay['label']); ?> <?php echo e($weekDay['date']); ?></span>
                            <span class="day-count"><?php echo count($dayRequests); ?> <?php echo e(fjdT('demande(s)', 'aanvraag/aanvragen')); ?></span>
                        </div>

                        <?php if (empty($dayRequests)): ?>
                            <div class="empty"><?php echo e(fjdT('Aucune demande sur cette journée.', 'Geen aanvraag op deze dag.')); ?></div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th><?php echo e(fjdT('Département / Horaire', 'Afdeling / Uurrooster')); ?></th>
                                            <th><?php echo e(fjdT('Remplissage', 'Bezetting')); ?></th>
                                            <th><?php echo e(fjdT('Validation', 'Validatie')); ?></th>
                                            <th><?php echo e(fjdT('Action', 'Actie')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayRequests as $request): ?>
                                            <?php
                                            $filled = (int) ($request['seats_filled'] ?? 0);
                                            $required = (int) ($request['seats_required'] ?? 1);
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
                                                    <span class="badge"><?php echo $filled; ?> / <?php echo $required; ?> pourvu(s)</span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $validationStatus = (string) ($request['validation_status'] ?? 'pending');
                                                    $validationLabel = fjdT('En attente', 'In afwachting');
                                                    if ($validationStatus === 'approved') {
                                                        $validationLabel = fjdT('Validée', 'Goedgekeurd');
                                                    } elseif ($validationStatus === 'rejected') {
                                                        $validationLabel = fjdT('Refusée', 'Geweigerd');
                                                    }
                                                    ?>
                                                    <span class="badge"><?php echo e($validationLabel); ?></span>
                                                </td>
                                                <td>
                                                    <form method="POST" onsubmit="return confirm('<?php echo e(fjdT('Supprimer cette demande et ses affectations ?', 'Deze aanvraag en toewijzingen verwijderen?')); ?>');">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="delete_request" value="1">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                        <button type="submit" class="btn btn-danger"><?php echo e(fjdT('Supprimer', 'Verwijderen')); ?></button>
                                                    </form>
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
(function () {
    var WEEK_DATES = <?php echo json_encode($weekDatesJs); ?>;
    var BLOCKS = <?php echo json_encode($blocksForJs); ?>;
    var INITIAL_ROWS = <?php echo json_encode($initialRows); ?>;
    var DAY_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    var T_ALLDAYS = <?php echo json_encode(fjdT('Cocher/décocher tous les jours de la ligne', 'Alle dagen van de regel aan-/uitvinken')); ?>;
    var T_DUP = <?php echo json_encode(fjdT('Dupliquer la ligne', 'Regel dupliceren')); ?>;
    var T_REMOVE = <?php echo json_encode(fjdT('Retirer la ligne', 'Regel verwijderen')); ?>;
    var rowIdx = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function dayCellsHtml(idx, checkedDays) {
        var html = '';
        for (var k = 0; k < WEEK_DATES.length; k++) {
            var on = (checkedDays && checkedDays.indexOf(k) >= 0) ? ' checked' : '';
            html += '<td><input type="checkbox" name="row_days[' + idx + '][]" value="' + esc(WEEK_DATES[k]) + '"' + on + '></td>';
        }
        return html;
    }

    function addRow(h, n, days) {
        var idx = rowIdx++;
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="cell-horaire"><input type="text" name="row_horaire[' + idx + ']" value="' + esc(h || '') + '" placeholder="9h30-12h30" style="width:100%;"></td>' +
            '<td><input type="number" min="1" max="30" name="row_nombre[' + idx + ']" value="' + (parseInt(n, 10) > 0 ? parseInt(n, 10) : 1) + '" style="width:64px;"></td>' +
            dayCellsHtml(idx, days) +
            '<td class="row-actions">' +
                '<div class="mini-group">' +
                    '<button type="button" class="btn-mini mini-days" title="' + esc(T_ALLDAYS) + '" onclick="toggleRowDays(this)">7/7</button>' +
                    '<button type="button" class="btn-mini mini-dup" title="' + esc(T_DUP) + '" onclick="duplicateRow(this)">&#10697;</button>' +
                    '<button type="button" class="btn-mini mini-del" title="' + esc(T_REMOVE) + '" onclick="this.closest(\'tr\').remove();">&times;</button>' +
                '</div>' +
            '</td>';
        document.getElementById('gridBody').appendChild(tr);
        var horaireInput = tr.querySelector('input[name^="row_horaire"]');
        if (horaireInput) {
            horaireInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addRow();
                    var allRows = document.querySelectorAll('#gridBody tr');
                    var last = allRows[allRows.length - 1];
                    var inp = last ? last.querySelector('input[name^="row_horaire"]') : null;
                    if (inp) { inp.focus(); }
                }
            });
        }
        return tr;
    }
    window.addRow = addRow;

    window.toggleRowDays = function (btn) {
        var tr = btn.closest('tr');
        if (!tr) { return; }
        var boxes = tr.querySelectorAll('input[name^="row_days"]');
        var allOn = boxes.length > 0 && Array.prototype.every.call(boxes, function (b) { return b.checked; });
        boxes.forEach(function (b) { b.checked = !allOn; });
    };

    window.duplicateRow = function (btn) {
        var tr = btn.closest('tr');
        if (!tr) { return; }
        var hi = tr.querySelector('input[name^="row_horaire"]');
        var ni = tr.querySelector('input[name^="row_nombre"]');
        var days = [];
        tr.querySelectorAll('input[name^="row_days"]').forEach(function (b, k) { if (b.checked) { days.push(k); } });
        addRow(hi ? hi.value : '', ni ? ni.value : 1, days);
    };

    // En-tête de colonne : coche/décoche ce jour pour toutes les lignes.
    document.querySelectorAll('.col-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var col = parseInt(cb.getAttribute('data-col'), 10);
            document.querySelectorAll('#gridBody tr').forEach(function (tr) {
                var dayBoxes = tr.querySelectorAll('input[name^="row_days"]');
                if (dayBoxes[col]) { dayBoxes[col].checked = cb.checked; }
            });
        });
    });

    window.selectDay = function (dayKey, btn) {
        document.querySelectorAll('#panel-byday .byday-pane').forEach(function (p) { p.style.display = 'none'; });
        var pane = document.getElementById('pane-' + dayKey);
        if (pane) {
            pane.style.display = 'block';
            var ta = pane.querySelector('textarea');
            if (ta) { ta.focus(); }
        }
        document.querySelectorAll('#panel-byday .day-chip').forEach(function (c) { c.classList.remove('is-active'); });
        if (btn) { btn.classList.add('is-active'); }
        var hint = document.getElementById('bydayHint');
        if (hint) { hint.style.display = 'none'; }
    };

    window.markDayFilled = function (dayKey) {
        var pane = document.getElementById('pane-' + dayKey);
        var ta = pane ? pane.querySelector('textarea') : null;
        var chip = document.querySelector('#panel-byday .day-chip[data-day="' + dayKey + '"]');
        if (ta && chip) {
            if (ta.value.trim() !== '') { chip.classList.add('has-content'); }
            else { chip.classList.remove('has-content'); }
        }
    };

    function bydayInit() {
        document.querySelectorAll('#panel-byday .day-chip').forEach(function (chip) {
            var pane = document.getElementById('pane-' + chip.getAttribute('data-day'));
            var ta = pane ? pane.querySelector('textarea') : null;
            if (ta && ta.value.trim() !== '') { chip.classList.add('has-content'); }
        });
    }

    function bydaySelectDefault() {
        if (document.querySelector('#panel-byday .day-chip.is-active')) { return; }
        var target = document.querySelector('#panel-byday .day-chip.has-content')
            || document.querySelector('#panel-byday .day-chip');
        if (target) { selectDay(target.getAttribute('data-day'), target); }
    }

    window.switchPanel = function (panelId, btn) {
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.style.display = 'none'; });
        var panel = document.getElementById(panelId);
        if (panel) { panel.style.display = 'block'; }
        document.querySelectorAll('.mode-tab').forEach(function (t) { t.classList.remove('is-active'); });
        if (btn) { btn.classList.add('is-active'); }
        var weekTabField = document.getElementById('weekTabField');
        if (weekTabField) { weekTabField.value = (panelId === 'panel-byday') ? 'byday' : 'grid'; }
        if (panelId === 'panel-byday') { bydaySelectDefault(); }
    };

    window.insertBlock = function (id) {
        var b = BLOCKS[id];
        if (!b || !b.rows) { return; }
        b.rows.forEach(function (r) { addRow(r.h, r.n, r.days || []); });
    };

    function collectRows() {
        var rows = [];
        document.querySelectorAll('#gridBody tr').forEach(function (tr) {
            var hi = tr.querySelector('input[name^="row_horaire"]');
            var ni = tr.querySelector('input[name^="row_nombre"]');
            if (!hi) { return; }
            var h = hi.value.trim();
            if (h === '') { return; }
            var n = parseInt(ni ? ni.value : '1', 10);
            if (!(n > 0)) { n = 1; }
            var days = [];
            tr.querySelectorAll('input[type="checkbox"]').forEach(function (cb, k) {
                if (cb.checked) { days.push(k); }
            });
            rows.push({ h: h, n: n, days: days });
        });
        return rows;
    }

    window.saveBlock = function () {
        var rows = collectRows();
        if (rows.length === 0) {
            alert(<?php echo json_encode(fjdT('Ajoute au moins un horaire avant d’enregistrer un bloc.', 'Voeg minstens één uurrooster toe voordat je een blok opslaat.')); ?>);
            return;
        }
        var name = prompt(<?php echo json_encode(fjdT('Nom du bloc ?', 'Naam van het blok?')); ?>);
        if (name === null) { return; }
        name = name.trim();
        if (name === '') { return; }
        var deptSel = document.getElementById('department_name');
        document.getElementById('blockDeptField').value = deptSel ? deptSel.value : '';
        document.getElementById('blockNameField').value = name;
        document.getElementById('blockPayloadField').value = JSON.stringify(rows);
        document.getElementById('saveBlockForm').submit();
    };

    window.deleteBlock = function (id) {
        if (!confirm(<?php echo json_encode(fjdT('Supprimer ce bloc ?', 'Dit blok verwijderen?')); ?>)) { return; }
        document.getElementById('deleteBlockIdField').value = id;
        document.getElementById('deleteBlockForm').submit();
    };

    // Lignes initiales : reaffichage apres un POST, sinon une ligne vide.
    if (INITIAL_ROWS && INITIAL_ROWS.length) {
        INITIAL_ROWS.forEach(function (r) { addRow(r.h, r.n, r.days || []); });
    } else {
        addRow();
    }

    // Onglet "par jour" : marque les jours remplis et sélectionne un jour si l'onglet est actif.
    bydayInit();
    if (<?php echo json_encode($activeTab); ?> === 'byday') {
        bydaySelectDefault();
    }
})();
</script>
</body>
</html>
