<?php
// ============================================================
// events.php — Cloche : boîte de réception admin (contenus à contrôler)
//   + fil d'événements du site (accessible à tous les utilisateurs connectés).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/events.php';

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// --- Actions de modération (admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    if (!$isAdmin) { header('Location: events.php'); exit(); }
    $act = $_POST['action'] ?? '';
    $mid = (int) ($_POST['module_id'] ?? 0);
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($mid > 0 && $act === 'publish_submission') {
        publishSubmission($db, $mid, $uid);
        $_SESSION['module_flash'] = "✅ Contenu publié.";
    } elseif ($mid > 0 && $act === 'reject_submission') {
        rejectSubmission($db, $mid, $uid);
        $_SESSION['module_flash'] = "↩ Contenu renvoyé en brouillon.";
    }
    header('Location: events.php');
    exit();
}

$flash = '';
if (!empty($_SESSION['module_flash'])) { $flash = $_SESSION['module_flash']; unset($_SESSION['module_flash']); }

$pending = $isAdmin ? eventsPendingSubmissions($db) : [];
$recent = eventsRecent($db, 60);
// Ouvrir les notifications = « tout vu » -> remet le badge de la cloche à zéro.
eventsMarkSeen($db, (int) ($_SESSION['user_id'] ?? 0));

// Noms des auteurs + guide (pour « Relire »).
$authorNames = [];
$guideOf = [];
if ($pending) {
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return (int) ($r['contenu_by'] ?? 0); }, $pending))));
    if ($ids) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, prenom, nom FROM utilisateurs WHERE id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) { $authorNames[(int) $u['id']] = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')); }
        } catch (Exception $e) {}
    }
    foreach ($pending as $p) {
        $pid = (int) $p['id'];
        if (($p['content_status'] ?? '') !== '' && (string) ($p['content_status'] ?? '') === 'pending') { /* ok */ }
        // guide = ce module s'il est 'ecrit', sinon un enfant 'ecrit'
        try {
            $st = $db->prepare("SELECT id FROM modules WHERE (id = ? AND content_kind='ecrit') OR (parent_id = ? AND content_kind='ecrit') ORDER BY (id = ?) DESC LIMIT 1");
            $st->execute([$pid, $pid, $pid]);
            $g = $st->fetchColumn();
            if ($g) { $guideOf[$pid] = (int) $g; }
        } catch (Exception $e) {}
    }
}

$evIcon = function ($t) {
    if ($t === 'content_submitted') { return '📥'; }
    if ($t === 'content_published') { return '✅'; }
    if ($t === 'content_rejected')  { return '↩'; }
    return '•';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — FamiFormation</title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<style>
    :root { --forest:#1E4D2B; --leaf:#3E8E4E; --line:#d9e3dc; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#f4f7f6; margin:0; color:#21301F; }
    .topbar { background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
    .topbar a { color:var(--forest); text-decoration:none; font-weight:700; }
    .wrap { max-width:820px; margin:0 auto; padding:22px 18px 80px; }
    h1 { color:var(--forest); }
    .flash { background:#dff3e3; border:1px solid #b6e0c2; color:#1d6a39; padding:12px 18px; border-radius:12px; margin-bottom:18px; font-weight:700; }
    .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px 20px; margin-bottom:18px; }
    .sub { border:1px solid var(--line); border-left:4px solid #f0a500; border-radius:12px; padding:14px 16px; margin:10px 0; }
    .sub h3 { margin:0 0 4px; color:var(--forest); }
    .sub .meta { color:#5a6b60; font-size:0.88rem; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .btn { border:none; border-radius:9px; padding:8px 14px; font-weight:700; cursor:pointer; text-decoration:none; font:inherit; display:inline-block; }
    .btn-view { background:#eef7f0; color:var(--forest); border:1px solid #cfe3d5; }
    .btn-pub { background:var(--forest); color:#fff; }
    .btn-rej { background:#e9ecef; color:#444; }
    .ev { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid #eef1ec; }
    .ev:last-child { border-bottom:none; }
    .ev .ic { font-size:1.3rem; }
    .ev .txt { flex:1; }
    .ev .when { color:#7a8a80; font-size:0.8rem; white-space:nowrap; }
    .badge { background:#c0392b; color:#fff; border-radius:999px; padding:2px 9px; font-size:0.85rem; font-weight:800; }
    .muted { color:#7a8a80; }
</style>
</head>
<body>
    <div class="topbar">
        <a href="index.php">⬅ Accueil</a>
        <strong>🔔 Notifications</strong>
        <span></span>
    </div>
    <div class="wrap">
        <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

        <?php if ($isAdmin): ?>
        <div class="card">
            <h1 style="margin:0 0 10px;">📥 À contrôler <?php if (count($pending)): ?><span class="badge"><?= count($pending) ?></span><?php endif; ?></h1>
            <?php if (empty($pending)): ?>
                <p class="muted">Rien à contrôler pour l'instant. 🌿</p>
            <?php else: ?>
                <?php foreach ($pending as $p): $pid = (int) $p['id']; $auth = $authorNames[(int) ($p['contenu_by'] ?? 0)] ?? '—'; ?>
                <div class="sub">
                    <h3><?= htmlspecialchars(moduleNom($p)) ?></h3>
                    <div class="meta">Proposé par <strong><?= htmlspecialchars($auth ?: '—') ?></strong></div>
                    <div class="actions">
                        <a class="btn btn-view" href="module.php?id=<?= $pid ?>">👁 Voir</a>
                        <?php if (!empty($guideOf[$pid])): ?><a class="btn btn-view" href="module_review.php?id=<?= (int) $guideOf[$pid] ?>">✏️ Relire / corriger</a><?php endif; ?>
                        <form method="POST" action="events.php" style="display:inline;" onsubmit="return confirm('Publier ce contenu ?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="publish_submission">
                            <input type="hidden" name="module_id" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-pub">✅ Publier</button>
                        </form>
                        <form method="POST" action="events.php" style="display:inline;" onsubmit="return confirm('Renvoyer en brouillon (masquer) ?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reject_submission">
                            <input type="hidden" name="module_id" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-rej">✗ Rejeter</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h1 style="margin:0 0 10px;">🔔 Événements récents</h1>
            <?php if (empty($recent)): ?>
                <p class="muted">Aucun événement pour l'instant.</p>
            <?php else: ?>
                <?php foreach ($recent as $e):
                    $who = trim((string) (($e['prenom'] ?? '') . ' ' . ($e['unom'] ?? '')));
                    $when = !empty($e['created_at']) ? date('d/m/Y H:i', strtotime((string) $e['created_at'])) : '';
                ?>
                <div class="ev">
                    <span class="ic"><?= $evIcon($e['type'] ?? '') ?></span>
                    <div class="txt">
                        <?= htmlspecialchars((string) ($e['message'] ?? '')) ?>
                        <?php if (!empty($e['module_id'])): ?> — <a href="module.php?id=<?= (int) $e['module_id'] ?>" style="color:#2d5a37; font-weight:700; text-decoration:none;"><?= htmlspecialchars((string) ($e['module_nom'] ?? 'voir')) ?></a><?php endif; ?>
                        <?php if ($who !== ''): ?><span class="muted"> · <?= htmlspecialchars($who) ?></span><?php endif; ?>
                    </div>
                    <span class="when"><?= htmlspecialchars($when) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
