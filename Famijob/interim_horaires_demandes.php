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
    header('Location: ../public/index.php');
    exit();
}

requireAdmin();

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

$departmentStmt = $db->query(
    "SELECT department_name
     FROM departments
     WHERE is_active = 1
     ORDER BY department_name ASC"
);
$departmentOptions = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);

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

$selectedWeekKey = (string) ($_GET['week'] ?? array_key_first($weekOptions));
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = array_key_first($weekOptions);
}
$selectedWeek = $weekOptions[$selectedWeekKey];

$message = '';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    if (isset($_POST['create_requests'])) {
        $shiftDate = (string) ($_POST['shift_date'] ?? '');
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $timeSlotsRaw = trim((string) ($_POST['time_slots'] ?? ''));
        $globalComment = trim((string) ($_POST['global_comment'] ?? ''));

        if ($shiftDate === '' || $departmentName === '' || $timeSlotsRaw === '') {
            $message = "<div class='alert error'>" . e(fjdT('Tous les champs de creation sont obligatoires.', 'Alle velden voor het aanmaken zijn verplicht.')) . "</div>";
        } elseif (!in_array($departmentName, $departmentOptions, true)) {
            $message = "<div class='alert error'>" . e(fjdT('Departement invalide. Utilise un departement de la liste synchronisee.', 'Ongeldige afdeling. Gebruik een afdeling uit de gesynchroniseerde lijst.')) . "</div>";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate) !== 1) {
            $message = "<div class='alert error'>" . e(fjdT('Date invalide.', 'Ongeldige datum.')) . "</div>";
        } else {
            $lines = preg_split('/\R+/', $timeSlotsRaw) ?: [];
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
            $ignoredCount = 0;

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $timeSlot = $line;
                $seatsRequired = 1;

                if (strpos($line, '|') !== false) {
                    $parts = explode('|', $line, 2);
                    $timeSlot = trim((string) ($parts[0] ?? ''));
                    $seatsRequired = max(1, (int) trim((string) ($parts[1] ?? '1')));
                } elseif (preg_match('/^(.*?)(?:\s+[xX]\s*(\d+))$/', $line, $matches)) {
                    $timeSlot = trim((string) $matches[1]);
                    $seatsRequired = max(1, (int) $matches[2]);
                }

                if ($timeSlot === '') {
                    $ignoredCount++;
                    continue;
                }

                $seatsRequired = min($seatsRequired, 30);
                $upsertStmt->execute([
                    $shiftDate,
                    $departmentName,
                    $timeSlot,
                    $seatsRequired,
                    $globalComment !== '' ? $globalComment : null,
                    $currentUserId,
                ]);
                $createdCount++;
            }

            if ($createdCount > 0) {
                $message = "<div class='alert success'>" . $createdCount . ' ' . fjdT('demande(s) enregistree(s).', 'aanvraag/aanvragen geregistreerd.')
                    . ($ignoredCount > 0 ? ' ' . $ignoredCount . ' ' . fjdT('ligne(s) ignoree(s).', 'regel(s) genegeerd.') : '')
                    . "</div>";
            } else {
                $message = "<div class='alert error'>" . e(fjdT('Aucune ligne exploitable. Exemple: 9h30-12h30 | 2', 'Geen bruikbare regel. Voorbeeld: 9u30-12u30 | 2')) . "</div>";
            }
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
                $message = "<div class='alert success'>" . e(fjdT('Demande supprimee.', 'Aanvraag verwijderd.')) . "</div>";
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
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(fjdT('Demandes Horaires Interim', 'Interim uurroosteraanvragen')); ?></title>
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
            display: grid;
            grid-template-columns: 430px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
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
            <p><?php echo e(fjdT("Page dediee a la creation des demandes. Le remplissage et l'auto-matching sont geres sur la page separee.", 'Pagina voor het aanmaken van aanvragen. Invullen en automatische matching gebeuren op een aparte pagina.')); ?></p>
        </section>

        <?php echo $message; ?>

        <section class="toolbar">
            <form method="GET">
                <div>
                    <label for="week"><?php echo e(fjdT('Semaine', 'Week')); ?></label>
                    <select id="week" name="week">
                        <?php foreach ($weekOptions as $weekKey => $weekOption): ?>
                            <option value="<?php echo e($weekKey); ?>" <?php echo $selectedWeekKey === $weekKey ? 'selected' : ''; ?>><?php echo e($weekOption['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-soft"><?php echo e(fjdT('Afficher', 'Tonen')); ?></button>
            </form>
            <div style="text-align:right;color:var(--muted);line-height:1.5;">
                <strong><?php echo e(fjdT('Periode', 'Periode')); ?></strong><br>
                <?php echo $selectedWeek['start']->format('d/m/Y'); ?> - <?php echo $selectedWeek['end']->format('d/m/Y'); ?>
            </div>
        </section>

        <section class="layout">
            <aside class="card">
                <div class="card-head"><?php echo e(fjdT('Creation rapide', 'Snel aanmaken')); ?></div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="create_requests" value="1">

                        <div style="margin-bottom:10px;">
                            <label for="shift_date"><?php echo e(fjdT('Date', 'Datum')); ?></label>
                            <input type="date" id="shift_date" name="shift_date" required value="<?php echo e($selectedWeek['start']->format('Y-m-d')); ?>">
                        </div>

                        <div style="margin-bottom:10px;">
                            <label for="department_name"><?php echo e(fjdT('Departement', 'Afdeling')); ?></label>
                            <select id="department_name" name="department_name" required>
                                <option value=""><?php echo e(fjdT('Selectionner', 'Selecteren')); ?></option>
                                <?php foreach ($departmentOptions as $departmentName): ?>
                                    <option value="<?php echo e($departmentName); ?>"><?php echo e($departmentName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-bottom:10px;">
                            <label for="time_slots"><?php echo e(fjdT('Horaires (1 ligne = 1 demande)', 'Uurroosters (1 regel = 1 aanvraag)')); ?></label>
                            <textarea id="time_slots" name="time_slots" placeholder="9h30-12h30 | 2&#10;14h-17h | 1" required></textarea>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label for="global_comment"><?php echo e(fjdT('Commentaire (optionnel)', 'Opmerking (optioneel)')); ?></label>
                            <input type="text" id="global_comment" name="global_comment" placeholder="<?php echo e(fjdT('Ex: renfort caisse / autonomie requise', 'Bijv.: extra kassa / zelfstandigheid vereist')); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;"><?php echo e(fjdT('Enregistrer les demandes', 'Aanvragen opslaan')); ?></button>
                    </form>

                    <p class="helper">
                        <?php echo e(fjdT('Format simple: horaire | quantite', 'Eenvoudig formaat: uurrooster | aantal')); ?>
                        <br>
                        <?php echo e(fjdT('Exemple: 9h30-12h30 | 3', 'Voorbeeld: 9u30-12u30 | 3')); ?>
                        <br>
                        <?php echo e(fjdT('Une ligne identique met a jour la quantite existante.', 'Een identieke regel werkt het bestaande aantal bij.')); ?>
                    </p>
                </div>
            </aside>

            <div>
                <?php foreach ($weekDays as $weekDay): ?>
                    <?php $dayRequests = $requestsByDate[$weekDay['key']] ?? []; ?>
                    <section class="day-card">
                        <div class="day-head">
                            <span><?php echo e($weekDay['label']); ?> <?php echo e($weekDay['date']); ?></span>
                            <span class="day-count"><?php echo count($dayRequests); ?> <?php echo e(fjdT('demande(s)', 'aanvraag/aanvragen')); ?></span>
                        </div>

                        <?php if (empty($dayRequests)): ?>
                            <div class="empty"><?php echo e(fjdT('Aucune demande sur cette journee.', 'Geen aanvraag op deze dag.')); ?></div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th><?php echo e(fjdT('Departement / Horaire', 'Afdeling / Uurrooster')); ?></th>
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
                                                        $validationLabel = fjdT('Validee', 'Goedgekeurd');
                                                    } elseif ($validationStatus === 'rejected') {
                                                        $validationLabel = fjdT('Refusee', 'Geweigerd');
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
</body>
</html>
