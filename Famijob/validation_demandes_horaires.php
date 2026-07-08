<?php
require_once 'config.php';
verifierConnexion($db);

$pageLang = famiLang();
if (!function_exists('fjvT')) {
    function fjvT($fr, $nl = null)
    {
        return famiLang() === 'nl' && $nl !== null ? $nl : $fr;
    }
}

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin'], true)) {
    header('Location: ../public/index.php');
    exit();
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

$message = '';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$today = new DateTimeImmutable('today');
$startMonday = $today->modify('monday this week');
$weekOptions = [];
for ($offset = -2; $offset < 8; $offset++) {
    $weekStart = $startMonday->modify(($offset >= 0 ? '+' : '') . $offset . ' week');
    $weekEnd = $weekStart->modify('+6 days');
    $weekOptions[$weekStart->format('Y-m-d')] = [
        'start' => $weekStart,
        'end' => $weekEnd,
        'label' => fjvT('Semaine du ', 'Week van ') . $weekStart->format('d/m/Y') . fjvT(' au ', ' tot ') . $weekEnd->format('d/m/Y'),
    ];
}

$selectedWeekKey = (string) ($_GET['week'] ?? array_key_first($weekOptions));
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = array_key_first($weekOptions);
}
$selectedWeek = $weekOptions[$selectedWeekKey];

$departmentOptions = $db->query(
    "SELECT DISTINCT department_name
     FROM interim_shift_requests
     WHERE validation_status = 'pending'
       AND TRIM(COALESCE(department_name, '')) <> ''
     ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$selectedDepartment = trim((string) ($_GET['department'] ?? 'all'));
if ($selectedDepartment !== 'all' && !in_array($selectedDepartment, $departmentOptions, true)) {
    $selectedDepartment = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $selectedWeekKey = (string) ($_POST['week'] ?? $selectedWeekKey);
    if (!isset($weekOptions[$selectedWeekKey])) {
        $selectedWeekKey = array_key_first($weekOptions);
    }
    $selectedWeek = $weekOptions[$selectedWeekKey];

    $selectedDepartment = trim((string) ($_POST['department'] ?? $selectedDepartment));
    if ($selectedDepartment !== 'all' && !in_array($selectedDepartment, $departmentOptions, true)) {
        $selectedDepartment = 'all';
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId > 0 && isset($_POST['approve_request'])) {
        $stmt = $db->prepare("UPDATE interim_shift_requests SET validation_status = 'approved', validated_by_user_id = ?, validated_at = NOW() WHERE id = ?");
        $stmt->execute([$currentUserId, $requestId]);
        $message = "<div class='alert success'>" . e(fjvT('Demande validée.', 'Aanvraag goedgekeurd.')) . "</div>";
    } elseif ($requestId > 0 && isset($_POST['reject_request'])) {
        $stmt = $db->prepare("UPDATE interim_shift_requests SET validation_status = 'rejected', validated_by_user_id = ?, validated_at = NOW() WHERE id = ?");
        $stmt->execute([$currentUserId, $requestId]);
        $message = "<div class='alert error'>" . e(fjvT('Demande refusée.', 'Aanvraag geweigerd.')) . "</div>";
    } elseif (isset($_POST['approve_all'])) {
        $params = [$currentUserId, $selectedWeek['start']->format('Y-m-d'), $selectedWeek['end']->format('Y-m-d')];
        $sql = "UPDATE interim_shift_requests
                SET validation_status = 'approved', validated_by_user_id = ?, validated_at = NOW()
                WHERE shift_date BETWEEN ? AND ?
                  AND validation_status = 'pending'";
        if ($selectedDepartment !== 'all') {
            $sql .= ' AND department_name = ?';
            $params[] = $selectedDepartment;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $message = "<div class='alert success'>" . e(fjvT('Validation globale effectuée :', 'Globale validatie uitgevoerd:')) . ' ' . (int) $stmt->rowCount() . ' ' . e(fjvT('demande(s).', 'aanvraag/aanvragen.')) . "</div>";
    } elseif (isset($_POST['approve_department'])) {
        if ($selectedDepartment === 'all') {
            $message = "<div class='alert error'>" . e(fjvT('Sélectionne un département pour la validation par département.', 'Selecteer een afdeling voor validatie per afdeling.')) . "</div>";
        } else {
            $stmt = $db->prepare(
                "UPDATE interim_shift_requests
                 SET validation_status = 'approved', validated_by_user_id = ?, validated_at = NOW()
                 WHERE shift_date BETWEEN ? AND ?
                   AND validation_status = 'pending'
                   AND department_name = ?"
            );
            $stmt->execute([
                $currentUserId,
                $selectedWeek['start']->format('Y-m-d'),
                $selectedWeek['end']->format('Y-m-d'),
                $selectedDepartment,
            ]);
            $message = "<div class='alert success'>" . e(fjvT('Validation du département', 'Validatie van afdeling')) . ' ' . e($selectedDepartment) . ': ' . (int) $stmt->rowCount() . ' ' . e(fjvT('demande(s).', 'aanvraag/aanvragen.')) . "</div>";
        }
    }
}

$pendingSql =
    "SELECT r.id, r.shift_date, r.department_name, r.time_slot, r.seats_required, r.comment,
            u.nom AS creator_nom, u.prenom AS creator_prenom
     FROM interim_shift_requests r
     LEFT JOIN utilisateurs u ON u.id = r.created_by_user_id
     WHERE r.shift_date BETWEEN ? AND ?
       AND r.validation_status = 'pending'";
$pendingParams = [
    $selectedWeek['start']->format('Y-m-d'),
    $selectedWeek['end']->format('Y-m-d'),
];
if ($selectedDepartment !== 'all') {
    $pendingSql .= ' AND r.department_name = ?';
    $pendingParams[] = $selectedDepartment;
}
$pendingSql .= ' ORDER BY r.shift_date ASC, r.department_name ASC, r.time_slot ASC';
$pendingStmt = $db->prepare($pendingSql);
$pendingStmt->execute($pendingParams);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?php echo e($pageLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(fjvT('Validation demandes horaires - FamiJob', 'Validatie uurroosteraanvragen - FamiJob')); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f4f7f6; --card:#fff; --line:#dde6df; --text:#21362a; --muted:#64756a; --accent:#2d5a37; --warn:#a13e35; --shadow:0 14px 34px rgba(22,49,33,.1); }
        body { margin:0; padding:24px; background:var(--bg); font-family:'Open Sans',sans-serif; color:var(--text); }
        .page { max-width:1200px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; border-radius:20px; padding:20px 22px; box-shadow:var(--shadow); margin-bottom:16px; }
        .hero h1 { margin:0 0 6px; }
        .hero a { color:#fff; text-decoration:none; font-weight:700; background:rgba(255,255,255,.16); padding:8px 12px; border-radius:999px; display:inline-block; margin-bottom:8px; }
        .toolbar { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:14px; margin-bottom:14px; display:flex; gap:12px; align-items:end; flex-wrap:wrap; }
        .toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; }
        label { display:block; margin-bottom:6px; font-size:.82rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:700; }
        select { border:1px solid #cfdad3; border-radius:10px; padding:9px 10px; min-width:320px; }
        .btn { border:none; border-radius:10px; padding:9px 12px; font-weight:700; cursor:pointer; }
        .btn-ok { background:#dff3e3; color:#1d6a39; }
        .btn-ko { background:#fae4e1; color:var(--warn); }
        .btn-soft { background:#edf5ef; color:var(--accent); }
        .alert { padding:10px 12px; border-radius:10px; font-weight:700; margin-bottom:12px; }
        .success { background:#dff3e3; color:#1d6a39; }
        .error { background:#fae4e1; color:var(--warn); }
        table { width:100%; border-collapse:collapse; background:#fff; border-radius:16px; overflow:hidden; box-shadow:var(--shadow); }
        th, td { border-bottom:1px solid var(--line); padding:10px; text-align:left; vertical-align:top; }
        th { background:#f7fbf8; color:var(--muted); font-size:.78rem; text-transform:uppercase; }
        .actions { display:flex; gap:8px; }
        .empty { background:#fff; border-radius:16px; box-shadow:var(--shadow); padding:18px; color:var(--muted); }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a href="index.php"><?php echo e(fjvT('Retour FamiJob', 'Terug naar FamiJob')); ?></a>
        <?php echo famiRenderLanguageSwitcher(); ?>
        <h1><?php echo e(fjvT('Validation des demandes horaires', 'Validatie van uurroosteraanvragen')); ?></h1>
        <p><?php echo e(fjvT('Les demandes sont visibles dans le matching uniquement après validation.', 'Aanvragen zijn pas zichtbaar in de matching na validatie.')); ?></p>
    </div>

    <?php echo $message; ?>

    <form method="get" class="toolbar">
        <div>
            <label for="week"><?php echo e(fjvT('Semaine', 'Week')); ?></label>
            <select id="week" name="week">
                <?php foreach ($weekOptions as $weekKey => $weekInfo): ?>
                    <option value="<?php echo e($weekKey); ?>" <?php echo $weekKey === $selectedWeekKey ? 'selected' : ''; ?>><?php echo e($weekInfo['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="department"><?php echo e(fjvT('Département', 'Afdeling')); ?></label>
            <select id="department" name="department">
                <option value="all" <?php echo $selectedDepartment === 'all' ? 'selected' : ''; ?>><?php echo e(fjvT('Tous les départements', 'Alle afdelingen')); ?></option>
                <?php foreach ($departmentOptions as $departmentName): ?>
                    <option value="<?php echo e($departmentName); ?>" <?php echo $selectedDepartment === $departmentName ? 'selected' : ''; ?>><?php echo e($departmentName); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-soft" type="submit"><?php echo e(fjvT('Afficher', 'Tonen')); ?></button>
    </form>

    <form method="post" class="toolbar" onsubmit="return confirm('<?php echo e(fjvT('Valider les demandes selon l\'action choisie ?', 'Aanvragen valideren volgens de gekozen actie?')); ?>');">
        <?php echo csrfField(); ?>
        <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">
        <input type="hidden" name="department" value="<?php echo e($selectedDepartment); ?>">
        <div class="toolbar-actions">
            <button class="btn btn-ok" type="submit" name="approve_all" value="1"><?php echo e(fjvT('Validation globale', 'Globale validatie')); ?></button>
            <button class="btn btn-soft" type="submit" name="approve_department" value="1"><?php echo e(fjvT('Validation globale par département', 'Globale validatie per afdeling')); ?></button>
        </div>
    </form>

    <?php if (empty($pendingRequests)): ?>
        <div class="empty"><?php echo e(fjvT('Aucune demande en attente sur la période sélectionnée.', 'Geen aanvraag in afwachting voor de geselecteerde periode.')); ?></div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?php echo e(fjvT('Date', 'Datum')); ?></th>
                    <th><?php echo e(fjvT('Département', 'Afdeling')); ?></th>
                    <th><?php echo e(fjvT('Horaire', 'Uurrooster')); ?></th>
                    <th><?php echo e(fjvT('Postes', 'Plaatsen')); ?></th>
                    <th><?php echo e(fjvT('Créé par', 'Aangemaakt door')); ?></th>
                    <th><?php echo e(fjvT('Commentaire', 'Opmerking')); ?></th>
                    <th><?php echo e(fjvT('Validation', 'Validatie')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingRequests as $request): ?>
                    <tr>
                        <td><?php echo e((new DateTimeImmutable((string) $request['shift_date']))->format('d/m/Y')); ?></td>
                        <td><?php echo e($request['department_name']); ?></td>
                        <td><?php echo e($request['time_slot']); ?></td>
                        <td><?php echo (int) $request['seats_required']; ?></td>
                        <td><?php echo e(trim((string) ($request['creator_prenom'] ?? '')) . ' ' . trim((string) ($request['creator_nom'] ?? ''))); ?></td>
                        <td><?php echo e((string) ($request['comment'] ?? '')); ?></td>
                        <td>
                            <div class="actions">
                                <form method="post">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                    <button class="btn btn-ok" type="submit" name="approve_request" value="1"><?php echo e(fjvT('Valider', 'Goedkeuren')); ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('<?php echo e(fjvT('Refuser cette demande ?', 'Deze aanvraag weigeren?')); ?>');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                    <button class="btn btn-ko" type="submit" name="reject_request" value="1"><?php echo e(fjvT('Refuser', 'Weigeren')); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
