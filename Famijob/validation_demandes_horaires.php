<?php
require_once 'config.php';
require_once __DIR__ . '/includes/notifications.php';
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
    header('Location: ../index.php');
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

// Par défaut : la semaine en cours (les semaines précédentes restent sélectionnables).
$currentWeekKey = $startMonday->format('Y-m-d');
$selectedWeekKey = (string) ($_GET['week'] ?? $currentWeekKey);
if (!isset($weekOptions[$selectedWeekKey])) {
    $selectedWeekKey = $currentWeekKey;
}
$selectedWeek = $weekOptions[$selectedWeekKey];

$departmentOptions = $db->query(
    "SELECT DISTINCT department_name
     FROM interim_shift_requests
     WHERE TRIM(COALESCE(department_name, '')) <> ''
     ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$selectedDepartment = trim((string) ($_GET['department'] ?? 'all'));
if ($selectedDepartment !== 'all' && !in_array($selectedDepartment, $departmentOptions, true)) {
    $selectedDepartment = 'all';
}

// Filtre par statut : en attente (par defaut), validees, refusees, ou toutes.
$statusOptions = ['pending', 'approved', 'rejected', 'all'];
$selectedStatus = trim((string) ($_GET['status'] ?? ($_POST['status'] ?? 'pending')));
if (!in_array($selectedStatus, $statusOptions, true)) {
    $selectedStatus = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $selectedWeekKey = (string) ($_POST['week'] ?? $selectedWeekKey);
    if (!isset($weekOptions[$selectedWeekKey])) {
        $selectedWeekKey = $currentWeekKey;
    }
    $selectedWeek = $weekOptions[$selectedWeekKey];

    $selectedDepartment = trim((string) ($_POST['department'] ?? $selectedDepartment));
    if ($selectedDepartment !== 'all' && !in_array($selectedDepartment, $departmentOptions, true)) {
        $selectedDepartment = 'all';
    }

    // Valide/refuse une liste de demandes et previent chaque createur via la boite a notif.
    $bulkDecide = static function (array $ids, $decision, $reason = '') use ($db, $currentUserId) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($n) { return $n > 0; })));
        if (empty($ids)) {
            return 0;
        }
        $status = ($decision === 'rejected') ? 'rejected' : 'approved';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "UPDATE interim_shift_requests
             SET validation_status = '$status', validated_by_user_id = ?, validated_at = NOW()
             WHERE id IN ($ph)"
        );
        $stmt->execute(array_merge([$currentUserId], $ids));
        foreach ($ids as $rid) {
            famijobNotifyRequestValidated($db, $rid, $currentUserId, $decision, $reason);
        }
        return $stmt->rowCount();
    };

    // Recupere les ids en attente correspondant au filtre (avant validation groupee).
    $pendingIdsForScope = static function ($department) use ($db, $selectedWeek) {
        $sql = "SELECT id FROM interim_shift_requests
                WHERE shift_date BETWEEN ? AND ? AND validation_status = 'pending'";
        $params = [$selectedWeek['start']->format('Y-m-d'), $selectedWeek['end']->format('Y-m-d')];
        if ($department !== 'all') {
            $sql .= ' AND department_name = ?';
            $params[] = $department;
        }
        $st = $db->prepare($sql);
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };

    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId > 0 && isset($_POST['approve_request'])) {
        $bulkDecide([$requestId], 'approved');
        $message = "<div class='alert success'>" . e(fjvT('Demande validée.', 'Aanvraag goedgekeurd.')) . "</div>";
    } elseif ($requestId > 0 && isset($_POST['reject_request'])) {
        $rejectReason = trim((string) ($_POST['reject_reason'] ?? ''));
        $bulkDecide([$requestId], 'rejected', mb_substr($rejectReason, 0, 500));
        $message = "<div class='alert error'>" . e(fjvT('Demande refusée.', 'Aanvraag geweigerd.')) . "</div>";
    } elseif (isset($_POST['approve_all'])) {
        $ids = $pendingIdsForScope($selectedDepartment);
        $count = $bulkDecide($ids, 'approved');
        $message = "<div class='alert success'>" . e(fjvT('Validation globale effectuée :', 'Globale validatie uitgevoerd:')) . ' ' . (int) $count . ' ' . e(fjvT('demande(s).', 'aanvraag/aanvragen.')) . "</div>";
    } elseif (isset($_POST['approve_department'])) {
        if ($selectedDepartment === 'all') {
            $message = "<div class='alert error'>" . e(fjvT('Sélectionne un département pour la validation par département.', 'Selecteer een afdeling voor validatie per afdeling.')) . "</div>";
        } else {
            $ids = $pendingIdsForScope($selectedDepartment);
            $count = $bulkDecide($ids, 'approved');
            $message = "<div class='alert success'>" . e(fjvT('Validation du département', 'Validatie van afdeling')) . ' ' . e($selectedDepartment) . ': ' . (int) $count . ' ' . e(fjvT('demande(s).', 'aanvraag/aanvragen.')) . "</div>";
        }
    } elseif ($requestId > 0 && isset($_POST['revert_request'])) {
        // Remettre une demande (validee ou refusee) en attente.
        $db->prepare("UPDATE interim_shift_requests SET validation_status = 'pending', validated_by_user_id = NULL, validated_at = NULL WHERE id = ?")->execute([$requestId]);
        $message = "<div class='alert success'>" . e(fjvT('Demande remise en attente.', 'Aanvraag terug in afwachting.')) . "</div>";
    } elseif ($requestId > 0 && isset($_POST['delete_request'])) {
        // Suppression definitive d'une demande + ses affectations (prevenir le createur).
        try {
            $st = $db->prepare("SELECT shift_date, department_name, time_slot, created_by_user_id FROM interim_shift_requests WHERE id = ? LIMIT 1");
            $st->execute([$requestId]);
            $reqRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($reqRow && (int) $reqRow['created_by_user_id'] > 0 && (int) $reqRow['created_by_user_id'] !== $currentUserId) {
                $lbl = famijobFormatShiftLabel($reqRow);
                famijobNotify($db, (int) $reqRow['created_by_user_id'], 'info', 'Demande supprimée', 'Votre demande d\'horaire (' . $lbl . ') a été supprimée par un administrateur.', 'interim_horaires_demandes.php', $currentUserId, '');
            }
        } catch (Exception $e) {}
        try { $db->prepare("DELETE FROM interim_shift_assignments WHERE request_id = ?")->execute([$requestId]); } catch (Exception $e) {}
        $db->prepare("DELETE FROM interim_shift_requests WHERE id = ?")->execute([$requestId]);
        $message = "<div class='alert success'>" . e(fjvT('Demande supprimée.', 'Aanvraag verwijderd.')) . "</div>";
    }

    // Les demandes viennent d'être traitées : on marque les notifs "nouvelles demandes"
    // de cet admin comme lues (elles ne servent plus, il est en train de les traiter).
    famijobNotifMarkReadByType($db, $currentUserId, 'demande_created');
}

$pendingSql =
    "SELECT r.id, r.shift_date, r.department_name, r.time_slot, r.seats_required, r.comment, r.validation_status,
            u.nom AS creator_nom, u.prenom AS creator_prenom
     FROM interim_shift_requests r
     LEFT JOIN utilisateurs u ON u.id = r.created_by_user_id
     WHERE r.shift_date BETWEEN ? AND ?";
$pendingParams = [
    $selectedWeek['start']->format('Y-m-d'),
    $selectedWeek['end']->format('Y-m-d'),
];
if ($selectedStatus !== 'all') {
    $pendingSql .= ' AND r.validation_status = ?';
    $pendingParams[] = $selectedStatus;
}
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
        .seat-badge { display:inline-block; min-width:34px; text-align:center; background:#edf5ef; color:var(--accent); font-weight:800; font-size:.82rem; padding:3px 8px; border-radius:999px; }
        tr.date-band td { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; font-weight:800; font-size:1rem; letter-spacing:.02em; padding:12px 14px; text-transform:capitalize; position:sticky; }
        tr.date-band + tr.req-row td { border-top:none; }
        tr.req-first td { border-top:2px solid #cddccf; }
        tr.req-row:not(.req-last) td:nth-child(-n+4) { border-bottom:1px dashed #e6eee8; }
        tbody tr:hover td { background:#f9fdfa; }
    </style>
</head>
<body>
<?php require_once __DIR__ . "/includes/topbar.php"; famijobRibbon($db); ?>
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
        <div>
            <label for="status"><?php echo e(fjvT('Statut', 'Status')); ?></label>
            <select id="status" name="status" style="min-width:190px;">
                <option value="pending" <?php echo $selectedStatus === 'pending' ? 'selected' : ''; ?>><?php echo e(fjvT('En attente', 'In afwachting')); ?></option>
                <option value="approved" <?php echo $selectedStatus === 'approved' ? 'selected' : ''; ?>><?php echo e(fjvT('Validées', 'Goedgekeurd')); ?></option>
                <option value="rejected" <?php echo $selectedStatus === 'rejected' ? 'selected' : ''; ?>><?php echo e(fjvT('Refusées', 'Geweigerd')); ?></option>
                <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>><?php echo e(fjvT('Toutes', 'Alle')); ?></option>
            </select>
        </div>
        <button class="btn btn-soft" type="submit"><?php echo e(fjvT('Afficher', 'Tonen')); ?></button>
    </form>

    <?php if ($selectedStatus === 'pending'): ?>
    <form method="post" class="toolbar" onsubmit="return confirm('<?php echo e(fjvT('Valider les demandes selon l\'action choisie ?', 'Aanvragen valideren volgens de gekozen actie?')); ?>');">
        <?php echo csrfField(); ?>
        <input type="hidden" name="week" value="<?php echo e($selectedWeekKey); ?>">
        <input type="hidden" name="department" value="<?php echo e($selectedDepartment); ?>">
        <input type="hidden" name="status" value="pending">
        <div class="toolbar-actions">
            <button class="btn btn-ok" type="submit" name="approve_all" value="1"><?php echo e(fjvT('Validation globale', 'Globale validatie')); ?></button>
            <button class="btn btn-soft" type="submit" name="approve_department" value="1"><?php echo e(fjvT('Validation globale par département', 'Globale validatie per afdeling')); ?></button>
        </div>
    </form>
    <?php endif; ?>

    <?php if (empty($pendingRequests)): ?>
        <div class="empty"><?php echo e(fjvT('Aucune demande sur la période et le statut sélectionnés.', 'Geen aanvraag voor de geselecteerde periode en status.')); ?></div>
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
                <?php
                    $joursFr = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
                    $joursNl = [1 => 'Maandag', 2 => 'Dinsdag', 3 => 'Woensdag', 4 => 'Donderdag', 5 => 'Vrijdag', 6 => 'Zaterdag', 7 => 'Zondag'];
                    $jours = famiLang() === 'nl' ? $joursNl : $joursFr;
                    $currentBandDate = null;
                ?>
                <?php foreach ($pendingRequests as $request): ?>
                    <?php
                        $dateKey = (string) $request['shift_date'];
                        if ($dateKey !== $currentBandDate):
                            $currentBandDate = $dateKey;
                            $bandD = new DateTimeImmutable($dateKey);
                            $bandLabel = ($jours[(int) $bandD->format('N')] ?? '') . ' ' . $bandD->format('d/m/Y');
                    ?>
                        <tr class="date-band"><td colspan="7">📅 <?php echo e($bandLabel); ?></td></tr>
                    <?php endif; ?>
                    <?php
                        $seats = max(1, (int) $request['seats_required']);
                        $dateLabel = e((new DateTimeImmutable((string) $request['shift_date']))->format('d/m/Y'));
                        $deptLabel = e((string) $request['department_name']);
                        $slotLabel = e((string) $request['time_slot']);
                        $creatorLabel = e(trim(trim((string) ($request['creator_prenom'] ?? '')) . ' ' . trim((string) ($request['creator_nom'] ?? ''))));
                        $commentLabel = e((string) ($request['comment'] ?? ''));
                        $reqStatus = (string) ($request['validation_status'] ?? 'pending');
                    ?>
                    <?php for ($seat = 1; $seat <= $seats; $seat++): ?>
                        <tr class="req-row <?php echo $seat === 1 ? 'req-first' : ''; ?><?php echo $seat === $seats ? ' req-last' : ''; ?>">
                            <td><?php echo $dateLabel; ?></td>
                            <td><?php echo $deptLabel; ?></td>
                            <td><?php echo $slotLabel; ?></td>
                            <td>
                                <span class="seat-badge"><?php echo $seat; ?><?php echo $seats > 1 ? ' / ' . $seats : ''; ?></span>
                            </td>
                            <?php if ($seat === 1): ?>
                                <td rowspan="<?php echo $seats; ?>"><?php echo $creatorLabel; ?></td>
                                <td rowspan="<?php echo $seats; ?>"><?php echo $commentLabel; ?></td>
                                <td rowspan="<?php echo $seats; ?>">
                                    <?php if ($reqStatus === 'approved'): ?>
                                        <div style="font-weight:800;color:#1d6a39;margin-bottom:6px;">✔ <?php echo e(fjvT('Validée', 'Goedgekeurd')); ?></div>
                                    <?php elseif ($reqStatus === 'rejected'): ?>
                                        <div style="font-weight:800;color:var(--warn);margin-bottom:6px;">✖ <?php echo e(fjvT('Refusée', 'Geweigerd')); ?></div>
                                    <?php endif; ?>
                                    <div class="actions">
                                        <?php if ($reqStatus === 'pending'): ?>
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                <button class="btn btn-ok" type="submit" name="approve_request" value="1"><?php echo e(fjvT('Valider', 'Goedkeuren')); ?></button>
                                            </form>
                                            <form method="post" onsubmit="return fjvOpenRejectModal(this);">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                <input type="hidden" name="reject_reason" value="">
                                                <input type="hidden" name="reject_request" value="1">
                                                <button class="btn btn-ko" type="submit"><?php echo e(fjvT('Refuser', 'Weigeren')); ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo e($selectedStatus); ?>">
                                                <button class="btn btn-soft" type="submit" name="revert_request" value="1"><?php echo e(fjvT('Remettre en attente', 'Terug in afwachting')); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('<?php echo e(fjvT('Supprimer définitivement cette demande (et ses affectations) ?', 'Deze aanvraag (en toewijzingen) definitief verwijderen?')); ?>');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo e($selectedStatus); ?>">
                                            <button class="btn btn-ko" type="submit" name="delete_request" value="1">🗑 <?php echo e(fjvT('Supprimer', 'Verwijderen')); ?></button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<div class="fjv-modal-mask" id="fjvRejectModal">
    <div class="fjv-modal" role="dialog" aria-modal="true">
        <div class="fjv-modal-ic">⛔</div>
        <h3><?php echo e(fjvT('Refuser la demande', 'Aanvraag weigeren')); ?></h3>
        <p><?php echo e(fjvT('Motif du refus (optionnel) — la personne le recevra dans sa notification.', 'Reden van weigering (optioneel) — de persoon ontvangt dit in zijn melding.')); ?></p>
        <textarea id="fjvRejectReason" rows="4" maxlength="500" placeholder="<?php echo e(fjvT('Ex. : créneau annulé, doublon, erreur de date…', 'Bv.: geannuleerd, dubbel, verkeerde datum…')); ?>"></textarea>
        <div class="fjv-modal-actions">
            <button type="button" class="fjv-mbtn fjv-mbtn-cancel" onclick="fjvCloseRejectModal()"><?php echo e(fjvT('Annuler', 'Annuleren')); ?></button>
            <button type="button" class="fjv-mbtn fjv-mbtn-danger" onclick="fjvConfirmReject()"><?php echo e(fjvT('Confirmer le refus', 'Weigering bevestigen')); ?></button>
        </div>
    </div>
</div>

<style>
    .fjv-modal-mask { position:fixed; inset:0; background:rgba(15,36,29,.5); display:none; align-items:center; justify-content:center; z-index:9000; padding:16px; }
    .fjv-modal-mask.show { display:flex; }
    .fjv-modal { background:#fff; border-radius:20px; padding:26px 24px 20px; max-width:430px; width:100%; box-shadow:0 30px 70px rgba(8,22,17,.4); text-align:center; animation:fjvPop .18s ease; }
    @keyframes fjvPop { from { transform:scale(.94); opacity:.5; } to { transform:scale(1); opacity:1; } }
    .fjv-modal-ic { width:56px; height:56px; margin:0 auto 12px; border-radius:50%; background:#fae4e1; color:#a13e35; display:flex; align-items:center; justify-content:center; font-size:1.7rem; }
    .fjv-modal h3 { margin:0 0 6px; color:#21362a; font-size:1.2rem; }
    .fjv-modal p { margin:0 0 16px; color:#64756a; font-size:.9rem; line-height:1.5; }
    .fjv-modal textarea { width:100%; border:1px solid #cfdad3; border-radius:12px; padding:12px 14px; font-family:inherit; font-size:.95rem; resize:vertical; min-height:92px; text-align:left; }
    .fjv-modal-actions { display:flex; gap:10px; margin-top:18px; }
    .fjv-mbtn { flex:1; border:none; border-radius:12px; padding:13px 14px; font-weight:800; font-size:.95rem; cursor:pointer; font-family:inherit; }
    .fjv-mbtn-cancel { background:#eef2f0; color:#3a4a42; }
    .fjv-mbtn-cancel:hover { background:#e2e8e5; }
    .fjv-mbtn-danger { background:#c0392b; color:#fff; }
    .fjv-mbtn-danger:hover { background:#a5301f; }
</style>

<script>
var fjvRejectForm = null;
function fjvOpenRejectModal(form) {
    fjvRejectForm = form;
    var ta = document.getElementById('fjvRejectReason');
    if (ta) { ta.value = ''; }
    document.getElementById('fjvRejectModal').classList.add('show');
    if (ta) { ta.focus(); }
    return false; // on n'envoie pas encore : on attend la confirmation dans la modale
}
function fjvCloseRejectModal() {
    document.getElementById('fjvRejectModal').classList.remove('show');
    fjvRejectForm = null;
}
function fjvConfirmReject() {
    if (!fjvRejectForm) { return; }
    var ta = document.getElementById('fjvRejectReason');
    var field = fjvRejectForm.querySelector('input[name="reject_reason"]');
    if (field && ta) { field.value = ta.value; }
    var f = fjvRejectForm;
    fjvRejectForm = null;
    f.submit();
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fjvCloseRejectModal(); } });
(function () {
    var mask = document.getElementById('fjvRejectModal');
    if (mask) { mask.addEventListener('click', function (e) { if (e.target === this) { fjvCloseRejectModal(); } }); }
})();
</script>
</body>
</html>
