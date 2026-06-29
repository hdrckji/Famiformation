<?php
require_once 'config.php';
verifierConnexion($db);

$role = getCurrentRole();
if ($role !== 'admin') {
    header('Location: index.php');
    exit();
}

// ─── Créer / s'assurer des tables ──────────────────────────────────────────
ensureDepartmentsTable($db);
try { syncDepartmentsFromPlanningDb($db); } catch (Exception $e) {}

$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_fixed_schedules (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        week_type   CHAR(1) NOT NULL DEFAULT 'A',
        day_of_week TINYINT NOT NULL,
        time_start  TIME NULL,
        time_end    TIME NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_week_day (user_id, week_type, day_of_week),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_user_department (
        user_id         INT NOT NULL,
        department_name VARCHAR(120) NOT NULL DEFAULT '',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// ─── Résolution URL background ─────────────────────────────────────────────
function resolveInterimBgUrl(): string
{
    $candidates = [
        __DIR__ . '/font.png',
        dirname(__DIR__) . '/famijob/font.png',
        dirname(__DIR__) . '/Famijob/font.png',
        __DIR__ . '/background-famijob.png',
    ];
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real === false || !is_file($real)) {
            continue;
        }
        $v = @filemtime($real) ?: time();
        if ($docRoot) {
            $dr = str_replace('\\', '/', $docRoot);
            $rp = str_replace('\\', '/', $real);
            if (strpos($rp, $dr) === 0) {
                return '/' . ltrim(substr($rp, strlen($dr)), '/') . '?v=' . $v;
            }
        }
        return '/font.png?v=' . $v;
    }
    return '/font.png?v=' . time();
}

$bgUrl = resolveInterimBgUrl();

// ─── Semaine courante ──────────────────────────────────────────────────────
$currentWeekNumber = (int) date('W');
$currentWeekType   = ($currentWeekNumber % 2 === 0) ? 'A' : 'B';
$currentWeekLabel  = $currentWeekType === 'A' ? famiT('interim_fixes.week.label_a') : famiT('interim_fixes.week.label_b');

// ─── Chargement des départements ───────────────────────────────────────────
$departments = [];
try {
    $departments = $db->query(
        "SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ─── Chargement des intérimaires ───────────────────────────────────────────
function loadInterimaires(PDO $db): array
{
    try {
        $stmt = $db->query(
            "SELECT u.id, u.nom, u.prenom, u.identifiant, u.interim,
                    uid.department_name AS dept_name
             FROM utilisateurs u
             LEFT JOIN interim_user_department uid ON uid.user_id = u.id
             WHERE u.interim IS NOT NULL
               AND u.interim != ''
               AND LOWER(u.interim) != 'famiflora'
               AND u.role != 'etudiant'
             ORDER BY u.interim ASC, u.nom ASC, u.prenom ASC"
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        return [];
    }
}

$interimaires  = loadInterimaires($db);
$message       = '';
$selectedUserId = (int) ($_GET['user_id'] ?? 0);

$agencyOptions = [];
$rayonOptions = [];
foreach ($interimaires as $imOption) {
    $agencyName = trim((string) ($imOption['interim'] ?? ''));
    $rayonName = trim((string) ($imOption['dept_name'] ?? ''));
    if ($agencyName !== '') {
        $agencyOptions[$agencyName] = true;
    }
    if ($rayonName !== '') {
        $rayonOptions[$rayonName] = true;
    }
}
$agencyOptions = array_keys($agencyOptions);
$rayonOptions = array_keys($rayonOptions);
sort($agencyOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($rayonOptions, SORT_NATURAL | SORT_FLAG_CASE);

// ─── POST : Enregistrement de l'horaire ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    requireValidCSRF();
    $postUserId = (int) ($_POST['user_id'] ?? 0);

    if ($postUserId > 0) {
        $department = trim($_POST['department'] ?? '');

        // Upsert département
        $deptStmt = $db->prepare(
            "INSERT INTO interim_user_department (user_id, department_name)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE department_name = VALUES(department_name), updated_at = CURRENT_TIMESTAMP"
        );
        $deptStmt->execute([$postUserId, $department]);

        // Upsert horaires par semaine et par jour
        $schedStmt = $db->prepare(
            "INSERT INTO interim_fixed_schedules (user_id, week_type, day_of_week, time_start, time_end, is_active)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 time_start = VALUES(time_start),
                 time_end   = VALUES(time_end),
                 is_active  = VALUES(is_active),
                 updated_at = CURRENT_TIMESTAMP"
        );

        foreach (['A', 'B'] as $wt) {
            for ($d = 1; $d <= 7; $d++) {
                $isActive = isset($_POST["active_{$wt}_{$d}"]) ? 1 : 0;
                $rawStart = trim($_POST["start_{$wt}_{$d}"] ?? '');
                $rawEnd   = trim($_POST["end_{$wt}_{$d}"]   ?? '');
                $tStart   = preg_match('/^\d{2}:\d{2}$/', $rawStart) ? $rawStart . ':00' : null;
                $tEnd     = preg_match('/^\d{2}:\d{2}$/', $rawEnd)   ? $rawEnd   . ':00' : null;
                $schedStmt->execute([$postUserId, $wt, $d, $tStart, $tEnd, $isActive]);
            }
        }

        $message       = "<div class='fj-alert fj-alert--success'>✅ " . e(famiT('interim_fixes.message.saved')) . "</div>";
        $selectedUserId = $postUserId;
        $interimaires  = loadInterimaires($db);
    }
}

// ─── Chargement de l'horaire de l'utilisateur sélectionné ──────────────────
$selectedUser     = null;
$userSchedule     = [];   // ['A'][1..7] | ['B'][1..7]
$selectedUserDept = '';

function calculateWeekHours(array $weekSchedule): float
{
    $minutes = 0;
    foreach ($weekSchedule as $row) {
        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        $timeStart = (string) ($row['time_start'] ?? '');
        $timeEnd = (string) ($row['time_end'] ?? '');

        if (!$isActive || $timeStart === '' || $timeEnd === '') {
            continue;
        }

        $startTs = strtotime('1970-01-01 ' . $timeStart);
        $endTs = strtotime('1970-01-01 ' . $timeEnd);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            continue;
        }

        $minutes += (int) (($endTs - $startTs) / 60);
    }

    return round($minutes / 60, 2);
}

$hoursWeekA = 0.0;
$hoursWeekB = 0.0;
$hoursTotal = 0.0;

if ($selectedUserId > 0) {
    foreach ($interimaires as $im) {
        if ((int) $im['id'] === $selectedUserId) {
            $selectedUser = $im;
            break;
        }
    }

    if ($selectedUser) {
        $rs = $db->prepare(
            "SELECT week_type, day_of_week, time_start, time_end, is_active
             FROM interim_fixed_schedules WHERE user_id = ?"
        );
        $rs->execute([$selectedUserId]);
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userSchedule[$row['week_type']][(int) $row['day_of_week']] = $row;
        }
        $selectedUserDept = (string) ($selectedUser['dept_name'] ?? '');
        $hoursWeekA = calculateWeekHours($userSchedule['A'] ?? []);
        $hoursWeekB = calculateWeekHours($userSchedule['B'] ?? []);
        $hoursTotal = round($hoursWeekA + $hoursWeekB, 2);
    }
}

$days = [
    1 => famiT('interim_fixes.day.1'), 2 => famiT('interim_fixes.day.2'), 3 => famiT('interim_fixes.day.3'),
    4 => famiT('interim_fixes.day.4'), 5 => famiT('interim_fixes.day.5'), 6 => famiT('interim_fixes.day.6'), 7 => famiT('interim_fixes.day.7'),
];

$csrfField = csrfField();
?>
<!DOCTYPE html>
<html lang="<?= e(famiLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(famiT('interim_fixes.page_title')) ?> - FamiJob</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --fj-ink-900: #0f241d;
            --fj-ink-800: #17372d;
            --fj-ink-600: #2e5e4f;
            --fj-mint-100: #e8f4ef;
            --fj-mint-200: #d3ebdf;
            --fj-card: rgba(255,255,255,0.97);
            --fj-card-border: rgba(18,49,39,0.12);
            --fj-shadow-lg: 0 18px 44px rgba(8,22,17,0.24);
            --fj-shadow-md: 0 10px 24px rgba(8,22,17,0.15);
            --fj-text: #1b2c25;
            --fj-muted: #5c6f67;
            --fj-highlight: #f2b85a;
            --fj-orange: #e07b2b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Manrope', sans-serif;
            background:
                linear-gradient(140deg, rgba(9,31,24,0.78), rgba(16,46,37,0.56)),
                url('<?= e($bgUrl) ?>') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            color: var(--fj-text);
        }

        /* ─── Top nav ─── */
        .top-nav {
            width: min(1320px, calc(100% - 32px));
            margin: 14px auto 0;
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 18px;
            background: rgba(8,28,22,0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .top-nav-left { display: flex; align-items: center; gap: 12px; }

        .brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.94);
            color: var(--fj-ink-900);
            border-radius: 999px;
            padding: 8px 13px;
            font-size: 0.84rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .brand-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            background: var(--fj-highlight);
            box-shadow: 0 0 0 5px rgba(242,184,90,0.2);
        }

        .btn-back {
            background: rgba(255,255,255,0.95);
            color: #8d2e2e;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 0.84rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-back:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(9,28,22,0.18);
        }

        .fami-lang-switcher {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.26);
            border-radius: 999px;
            padding: 4px;
        }

        .fami-lang-option {
            text-decoration: none;
            color: #fff;
            font-weight: 800;
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            padding: 5px 9px;
            border-radius: 999px;
            transition: background 0.2s, color 0.2s;
        }

        .fami-lang-option.is-active { background: #fff; color: var(--fj-ink-900); }

        /* ─── Layout ─── */
        .page-wrap {
            width: min(1080px, calc(100% - 32px));
            margin: 16px auto 32px;
            display: block;
        }

        /* ─── Sidebar ─── */
        .sidebar {
            width: min(1080px, 100%);
            background: var(--fj-card);
            border-radius: 20px;
            box-shadow: var(--fj-shadow-md);
            border: 1px solid var(--fj-card-border);
            overflow: hidden;
            margin: 0 auto 16px;
        }

        .sidebar-header {
            padding: 16px 16px 12px;
            background: linear-gradient(135deg, rgba(46,94,79,0.1), rgba(46,94,79,0.04));
            border-bottom: 1px solid var(--fj-card-border);
        }

        .sidebar-title {
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--fj-ink-600);
            margin-bottom: 4px;
        }

        .week-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: <?= $currentWeekType === 'A' ? 'rgba(46,94,79,0.14)' : 'rgba(230,120,30,0.14)' ?>;
            color: <?= $currentWeekType === 'A' ? 'var(--fj-ink-800)' : '#b85a00' ?>;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .sidebar-search {
            padding: 10px 12px;
            border-bottom: 1px solid var(--fj-card-border);
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .sidebar-search input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--fj-card-border);
            border-radius: 8px;
            font-family: 'Manrope', sans-serif;
            font-size: 0.84rem;
            outline: none;
            background: #f8faf9;
        }

        .sidebar-search select {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--fj-card-border);
            border-radius: 8px;
            font-family: 'Manrope', sans-serif;
            font-size: 0.84rem;
            outline: none;
            background: #f8faf9;
            color: var(--fj-text);
        }

        .sidebar-search input:focus { border-color: var(--fj-ink-600); background: #fff; }
        .sidebar-search select:focus { border-color: var(--fj-ink-600); background: #fff; }

        .interimaire-list {
            list-style: none;
            max-height: none;
            overflow: visible;
        }

        .interimaire-list li a {
            display: block;
            padding: 11px 16px;
            text-decoration: none;
            color: var(--fj-text);
            border-bottom: 1px solid rgba(46,94,79,0.06);
            transition: background 0.15s;
        }

        .interimaire-list li a:hover { background: var(--fj-mint-100); }

        .interimaire-list li a.is-active {
            background: linear-gradient(135deg, rgba(46,94,79,0.14), rgba(46,94,79,0.06));
            border-left: 3px solid var(--fj-ink-600);
        }

        .inline-editor-host {
            padding: 12px;
            border-top: 1px solid rgba(46,94,79,0.08);
            background: rgba(46,94,79,0.03);
        }

        .inline-editor-host .panel {
            margin-bottom: 0;
            border-radius: 14px;
            box-shadow: none;
            border: 1px solid rgba(18,49,39,0.14);
            padding: 16px;
        }

        .im-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--fj-ink-900);
        }

        .im-meta {
            font-size: 0.76rem;
            color: var(--fj-muted);
            margin-top: 2px;
        }

        .im-dept {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            background: var(--fj-mint-100);
            color: var(--fj-ink-600);
            border-radius: 999px;
            padding: 2px 7px;
            margin-top: 3px;
        }

        .empty-list {
            padding: 20px 16px;
            text-align: center;
            color: var(--fj-muted);
            font-size: 0.86rem;
        }

        /* ─── Main content ─── */
        .main-content {
            width: min(1080px, 100%);
            margin: 0 auto;
        }

        .panel {
            background: var(--fj-card);
            border-radius: 20px;
            box-shadow: var(--fj-shadow-md);
            border: 1px solid var(--fj-card-border);
            padding: 26px 28px;
            margin-bottom: 16px;
        }

        #schedule-editor.is-hidden {
            display: none;
        }

        .panel-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--fj-ink-900);
            margin-bottom: 4px;
        }

        .panel-sub {
            font-size: 0.88rem;
            color: var(--fj-muted);
            margin-bottom: 20px;
        }

        .intro-inline {
            padding: 14px 16px;
            font-size: 0.9rem;
            color: var(--fj-muted);
            border-top: 1px solid var(--fj-card-border);
            background: rgba(46, 94, 79, 0.03);
        }

        .hours-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .hours-box {
            border: 1px solid var(--fj-card-border);
            border-radius: 12px;
            padding: 10px 12px;
            background: #f8faf9;
        }

        .hours-label {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: var(--fj-muted);
        }

        .hours-value {
            margin-top: 4px;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--fj-ink-900);
        }

        /* ─── User header ─── */
        .user-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--fj-card-border);
        }

        .user-avatar {
            width: 48px; height: 48px;
            border-radius: 14px;
            background: linear-gradient(145deg, rgba(46,94,79,0.2), rgba(46,94,79,0.08));
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .user-name {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--fj-ink-900);
        }

        .user-agency {
            font-size: 0.82rem;
            color: var(--fj-muted);
            margin-top: 2px;
        }

        .badge-interim {
            display: inline-block;
            background: rgba(224,123,43,0.14);
            color: var(--fj-orange);
            border: 1px solid rgba(224,123,43,0.3);
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 0.72rem;
            font-weight: 800;
            margin-top: 4px;
        }

        /* ─── Form fields ─── */
        .form-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .form-row label {
            font-size: 0.84rem;
            font-weight: 700;
            color: var(--fj-ink-800);
            min-width: 110px;
        }

        .form-select {
            padding: 9px 12px;
            border: 1px solid var(--fj-card-border);
            border-radius: 10px;
            font-family: 'Manrope', sans-serif;
            font-size: 0.9rem;
            color: var(--fj-text);
            background: #f8faf9;
            outline: none;
            transition: border-color 0.2s;
            min-width: 240px;
        }

        .form-select:focus { border-color: var(--fj-ink-600); background: #fff; }

        /* ─── Schedule grid ─── */
        .schedule-section {
            margin-bottom: 24px;
        }

        .schedule-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .week-hours {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.78rem;
            font-weight: 800;
            color: var(--fj-ink-800);
            background: rgba(46,94,79,0.1);
            border: 1px solid rgba(46,94,79,0.2);
        }

        .week-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }

        .week-chip--a {
            background: rgba(46,94,79,0.12);
            color: var(--fj-ink-800);
            border: 1px solid rgba(46,94,79,0.2);
        }

        .week-chip--b {
            background: rgba(224,123,43,0.12);
            color: #b85a00;
            border: 1px solid rgba(224,123,43,0.22);
        }

        .week-chip--active {
            box-shadow: 0 0 0 3px rgba(46,94,79,0.18);
        }

        .schedule-table-wrap { overflow-x: auto; }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            min-width: 480px;
        }

        .schedule-table th {
            padding: 9px 12px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--fj-muted);
            background: #f5f8f6;
            border-bottom: 2px solid var(--fj-card-border);
        }

        .schedule-table td {
            padding: 8px 12px;
            border-bottom: 1px solid rgba(46,94,79,0.07);
            vertical-align: middle;
        }

        .schedule-table tr:last-child td { border-bottom: none; }

        .schedule-table tr.row-active td { background: rgba(46,94,79,0.025); }

        .day-label {
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--fj-ink-800);
            min-width: 90px;
        }

        .time-input {
            padding: 7px 10px;
            border: 1px solid var(--fj-card-border);
            border-radius: 8px;
            font-family: 'Manrope', sans-serif;
            font-size: 0.88rem;
            width: 108px;
            color: var(--fj-text);
            background: #f8faf9;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }

        .time-input:focus { border-color: var(--fj-ink-600); background: #fff; }
        .time-input:disabled { background: #eee; color: #aaa; border-color: #ddd; }

        /* Toggle switch */
        .toggle-wrap { display: flex; align-items: center; gap: 8px; }

        .toggle {
            position: relative;
            display: inline-block;
            width: 36px; height: 20px;
        }

        .toggle input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute; inset: 0;
            background: #d0d8d4;
            border-radius: 999px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 14px; height: 14px;
            left: 3px; top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }

        .toggle input:checked + .toggle-slider { background: var(--fj-ink-600); }
        .toggle input:checked + .toggle-slider::before { transform: translateX(16px); }

        .toggle-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--fj-muted);
        }

        /* ─── Copy button ─── */
        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border-radius: 8px;
            background: rgba(46,94,79,0.08);
            border: 1px solid rgba(46,94,79,0.2);
            color: var(--fj-ink-600);
            font-family: 'Manrope', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.18s, border-color 0.18s;
        }

        .btn-copy:hover { background: rgba(46,94,79,0.14); border-color: rgba(46,94,79,0.34); }

        /* ─── Actions ─── */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--fj-card-border);
            margin-top: 16px;
        }

        .btn-save {
            padding: 11px 22px;
            background: var(--fj-ink-800);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Manrope', sans-serif;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s, transform 0.15s;
        }

        .btn-save:hover { background: var(--fj-ink-900); transform: translateY(-1px); }

        /* ─── Alerts ─── */
        .fj-alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .fj-alert--success {
            background: rgba(46,94,79,0.1);
            color: var(--fj-ink-800);
            border: 1px solid rgba(46,94,79,0.24);
        }

        .fj-alert--error {
            background: rgba(180,30,30,0.08);
            color: #8b1a1a;
            border: 1px solid rgba(180,30,30,0.2);
        }

        /* ─── Responsive ─── */
        @media (max-width: 900px) {
            .sidebar { width: 100%; }
            .interimaire-list { max-height: none; overflow: visible; }
            .sidebar-search { grid-template-columns: 1fr; }
            .hours-summary { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .top-nav { width: calc(100% - 20px); flex-wrap: wrap; }
            .panel { padding: 18px 14px; }
        }
    </style>
</head>
<body>

<!-- ─── Nav ─────────────────────────────────────────────────────────────── -->
<div class="top-nav">
    <div class="top-nav-left">
        <span class="brand-pill"><span class="brand-dot"></span> <?= e(famiT('interim_fixes.workspace')) ?></span>
        <a href="index.php" class="btn-back">← <?= e(famiT('interim_fixes.back')) ?></a>
    </div>
    <?= famiRenderLanguageSwitcher() ?>
</div>

<!-- ─── Layout ──────────────────────────────────────────────────────────── -->
<div class="page-wrap">

    <!-- ─── Sidebar ─────────────────────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title"><?= e(famiT('interim_fixes.sidebar.title')) ?></div>
            <div class="week-badge">
                <?= e(famiT('interim_fixes.week_prefix')) ?> <?= $currentWeekNumber ?> — <?= e($currentWeekLabel) ?>
            </div>
        </div>
        <div class="sidebar-search">
            <input type="text" id="im-search" placeholder="<?= e(famiT('interim_fixes.search.placeholder')) ?>" autocomplete="off">
            <select id="agency-filter">
                <option value=""><?= e(famiT('interim_fixes.filter.agency.all')) ?></option>
                <?php foreach ($agencyOptions as $agencyOption): ?>
                <option value="<?= e(strtolower($agencyOption)) ?>"><?= e($agencyOption) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="rayon-filter">
                <option value=""><?= e(famiT('interim_fixes.filter.rayon.all')) ?></option>
                <?php foreach ($rayonOptions as $rayonOption): ?>
                <option value="<?= e(strtolower($rayonOption)) ?>"><?= e($rayonOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (empty($interimaires)): ?>
            <div class="empty-list">
                <p><?= e(famiT('interim_fixes.empty.none')) ?></p>
                <p style="margin-top:6px; font-size:0.78rem;"><?= e(famiT('interim_fixes.empty.help')) ?></p>
            </div>
        <?php else: ?>
        <ul class="interimaire-list" id="im-list">
            <?php foreach ($interimaires as $im): ?>
            <?php $isActive = ((int) $im['id'] === $selectedUserId); ?>
            <li
                data-search="<?= e(strtolower(($im['nom'] ?? '') . ' ' . ($im['prenom'] ?? '') . ' ' . ($im['interim'] ?? '') . ' ' . ($im['dept_name'] ?? ''))) ?>"
                data-agency="<?= e(strtolower(trim((string) ($im['interim'] ?? '')))) ?>"
                data-rayon="<?= e(strtolower(trim((string) ($im['dept_name'] ?? '')))) ?>"
            >
                     <a href="?user_id=<?= (int) $im['id'] ?>"
                         data-user-id="<?= (int) $im['id'] ?>"
                   class="<?= $isActive ? 'is-active' : '' ?>">
                    <div class="im-name"><?= e(trim(($im['prenom'] ?? '') . ' ' . ($im['nom'] ?? ''))) ?: e($im['identifiant']) ?></div>
                    <div class="im-meta"><?= e($im['interim'] ?? '') ?></div>
                    <?php if (!empty($im['dept_name'])): ?>
                    <span class="im-dept">📍 <?= e($im['dept_name']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="intro-inline">
            <?= e(famiT('interim_fixes.intro.select')) ?>
        </div>
    </aside>

    <!-- ─── Main ─────────────────────────────────────────────────────────── -->
    <main class="main-content">
        <?= $message ?>

        <?php if ($selectedUser): ?>
        <!-- Formulaire d'encodage -->
        <div class="panel" id="schedule-editor">
            <div class="hours-summary">
                <div class="hours-box">
                    <div class="hours-label"><?= e(famiT('interim_fixes.hours.week_a')) ?></div>
                    <div class="hours-value" id="hours_week_a"><?= number_format($hoursWeekA, 2, ',', ' ') ?> <?= e(famiT('interim_fixes.hours.unit')) ?></div>
                </div>
                <div class="hours-box">
                    <div class="hours-label"><?= e(famiT('interim_fixes.hours.week_b')) ?></div>
                    <div class="hours-value" id="hours_week_b"><?= number_format($hoursWeekB, 2, ',', ' ') ?> <?= e(famiT('interim_fixes.hours.unit')) ?></div>
                </div>
                <div class="hours-box">
                    <div class="hours-label"><?= e(famiT('interim_fixes.hours.total')) ?></div>
                    <div class="hours-value" id="hours_total"><?= number_format($hoursTotal, 2, ',', ' ') ?> <?= e(famiT('interim_fixes.hours.unit')) ?></div>
                </div>
            </div>

            <form method="POST" action="?user_id=<?= $selectedUserId ?>">
                <?= $csrfField ?>
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">

                <!-- Département -->
                <div class="form-row">
                    <label for="department"><?= e(famiT('interim_fixes.department.label')) ?></label>
                    <select id="department" name="department" class="form-select">
                        <option value=""><?= e(famiT('interim_fixes.department.undefined')) ?></option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= e($dept) ?>"
                            <?= ($selectedUserDept === $dept) ? 'selected' : '' ?>>
                            <?= e($dept) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if (!empty($selectedUserDept) && !in_array($selectedUserDept, $departments, true)): ?>
                        <option value="<?= e($selectedUserDept) ?>" selected><?= e($selectedUserDept) ?> <?= e(famiT('interim_fixes.department.custom_suffix')) ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Grilles d'horaires -->
                <?php foreach (['A' => 'week-chip--a', 'B' => 'week-chip--b'] as $wt => $chipClass): ?>
                <?php
                    $wtLabel   = $wt === 'A' ? famiT('interim_fixes.week.label_a') : famiT('interim_fixes.week.label_b');
                    $isCurrentWt = ($wt === $currentWeekType);
                ?>
                <div class="schedule-section">
                    <div class="schedule-section-title">
                        <span class="week-chip <?= $chipClass ?> <?= $isCurrentWt ? 'week-chip--active' : '' ?>">
                            <?= $isCurrentWt ? e(famiT('interim_fixes.week.current_dot')) : '' ?><?= e($wtLabel) ?>
                        </span>
                        <span class="week-hours" id="week_hours_<?= $wt ?>">
                            <?= $wt === 'A' ? number_format($hoursWeekA, 2, ',', ' ') : number_format($hoursWeekB, 2, ',', ' ') ?> <?= e(famiT('interim_fixes.hours.encoded_suffix')) ?>
                        </span>
                        <?php if ($isCurrentWt): ?>
                        <span style="font-size:0.76rem; color:var(--fj-muted); font-weight:700;"><?= e(famiT('interim_fixes.week.current_hint')) ?></span>
                        <?php endif; ?>
                        <?php if ($wt === 'B'): ?>
                        <button type="button" class="btn-copy" onclick="copyWeekAtoB()">
                            ⟳ <?= e(famiT('interim_fixes.copy.button')) ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="schedule-table-wrap">
                        <table class="schedule-table">
                            <thead>
                                <tr>
                                    <th><?= e(famiT('interim_fixes.table.day')) ?></th>
                                    <th><?= e(famiT('interim_fixes.table.active')) ?></th>
                                    <th><?= e(famiT('interim_fixes.table.start')) ?></th>
                                    <th><?= e(famiT('interim_fixes.table.end')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($days as $dayNum => $dayLabel): ?>
                            <?php
                                $row      = $userSchedule[$wt][$dayNum] ?? null;
                                $active   = $row ? (int) $row['is_active'] : 0;
                                $tStart   = $row ? substr((string) $row['time_start'], 0, 5) : '';
                                $tEnd     = $row ? substr((string) $row['time_end'],   0, 5) : '';
                                $rowClass = $active ? 'row-active' : '';
                            ?>
                            <tr class="<?= $rowClass ?>" id="row_<?= $wt ?>_<?= $dayNum ?>">
                                <td class="day-label"><?= e($dayLabel) ?></td>
                                <td>
                                    <div class="toggle-wrap">
                                        <label class="toggle">
                                            <input type="checkbox"
                                                   name="active_<?= $wt ?>_<?= $dayNum ?>"
                                                   id="active_<?= $wt ?>_<?= $dayNum ?>"
                                                   <?= $active ? 'checked' : '' ?>
                                                   onchange="toggleRow('<?= $wt ?>','<?= $dayNum ?>')">
                                            <span class="toggle-slider"></span>
                                        </label>
                                        <span class="toggle-label" id="lbl_<?= $wt ?>_<?= $dayNum ?>">
                                            <?= $active ? e(famiT('interim_fixes.status.active')) : e(famiT('interim_fixes.status.inactive')) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <input type="time"
                                           class="time-input"
                                           name="start_<?= $wt ?>_<?= $dayNum ?>"
                                           id="start_<?= $wt ?>_<?= $dayNum ?>"
                                           value="<?= e($tStart) ?>"
                                           <?= !$active ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <input type="time"
                                           class="time-input"
                                           name="end_<?= $wt ?>_<?= $dayNum ?>"
                                           id="end_<?= $wt ?>_<?= $dayNum ?>"
                                           value="<?= e($tEnd) ?>"
                                           <?= !$active ? 'disabled' : '' ?>>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="form-actions">
                    <button type="submit" class="btn-save">💾 <?= e(famiT('interim_fixes.save')) ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
const TEXT_ACTIVE = <?= json_encode(famiT('interim_fixes.status.active')) ?>;
const TEXT_INACTIVE = <?= json_encode(famiT('interim_fixes.status.inactive')) ?>;
const TEXT_HOURS_UNIT = <?= json_encode(famiT('interim_fixes.hours.unit')) ?>;
const TEXT_HOURS_ENCODED_SUFFIX = <?= json_encode(famiT('interim_fixes.hours.encoded_suffix')) ?>;
const TEXT_CONFIRM_COPY = <?= json_encode(famiT('interim_fixes.confirm.copy_a_to_b')) ?>;

function formatHours(hours) {
    return hours.toFixed(2).replace('.', ',') + ' ' + TEXT_HOURS_UNIT;
}

function parseTimeToMinutes(value) {
    if (!/^\d{2}:\d{2}$/.test(value || '')) {
        return null;
    }

    const parts = value.split(':');
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    if (Number.isNaN(h) || Number.isNaN(m)) {
        return null;
    }

    return (h * 60) + m;
}

function calculateWeekHours(wt) {
    let totalMinutes = 0;
    for (let day = 1; day <= 7; day++) {
        const cb = document.getElementById('active_' + wt + '_' + day);
        const s = document.getElementById('start_' + wt + '_' + day);
        const e = document.getElementById('end_' + wt + '_' + day);

        if (!cb || !s || !e || !cb.checked) {
            continue;
        }

        const start = parseTimeToMinutes(s.value);
        const end = parseTimeToMinutes(e.value);
        if (start === null || end === null || end <= start) {
            continue;
        }

        totalMinutes += (end - start);
    }

    return totalMinutes / 60;
}

function refreshHours() {
    const weekA = document.getElementById('hours_week_a');
    const weekB = document.getElementById('hours_week_b');
    const total = document.getElementById('hours_total');
    const badgeA = document.getElementById('week_hours_A');
    const badgeB = document.getElementById('week_hours_B');

    if (!weekA || !weekB || !total || !badgeA || !badgeB) {
        return;
    }

    const hoursA = calculateWeekHours('A');
    const hoursB = calculateWeekHours('B');
    const hoursT = hoursA + hoursB;

    weekA.textContent = formatHours(hoursA);
    weekB.textContent = formatHours(hoursB);
    total.textContent = formatHours(hoursT);
    badgeA.textContent = formatHours(hoursA) + ' ' + TEXT_HOURS_ENCODED_SUFFIX;
    badgeB.textContent = formatHours(hoursB) + ' ' + TEXT_HOURS_ENCODED_SUFFIX;
}

// Activer / désactiver les inputs d'une ligne
function toggleRow(wt, day) {
    const cb  = document.getElementById('active_' + wt + '_' + day);
    const row = document.getElementById('row_' + wt + '_' + day);
    const lbl = document.getElementById('lbl_' + wt + '_' + day);
    const s   = document.getElementById('start_' + wt + '_' + day);
    const e   = document.getElementById('end_'   + wt + '_' + day);

    if (!cb) return;
    const on = cb.checked;
    if (s) s.disabled = !on;
    if (e) e.disabled = !on;
    if (row) row.classList.toggle('row-active', on);
    if (lbl) lbl.textContent = on ? TEXT_ACTIVE : TEXT_INACTIVE;
    refreshHours();
}

// Copier Semaine A → Semaine B
function copyWeekAtoB() {
    if (!confirm(TEXT_CONFIRM_COPY)) return;
    for (let d = 1; d <= 7; d++) {
        const cbA = document.getElementById('active_A_' + d);
        const cbB = document.getElementById('active_B_' + d);
        const sA  = document.getElementById('start_A_'  + d);
        const eA  = document.getElementById('end_A_'    + d);
        const sB  = document.getElementById('start_B_'  + d);
        const eB  = document.getElementById('end_B_'    + d);

        if (!cbA || !cbB) continue;

        cbB.checked = cbA.checked;
        if (sA && sB) sB.value = sA.value;
        if (eA && eB) eB.value = eA.value;
        toggleRow('B', d);
    }
    refreshHours();
}

function applyInterimaireFilters() {
    const searchInput = document.getElementById('im-search');
    const agencyFilter = document.getElementById('agency-filter');
    const rayonFilter = document.getElementById('rayon-filter');
    const q = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const agency = agencyFilter ? agencyFilter.value : '';
    const rayon = rayonFilter ? rayonFilter.value : '';
    const items = document.querySelectorAll('#im-list li');

    items.forEach(function (li) {
        const search = li.dataset.search || '';
        const agencyVal = li.dataset.agency || '';
        const rayonVal = li.dataset.rayon || '';
        const matchSearch = (q === '' || search.includes(q));
        const matchAgency = (agency === '' || agencyVal === agency);
        const matchRayon = (rayon === '' || rayonVal === rayon);
        li.style.display = (matchSearch && matchAgency && matchRayon) ? '' : 'none';
    });
}

const searchInput = document.getElementById('im-search');
const agencyFilter = document.getElementById('agency-filter');
const rayonFilter = document.getElementById('rayon-filter');
if (searchInput) {
    searchInput.addEventListener('input', applyInterimaireFilters);
}
if (agencyFilter) {
    agencyFilter.addEventListener('change', applyInterimaireFilters);
}
if (rayonFilter) {
    rayonFilter.addEventListener('change', applyInterimaireFilters);
}

document.querySelectorAll('.time-input').forEach(function (input) {
    input.addEventListener('input', refreshHours);
});

document.querySelectorAll('#im-list li a').forEach(function (link) {
    link.addEventListener('click', function (event) {
        const isActive = link.classList.contains('is-active');
        const targetUrl = new URL(link.getAttribute('href') || '', window.location.href);
        const editor = document.getElementById('schedule-editor');

        if (isActive) {
            event.preventDefault();

            if (editor) {
                const isHidden = editor.classList.toggle('is-hidden');
                if (!isHidden) {
                    editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            return;
        }

        targetUrl.hash = 'schedule-editor';
        event.preventDefault();
        window.location.href = targetUrl.toString();
    });
});

function mountInlineEditor() {
    const editor = document.getElementById('schedule-editor');
    const activeLink = document.querySelector('#im-list li a.is-active');

    if (!editor || !activeLink) {
        return;
    }

    let host = activeLink.closest('li').querySelector('.inline-editor-host');
    if (!host) {
        host = document.createElement('div');
        host.className = 'inline-editor-host';
        activeLink.closest('li').appendChild(host);
    }

    host.appendChild(editor);
}

mountInlineEditor();

if (window.location.hash === '#schedule-editor') {
    const editor = document.getElementById('schedule-editor');
    if (editor) {
        editor.classList.remove('is-hidden');
        editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

refreshHours();
</script>

</body>
</html>
