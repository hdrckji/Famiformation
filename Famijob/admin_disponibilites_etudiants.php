<?php
require_once 'config.php';
require_once 'includes/quizz_status.php';
verifierConnexion($db);

$pageLang = famiLang();
if (!function_exists('fjaT')) {
    function fjaT($fr, $nl = null)
    {
        return famiLang() === 'nl' && $nl !== null ? $nl : $fr;
    }
}

$currentRole = getCurrentRole();
if (!in_array($currentRole, ['admin'], true)) {
    header('Location: ../public/index.php');
    exit();
}

$isAgencyInterimViewer = ($currentRole === 'agence_interim');
$agencyInterimName = '';
if ($isAgencyInterimViewer) {
    $agencyStmt = $db->prepare('SELECT interim FROM utilisateurs WHERE id = ? LIMIT 1');
    $agencyStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $agencyInterimName = trim((string) $agencyStmt->fetchColumn());
}

$canDeleteAvailability = ($currentRole === 'admin');

ensureStudentAvailabilityTable($db);
ensureDepartmentsTable($db);
ensureStudentDepartmentLinksTable($db);

try {
    syncDepartmentsFromPlanningDb($db);
} catch (Exception $e) {
    // Le repli local reste suffisant pour l'affichage admin.
}

$flashMessage = '';
if (isset($_GET['availability_deleted'])) {
    $flashMessage = ((string) $_GET['availability_deleted'] === '1')
    ? "<div class='alert success'>✅ " . e(fjaT('Disponibilité supprimée.', 'Beschikbaarheid verwijderd.')) . "</div>"
    : "<div class='alert error'>⚠️ " . e(fjaT('Aucune disponibilité à supprimer.', 'Geen beschikbaarheid om te verwijderen.')) . "</div>";
}

$statusLabels = [
    'non_renseigne' => fjaT('Non renseigné', 'Niet ingevuld'),
    'indisponible' => fjaT('Indisponible', 'Niet beschikbaar'),
    'apres_midi' => fjaT('Après-midi uniquement (à partir de 13h)', 'Enkel namiddag (vanaf 13u)'),
    'journee' => fjaT('Journée', 'Hele dag'),
];

$statusClasses = [
    'non_renseigne' => 'status-empty',
    'indisponible' => 'status-off',
    'apres_midi' => 'status-partial',
    'journee' => 'status-full',
];

$weekdayLabels = [
    'Monday' => fjaT('Lundi', 'Maandag'),
    'Tuesday' => fjaT('Mardi', 'Dinsdag'),
    'Wednesday' => fjaT('Mercredi', 'Woensdag'),
    'Thursday' => fjaT('Jeudi', 'Donderdag'),
    'Friday' => fjaT('Vendredi', 'Vrijdag'),
    'Saturday' => fjaT('Samedi', 'Zaterdag'),
    'Sunday' => fjaT('Dimanche', 'Zondag'),
];

$today = new DateTimeImmutable('today');
$startMonday = $today->modify('monday this week');
$weekOptions = [];
for ($offset = 0; $offset < 6; $offset++) {
    $weekStart = $startMonday->modify('+' . $offset . ' week');
    $weekEnd = $weekStart->modify('+6 days');
    $weekOptions[$weekStart->format('Y-m-d')] = [
        'start' => $weekStart,
        'end' => $weekEnd,
        'label' => fjaT('Semaine du ', 'Week van ') . $weekStart->format('d/m/Y') . fjaT(' au ', ' tot ') . $weekEnd->format('d/m/Y'),
    ];
}

$selectedWeekKey = (string) ($_GET['week'] ?? array_key_first($weekOptions));
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = array_key_first($weekOptions);
}

$selectedWeek = $weekOptions[$selectedWeekKey];
$weekDays = [];
$cursor = $selectedWeek['start'];
while ($cursor <= $selectedWeek['end']) {
    $weekDays[] = [
        'key' => $cursor->format('Y-m-d'),
        'label' => $weekdayLabels[$cursor->format('l')] ?? $cursor->format('l'),
        'date' => $cursor->format('d/m'),
    ];
    $cursor = $cursor->modify('+1 day');
}

$sectorStmt = $db->query(
    "SELECT department_name
     FROM departments
     WHERE is_active = 1
     ORDER BY department_name ASC"
);
$sectorOptions = $sectorStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedSector = trim((string) ($_GET['sector'] ?? 'all'));
$validSectorValues = array_merge(['all', 'none'], $sectorOptions);
if (!in_array($selectedSector, $validSectorValues, true)) {
    $selectedSector = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_availability'])) {
    if (!$canDeleteAvailability) {
        header('Location: admin_disponibilites_etudiants.php?week=' . urlencode($selectedWeekKey) . '&sector=' . urlencode($selectedSector));
        exit;
    }

    requireValidCSRF();

    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $targetDate = (string) ($_POST['availability_date'] ?? '');
    $returnWeek = (string) ($_POST['week'] ?? $selectedWeekKey);
    $returnSector = trim((string) ($_POST['sector'] ?? $selectedSector));

    if (!isset($weekOptions[$returnWeek])) {
        $returnWeek = $selectedWeekKey;
    }
    if (!in_array($returnSector, $validSectorValues, true)) {
        $returnSector = $selectedSector;
    }

    $deleted = 0;
    if ($targetUserId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1) {
        $deleteStmt = $db->prepare('DELETE FROM student_availabilities WHERE user_id = ? AND availability_date = ? LIMIT 1');
        $deleteStmt->execute([$targetUserId, $targetDate]);
        $deleted = (int) $deleteStmt->rowCount();
    }

    $redirectUrl = 'admin_disponibilites_etudiants.php?week=' . urlencode($returnWeek)
        . '&sector=' . urlencode($returnSector)
        . '&availability_deleted=' . ($deleted > 0 ? '1' : '0');
    header('Location: ' . $redirectUrl);
    exit;
}

$students = [];
if (!$isAgencyInterimViewer || $agencyInterimName !== '') {
    $studentsSql =
        "SELECT id, nom, prenom, identifiant, email, onboarding_unlocked
         FROM utilisateurs
         WHERE role = 'etudiant'";
    $studentsParams = [];

    if ($isAgencyInterimViewer) {
        $studentsSql .= " AND interim = ?";
        $studentsParams[] = $agencyInterimName;
    }

    $studentsSql .= " ORDER BY nom ASC, prenom ASC";
    $studentsStmt = $db->prepare($studentsSql);
    $studentsStmt->execute($studentsParams);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$studentIds = [];
$studentMap = [];
foreach ($students as $student) {
    $studentId = (int) $student['id'];
    if (!hasUnlockedOnboarding($studentId) || !hasCompletedOnboarding($studentId)) {
        continue;
    }

    $studentIds[] = $studentId;
    $studentMap[$studentId] = [
        'id' => $studentId,
        'nom' => (string) $student['nom'],
        'prenom' => (string) $student['prenom'],
        'identifiant' => (string) $student['identifiant'],
        'email' => (string) ($student['email'] ?? ''),
        'departments' => [],
        'availabilities' => [],
    ];
}

if (!empty($studentIds)) {
    $idPlaceholders = implode(', ', array_fill(0, count($studentIds), '?'));

    $departmentLinksStmt = $db->prepare(
        "SELECT sdl.student_id, sdl.priority_rank, d.department_name
         FROM student_department_links sdl
         INNER JOIN departments d ON d.id = sdl.department_id
         WHERE sdl.student_id IN ($idPlaceholders)
         ORDER BY sdl.student_id ASC, sdl.priority_rank ASC, d.department_name ASC"
    );
    $departmentLinksStmt->execute($studentIds);

    foreach ($departmentLinksStmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
        $studentId = (int) $link['student_id'];
        if (!isset($studentMap[$studentId])) {
            continue;
        }

        $studentMap[$studentId]['departments'][] = [
            'name' => (string) $link['department_name'],
            'priority' => (int) $link['priority_rank'],
        ];
    }

    $availabilityParams = array_merge(
        [$selectedWeek['start']->format('Y-m-d'), $selectedWeek['end']->format('Y-m-d')],
        $studentIds
    );
    $availabilityStmt = $db->prepare(
        "SELECT user_id, availability_date, availability_status, note
         FROM student_availabilities
         WHERE availability_date BETWEEN ? AND ?
           AND user_id IN ($idPlaceholders)
         ORDER BY availability_date ASC"
    );
    $availabilityStmt->execute($availabilityParams);

    foreach ($availabilityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentId = (int) $row['user_id'];
        if (!isset($studentMap[$studentId])) {
            continue;
        }

        if (($row['availability_status'] ?? '') === 'matin') {
            $row['availability_status'] = 'non_renseigne';
        }

        $studentMap[$studentId]['availabilities'][(string) $row['availability_date']] = [
            'status' => (string) $row['availability_status'],
            'note' => (string) ($row['note'] ?? ''),
            'exists' => true,
        ];
    }
}

$studentsBySector = [];
foreach ($studentMap as $student) {
    $targetSectors = [];
    foreach ($student['departments'] as $department) {
        $targetSectors[] = $department['name'];
    }

    if (empty($targetSectors)) {
        $targetSectors[] = 'none';
    }

    foreach ($targetSectors as $sectorName) {
        if ($selectedSector !== 'all' && $selectedSector !== $sectorName) {
            continue;
        }

        if (!isset($studentsBySector[$sectorName])) {
            $studentsBySector[$sectorName] = [];
        }

        $studentsBySector[$sectorName][] = $student;
    }
}

ksort($studentsBySector);
if (isset($studentsBySector['none'])) {
    $studentsBySector = ['none' => $studentsBySector['none']] + array_diff_key($studentsBySector, ['none' => true]);
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(fjaT('Disponibilités étudiants - Admin', 'Beschikbaarheden studenten - Admin')); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-dark: #214b35;
            --green-main: #2d5a37;
            --green-soft: #edf6ef;
            --green-pale: #f8fbf9;
            --gold: #d6a21a;
            --border: #d8e3db;
            --shadow: 0 12px 34px rgba(35,57,43,0.08);
            --text-soft: #607063;
        }
        body {
            margin: 0;
            font-family: 'Open Sans', sans-serif;
            background: #f4f7f6;
            color: #223028;
            padding: 20px;
        }
        .page {
            max-width: 1440px;
            margin: 0 auto;
        }
        .hero {
            background: linear-gradient(135deg, rgba(33,75,53,0.98), rgba(62,102,73,0.95));
            color: #fff;
            border-radius: 24px;
            padding: 26px 30px;
            box-shadow: 0 18px 40px rgba(31,56,40,0.18);
            margin-bottom: 20px;
        }
        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .hero h1 {
            margin: 8px 0 6px;
            font-size: 2rem;
        }
        .hero p {
            margin: 0;
            max-width: 900px;
            line-height: 1.6;
            opacity: 0.94;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            background: rgba(255,255,255,0.14);
            padding: 12px 18px;
            border-radius: 999px;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-bottom: 18px;
            padding: 18px 22px;
            background: #fff;
            border-radius: 22px;
            box-shadow: var(--shadow);
        }
        .toolbar-form {
            display: flex;
            gap: 14px;
            align-items: end;
            flex-wrap: wrap;
        }
        .filter-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--green-main);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .filter-group select {
            min-width: 280px;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 11px 12px;
            font: inherit;
            background: #fff;
        }
        .btn-filter {
            border: none;
            background: linear-gradient(135deg, var(--gold), #bd8f11);
            color: #fff;
            font-weight: 700;
            padding: 13px 18px;
            border-radius: 999px;
            cursor: pointer;
        }
        .toolbar-meta {
            color: var(--text-soft);
            line-height: 1.6;
            text-align: right;
        }
        .sector-card {
            background: #fff;
            border-radius: 22px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 18px;
        }
        .sector-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid #edf1ee;
            background: var(--green-pale);
        }
        .sector-title {
            margin: 0;
            color: var(--green-dark);
            font-size: 1.2rem;
        }
        .sector-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--green-soft);
            color: var(--green-main);
            font-weight: 700;
        }
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            min-width: 1180px;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf1ee;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f8fbf9;
            color: var(--text-soft);
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: .04em;
        }
        .sticky-col {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 2;
            box-shadow: 8px 0 14px rgba(0,0,0,0.03);
        }
        th.sticky-col {
            background: #f8fbf9;
            z-index: 3;
        }
        .student-name {
            font-weight: 700;
            color: var(--green-dark);
        }
        .student-meta {
            margin-top: 6px;
            color: var(--text-soft);
            font-size: 0.88rem;
        }
        .priority-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            border-radius: 999px;
            background: #eef6ef;
            color: var(--green-main);
            font-weight: 700;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 38px;
            padding: 6px 10px;
            border-radius: 12px;
            font-size: 0.84rem;
            font-weight: 700;
            box-sizing: border-box;
        }
        .status-empty { background: #f3f5f4; color: #738276; }
        .status-off { background: #fae4e1; color: #a13e35; }
        .status-partial { background: #fff4d8; color: #8b6400; }
        .status-full { background: #dff3e3; color: #1d6a39; }
        .note-text {
            display: block;
            margin-top: 6px;
            color: var(--text-soft);
            font-size: 0.78rem;
            line-height: 1.4;
        }
        .cell-actions {
            margin-top: 8px;
            display: flex;
            justify-content: flex-end;
        }
        .btn-delete-availability {
            border: 1px solid #efc7c3;
            background: #fff3f2;
            color: #a13e35;
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-delete-availability:hover {
            background: #fce5e3;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .alert.success {
            background: #dff3e3;
            color: #1d6a39;
            border: 1px solid #c6e6cd;
        }
        .alert.error {
            background: #fae4e1;
            color: #a13e35;
            border: 1px solid #f1c8c4;
        }
        .empty-state {
            padding: 24px 22px;
            color: var(--text-soft);
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
            color: var(--green-main);
        }

        @media (max-width: 980px) {
            body { padding: 12px; }
            .hero-top, .toolbar { flex-direction: column; align-items: flex-start; }
            .toolbar-meta { text-align: left; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <div class="hero-top">
                <div>
                    <div style="text-transform:uppercase;letter-spacing:.08em;font-size:.82rem;opacity:.85;"><?php echo $isAgencyInterimViewer ? e(fjaT('Agence intérim', 'Interimkantoor')) : e(fjaT('Administration', 'Administratie')); ?></div>
                    <h1><?php echo e(fjaT('Disponibilités étudiants', 'Beschikbaarheden studenten')); ?></h1>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <?php if ($isAgencyInterimViewer): ?>
                        <a href="logout.php" class="back-link">⬅ <?php echo e(fjaT('Se déconnecter', 'Uitloggen')); ?></a>
                    <?php else: ?>
                        <a href="index.php" class="back-link">⬅ <?php echo e(fjaT('Retour à l\'accueil', 'Terug naar start')); ?></a>
                    <?php endif; ?>
                    <?php echo famiRenderLanguageSwitcher(); ?>
                </div>
            </div>
            <p>
                <?php echo e(fjaT('Vue hebdomadaire des disponibilités étudiantes, organisée par secteur.', 'Wekelijks overzicht van studentbeschikbaarheden, georganiseerd per sector.')); ?>
                <?php if ($isAgencyInterimViewer): ?>
                    <?php echo e(fjaT('Affichage limité aux étudiants rattachés à l\'agence :', 'Weergave beperkt tot studenten van kantoor:')); ?> <strong><?php echo htmlspecialchars($agencyInterimName !== '' ? $agencyInterimName : fjaT('non renseignée', 'niet ingevuld')); ?></strong>.
                <?php else: ?>
                    <?php echo e(fjaT('Cette page permet de savoir rapidement quels étudiants sont disponibles sur la semaine choisie, avec leurs priorités de rattachement.', 'Met deze pagina zie je snel welke studenten beschikbaar zijn in de gekozen week, met hun prioriteiten.')); ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="toolbar">
            <form method="GET" class="toolbar-form">
                <div class="filter-group">
                    <label for="week"><?php echo e(fjaT('Semaine', 'Week')); ?></label>
                    <select name="week" id="week">
                        <?php foreach ($weekOptions as $weekKey => $weekOption): ?>
                            <option value="<?php echo htmlspecialchars($weekKey); ?>" <?php echo $selectedWeekKey === $weekKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($weekOption['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sector"><?php echo e(fjaT('Secteur', 'Sector')); ?></label>
                    <select name="sector" id="sector">
                        <option value="all" <?php echo $selectedSector === 'all' ? 'selected' : ''; ?>><?php echo e(fjaT('Tous les secteurs', 'Alle sectoren')); ?></option>
                        <option value="none" <?php echo $selectedSector === 'none' ? 'selected' : ''; ?>><?php echo e(fjaT('Sans secteur', 'Zonder sector')); ?></option>
                        <?php foreach ($sectorOptions as $sectorOption): ?>
                            <option value="<?php echo htmlspecialchars($sectorOption); ?>" <?php echo $selectedSector === $sectorOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($sectorOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-filter"><?php echo e(fjaT('Appliquer', 'Toepassen')); ?></button>
            </form>
            <div class="toolbar-meta">
                <strong><?php echo e(fjaT('Période :', 'Periode:')); ?></strong> <?php echo e(fjaT('du', 'van')); ?> <?php echo $selectedWeek['start']->format('d/m/Y'); ?> <?php echo e(fjaT('au', 'tot')); ?> <?php echo $selectedWeek['end']->format('d/m/Y'); ?><br>
                <strong><?php echo e(fjaT('Lecture :', 'Legenda:')); ?></strong> <?php echo e(fjaT('journée, après-midi à partir de 13h, indisponible ou non renseigné', 'hele dag, namiddag vanaf 13u, niet beschikbaar of niet ingevuld')); ?>
            </div>
        </div>

        <?php echo $flashMessage; ?>

        <?php if (empty($studentsBySector)): ?>
            <div class="sector-card">
                <div class="empty-state"><?php echo e(fjaT('Aucun étudiant disponible à afficher pour les filtres sélectionnés.', 'Geen beschikbare student om te tonen voor de geselecteerde filters.')); ?></div>
            </div>
        <?php endif; ?>

        <?php foreach ($studentsBySector as $sectorName => $sectorStudents): ?>
            <div class="sector-card">
                <div class="sector-head">
                    <h2 class="sector-title"><?php echo htmlspecialchars($sectorName === 'none' ? fjaT('Sans secteur', 'Zonder sector') : $sectorName); ?></h2>
                    <span class="sector-badge"><?php echo count($sectorStudents); ?> <?php echo e(fjaT('étudiant', 'student')); ?><?php echo count($sectorStudents) > 1 ? 's' : ''; ?></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="sticky-col"><?php echo e(fjaT('Étudiant', 'Student')); ?></th>
                                <th><?php echo e(fjaT('Priorité', 'Prioriteit')); ?></th>
                                <?php foreach ($weekDays as $day): ?>
                                    <th><?php echo htmlspecialchars($day['label']); ?><br><?php echo htmlspecialchars($day['date']); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sectorStudents as $student): ?>
                                <?php
                                $priority = null;
                                foreach ($student['departments'] as $department) {
                                    if ($sectorName !== 'none' && $department['name'] === $sectorName) {
                                        $priority = (int) $department['priority'];
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="sticky-col">
                                        <div class="student-name"><?php echo htmlspecialchars($student['prenom'] . ' ' . $student['nom']); ?></div>
                                        <div class="student-meta"><?php echo htmlspecialchars($student['identifiant']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($priority !== null): ?>
                                            <span class="priority-badge"><?php echo $priority; ?></span>
                                        <?php else: ?>
                                            <span class="student-meta">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($weekDays as $day): ?>
                                        <?php $availability = $student['availabilities'][$day['key']] ?? ['status' => 'non_renseigne', 'note' => '', 'exists' => false]; ?>
                                        <td>
                                            <span class="status-pill <?php echo htmlspecialchars($statusClasses[$availability['status']] ?? 'status-empty'); ?>"><?php echo htmlspecialchars($statusLabels[$availability['status']] ?? $availability['status']); ?></span>
                                            <?php if ($availability['note'] !== ''): ?>
                                                <span class="note-text"><?php echo htmlspecialchars($availability['note']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($canDeleteAvailability && !empty($availability['exists'])): ?>
                                                <div class="cell-actions">
                                                    <form method="POST" onsubmit="return confirm('<?php echo e(fjaT('Supprimer cette disponibilité ?', 'Deze beschikbaarheid verwijderen?')); ?>');">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="delete_availability" value="1">
                                                        <input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>">
                                                        <input type="hidden" name="availability_date" value="<?php echo htmlspecialchars($day['key']); ?>">
                                                        <input type="hidden" name="week" value="<?php echo htmlspecialchars($selectedWeekKey); ?>">
                                                        <input type="hidden" name="sector" value="<?php echo htmlspecialchars($selectedSector); ?>">
                                                        <button type="submit" class="btn-delete-availability" title="<?php echo e(fjaT('Supprimer cette disponibilité', 'Deze beschikbaarheid verwijderen')); ?>"><?php echo e(fjaT('Supprimer', 'Verwijderen')); ?></button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>