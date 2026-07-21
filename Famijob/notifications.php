<?php
require_once 'config.php';
require_once __DIR__ . '/includes/notifications.php';
verifierConnexion($db);

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'teamcoach'], true)) {
    header('Location: ../index.php');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    if (isset($_POST['mark_all_read'])) {
        $n = famijobNotifMarkAllRead($db, $userId);
        $message = 'Toutes les notifications ont été marquées comme lues.';
    } elseif (isset($_POST['delete_all'])) {
        famijobNotifDeleteAll($db, $userId);
        $message = 'Boîte de notifications vidée.';
    } elseif (isset($_POST['delete_one'])) {
        famijobNotifDelete($db, $userId, (int) ($_POST['notif_id'] ?? 0));
    } elseif (isset($_POST['mark_read'])) {
        famijobNotifMarkRead($db, $userId, (int) ($_POST['notif_id'] ?? 0));
    }
    // PRG : évite le renvoi de formulaire au refresh.
    $suffix = $message !== '' ? ('?m=' . rawurlencode($message)) : '';
    header('Location: notifications.php' . $suffix);
    exit();
}

if (isset($_GET['m'])) {
    $message = (string) $_GET['m'];
}

$notifications = famijobNotifRecent($db, $userId, 100);

function fjnTypeMeta($type)
{
    switch ($type) {
        case 'validation_approved': return ['✅', '#1d6a39', '#dff3e3'];
        case 'validation_rejected': return ['⛔', '#a13e35', '#fae4e1'];
        case 'match':               return ['🤝', '#1b5e7a', '#dbeefa'];
        default:                    return ['🔔', '#5c6f67', '#eef3f0'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(famiLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - FamiJob</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#eef3f0; --card:#fff; --line:#dde6df; --text:#1b2c25; --muted:#5c6f67; --accent:#2d5a37; --shadow:0 14px 34px rgba(22,49,33,.1); }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px 16px 60px; background:var(--bg); font-family:'Manrope',sans-serif; color:var(--text); }
        .page { max-width:820px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; border-radius:22px; padding:22px 24px; box-shadow:var(--shadow); margin-bottom:18px; }
        .hero h1 { margin:6px 0 4px; font-size:1.5rem; }
        .hero p { margin:0; opacity:.9; font-size:.92rem; }
        .hero a.back { color:#fff; text-decoration:none; font-weight:700; background:rgba(255,255,255,.16); padding:8px 14px; border-radius:999px; display:inline-block; }
        .bar { display:flex; gap:10px; justify-content:flex-end; margin-bottom:14px; flex-wrap:wrap; }
        .btn { border:none; border-radius:10px; padding:9px 14px; font-weight:700; cursor:pointer; font-family:inherit; font-size:.86rem; }
        .btn-soft { background:#edf5ef; color:var(--accent); }
        .btn-ko { background:#fae4e1; color:#a13e35; }
        .alert { padding:11px 14px; border-radius:12px; font-weight:700; margin-bottom:14px; background:#dff3e3; color:#1d6a39; }
        .list { display:flex; flex-direction:column; gap:10px; }
        .card { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:14px 16px; display:flex; gap:13px; align-items:flex-start; border-left:5px solid transparent; }
        .card.unread { border-left-color:var(--accent); background:#fbfefc; }
        .ico { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex:none; }
        .body { flex:1; min-width:0; }
        .title { font-weight:800; margin:0 0 3px; display:flex; align-items:center; gap:8px; }
        .badge-new { background:var(--accent); color:#fff; font-size:.62rem; font-weight:800; padding:2px 7px; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; }
        .txt { color:var(--muted); font-size:.9rem; line-height:1.45; margin:0 0 6px; }
        .meta { color:#93a29a; font-size:.76rem; }
        .row-actions { display:flex; gap:6px; align-items:center; }
        .link { text-decoration:none; color:var(--accent); font-weight:700; font-size:.82rem; }
        .icon-btn { border:none; background:transparent; cursor:pointer; color:#b0bcb4; font-size:1rem; padding:4px; border-radius:8px; }
        .icon-btn:hover { color:#a13e35; background:#fae4e1; }
        .empty { background:#fff; border-radius:16px; box-shadow:var(--shadow); padding:28px; text-align:center; color:var(--muted); }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a class="back" href="index.php">⬅ Retour FamiJob</a>
        <h1>🔔 Mes notifications</h1>
        <p>Validation de vos demandes d'horaire, matching de vos créneaux…</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($notifications)): ?>
    <div class="bar">
        <form method="post">
            <?= csrfField() ?>
            <button class="btn btn-soft" type="submit" name="mark_all_read" value="1">Tout marquer lu</button>
        </form>
        <form method="post" onsubmit="return confirm('Vider toute la boîte de notifications ?');">
            <?= csrfField() ?>
            <button class="btn btn-ko" type="submit" name="delete_all" value="1">Tout supprimer</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
        <div class="empty">Aucune notification pour le moment.</div>
    <?php else: ?>
        <div class="list">
            <?php foreach ($notifications as $notif): ?>
                <?php
                    [$emoji, $color, $bg] = fjnTypeMeta((string) $notif['type']);
                    $isUnread = (int) $notif['is_read'] === 0;
                    $createdLabel = '';
                    try { $createdLabel = (new DateTimeImmutable((string) $notif['created_at']))->format('d/m/Y à H:i'); } catch (Exception $e) {}
                ?>
                <div class="card <?= $isUnread ? 'unread' : '' ?>">
                    <div class="ico" style="background:<?= e($bg) ?>;color:<?= e($color) ?>;"><?= $emoji ?></div>
                    <div class="body">
                        <p class="title">
                            <?= e((string) $notif['title']) ?>
                            <?php if ($isUnread): ?><span class="badge-new">Nouveau</span><?php endif; ?>
                        </p>
                        <?php if (trim((string) ($notif['body'] ?? '')) !== ''): ?>
                            <p class="txt"><?= e((string) $notif['body']) ?></p>
                        <?php endif; ?>
                        <div class="row-actions">
                            <span class="meta"><?= e($createdLabel) ?></span>
                            <?php if (trim((string) ($notif['link'] ?? '')) !== ''): ?>
                                &nbsp;·&nbsp;<a class="link" href="<?= e((string) $notif['link']) ?>">Voir</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row-actions">
                        <?php if ($isUnread): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="notif_id" value="<?= (int) $notif['id'] ?>">
                            <button class="icon-btn" type="submit" name="mark_read" value="1" title="Marquer comme lu" style="color:#2d5a37;">✓</button>
                        </form>
                        <?php endif; ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="notif_id" value="<?= (int) $notif['id'] ?>">
                            <button class="icon-btn" type="submit" name="delete_one" value="1" title="Supprimer">✕</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
