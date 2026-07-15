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
    } elseif ($act === 'delete_events') {
        $n = eventsDelete($db, (array) ($_POST['ev'] ?? []));
        $_SESSION['module_flash'] = $n > 0
            ? "🗑️ " . $n . " notification" . ($n > 1 ? 's' : '') . " supprimée" . ($n > 1 ? 's' : '') . "."
            : "❌ Aucune notification sélectionnée.";
    } elseif ($act === 'delete_all_events') {
        $n = eventsDeleteAll($db);
        $_SESSION['module_flash'] = "🗑️ Fil vidé (" . $n . " notification" . ($n > 1 ? 's' : '') . ").";
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
    if ($t === 'ai_doubt')          { return '⚠️'; }
    if ($t === 'module_created')    { return '🧩'; }
    if ($t === 'module_updated')    { return '✏️'; }
    if ($t === 'module_deleted')    { return '🗑️'; }
    if ($t === 'module_toggled')    { return '🔀'; }
    if ($t === 'content_updated')   { return '📎'; }
    return '•';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Notifications', 'Meldingen') ?> — FamiFormation</title>
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
<?php
    require_once __DIR__ . '/includes/topbar.php';
    famiTopbar($db, ['back' => 'index.php', 'title' => '🔔 ' . t('Notifications', 'Meldingen')]);
?>
    <div class="wrap">
        <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

        <?php
            // TROIS BOÎTES DISTINCTES (cf. eventsCategory) :
            //   À contrôler = ce que l'admin doit valider ; Événements = la vie du site ;
            //   Erreurs = ce qui a mal tourné (doutes de l'IA, compression, API).
            $evEvents = [];
            $evErrors = [];
            foreach ($recent as $e) {
                if (eventsCategory($e['type'] ?? '') === 'error') { $evErrors[] = $e; } else { $evEvents[] = $e; }
            }
            $tabs = [];
            if ($isAdmin) { $tabs['todo'] = ['📥 ' . t('À contrôler', 'Te controleren'), count($pending)]; }
            $tabs['event'] = ['🔔 ' . t('Événements', 'Gebeurtenissen'), count($evEvents)];
            $tabs['error'] = ['⚠️ ' . t('Erreurs', 'Fouten'), count($evErrors)];
            $first = array_key_first($tabs);

            // Barre de selection : bouton "Selectionner" + "tout" (corbeille flottante unifiee).
            $evBar = function ($box) {
                ?>
                <div class="evbar">
                    <button type="button" class="btn evtab bulk-toggle" data-bulk-toggle="events" data-bulk-entity="event" data-bulk-nopass data-bulk-label="notification">&#9745; <?= t('Sélectionner', 'Selecteren') ?></button>
                    <label class="evchk"><input type="checkbox" class="bulk-all" data-bulk="events"> <span><?= t('Tout sélectionner', 'Alles selecteren') ?></span></label>
                    <span class="muted" style="margin-left:auto; font-size:.8rem;">&#128161; <?= t('Maj+clic : une plage', 'Shift+klik: een reeks') ?></span>
                </div>
                <?php
            };

            // Rendu d'une liste (cases à cocher pour l'admin).
            $renderList = function (array $list, $box) use ($isAdmin, $evIcon) {
                if (empty($list)) {
                    echo '<p class="muted">' . t("Rien pour l'instant.", 'Nog niets.') . ' 🌿</p>';
                    return;
                }
                foreach ($list as $e) {
                    $who = trim((string) (($e['prenom'] ?? '') . ' ' . ($e['unom'] ?? '')));
                    $when = !empty($e['created_at']) ? date('d/m/Y H:i', strtotime((string) $e['created_at'])) : '';
                    $isErr = (eventsCategory($e['type'] ?? '') === 'error');
                    echo '<div class="ev' . ($isErr ? ' ev-err' : '') . '">';
                    if ($isAdmin) {
                        echo '<input type="checkbox" class="bulk-cb" data-bulk="events" value="' . (int) $e['id'] . '">';
                    }
                    echo '<span class="ic">' . $evIcon($e['type'] ?? '') . '</span><div class="txt">'
                       . htmlspecialchars((string) ($e['message'] ?? ''));
                    if (!empty($e['module_id'])) {
                        echo ' — <a href="module.php?id=' . (int) $e['module_id'] . '" style="color:#2d5a37; font-weight:700; text-decoration:none;">'
                           . htmlspecialchars((string) ($e['module_nom'] ?? 'voir')) . '</a>';
                    }
                    if ($who !== '') { echo '<span class="muted"> · ' . htmlspecialchars($who) . '</span>'; }
                    echo '</div><span class="when">' . htmlspecialchars($when) . '</span></div>';
                }
            };
        ?>

        <div class="evtabs">
            <?php foreach ($tabs as $k => $tb): ?>
                <button type="button" class="evtab<?= $k === $first ? ' on' : '' ?>" data-tab="<?= $k ?>" onclick="evShow('<?= $k ?>')">
                    <?= $tb[0] ?><?php if ($tb[1] > 0): ?> <span class="badge<?= $k === 'error' ? ' warn' : '' ?>"><?= (int) $tb[1] ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($isAdmin): ?>
        <div class="card evpane" id="pane-todo"<?= $first === 'todo' ? '' : ' hidden' ?>>
            <p class="muted" style="margin-top:0;">Les contenus déposés par un teamcoach. Tant qu'ils ne sont pas publiés, personne ne les voit.</p>
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

        <div id="evForm">
            <div class="card evpane bulk-scope" id="pane-event"<?= $first === 'event' ? '' : ' hidden' ?>>
                <p class="muted" style="margin-top:0;"><?= t('La vie du site : modules créés, contenus ajoutés, contenus publiés.', 'Wat er op de site gebeurt: nieuwe modules, toegevoegde en gepubliceerde inhoud.') ?></p>
                <?php if ($isAdmin) { $evBar('event'); } ?>
                <?php $renderList($evEvents, 'event'); ?>
            </div>

            <div class="card evpane bulk-scope" id="pane-error"<?= $first === 'error' ? '' : ' hidden' ?>>
                <p class="muted" style="margin-top:0;"><?= t("Ce qui n'a pas fonctionné : doutes de l'IA sur un contenu, compression vidéo, service externe indisponible.", 'Wat misliep: twijfels van de AI, videocompressie, externe dienst niet bereikbaar.') ?></p>
                <?php if ($isAdmin) { $evBar('error'); } ?>
                <?php $renderList($evErrors, 'error'); ?>
            </div>
        </div>
        <?php require_once __DIR__ . '/includes/bulkselect.php'; echo bulkAssets(); ?>

        <style>
        .evtabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .evtab { border:1px solid var(--line); background:#fff; color:#4a5a50; border-radius:999px; padding:9px 16px; font-weight:700; cursor:pointer; font:inherit; display:inline-flex; align-items:center; gap:7px; }
        .evtab.on { background:var(--forest); color:#fff; border-color:var(--forest); }
        .evtab .badge { background:#c0392b; }
        .evtab .badge.warn { background:#e8a13a; }
        .evbar { display:flex; align-items:center; gap:14px; flex-wrap:wrap; background:#f4f7f5; border:1px solid #dde5e0; border-radius:12px; padding:10px 14px; margin-bottom:12px; }
        .evchk { display:flex; align-items:center; gap:7px; font-weight:700; color:#244230; cursor:pointer; }
        .ev .evbox { margin-right:2px; cursor:pointer; }
        .ev.sel { background:#eef7f0; border-radius:8px; }
        .ev-err { background:#fffaf7; border-left:4px solid #e8a13a; border-radius:8px; padding-left:10px; }
        .evdel[disabled] { opacity:.45; cursor:not-allowed; }
        </style>
        <script>
        function evShow(k) {
            document.querySelectorAll('.evpane').forEach(function (p) { p.hidden = (p.id !== 'pane-' + k); });
            document.querySelectorAll('.evtab').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === k); });
        }
        /* La selection (cases, Maj+clic, corbeille flottante) est geree par le composant unifie. */
        </script>
    </div>
</body>
</html>
