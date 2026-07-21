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
$isAdmin = ($role === 'admin');
$fullName = trim(trim((string) ($_SESSION['prenom'] ?? '')) . ' ' . trim((string) ($_SESSION['nom'] ?? '')));
if ($fullName === '') {
    $fullName = (string) ($_SESSION['username'] ?? '');
}

// --- Tables ---
$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        author_id INT NOT NULL,
        author_name VARCHAR(160) NULL,
        author_role VARCHAR(30) NULL,
        category VARCHAR(20) NOT NULL DEFAULT 'autre',
        subject VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        admin_note TEXT NULL,
        handled_by_user_id INT NULL,
        handled_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_feedback_author (author_id),
        INDEX idx_feedback_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS interim_feedback_replies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        feedback_id INT UNSIGNED NOT NULL,
        author_id INT NOT NULL,
        author_name VARCHAR(160) NULL,
        author_role VARCHAR(30) NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reply_feedback (feedback_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$categories = [
    'question'    => 'Question',
    'amelioration' => 'Suggestion d\'amélioration',
    'probleme'    => 'Signaler un problème',
    'autre'       => 'Autre',
];

$message = '';
$formError = '';
$oldSubject = '';
$oldMessage = '';
$oldCategory = 'question';

// Notifie tous les admins (sauf celui qui agit).
$notifyAdmins = function (PDO $db, $title, $body, $actorId, $actorName) use ($userId) {
    try {
        $admins = $db->query("SELECT id FROM utilisateurs WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            $adminId = (int) $adminId;
            if ($adminId <= 0 || $adminId === (int) $actorId) {
                continue;
            }
            famijobNotify($db, $adminId, 'feedback', $title, $body, 'avis.php', (int) $actorId, $actorName);
        }
    } catch (Exception $e) {}
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    if (isset($_POST['submit_feedback'])) {
        $category = (string) ($_POST['category'] ?? 'autre');
        if (!isset($categories[$category])) {
            $category = 'autre';
        }
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['message'] ?? ''));
        $oldSubject = $subject;
        $oldMessage = $body;
        $oldCategory = $category;

        if ($subject === '' || $body === '') {
            $formError = 'Merci de renseigner un objet et un message.';
        } else {
            $stmt = $db->prepare(
                "INSERT INTO interim_feedback (author_id, author_name, author_role, category, subject, message, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())"
            );
            $stmt->execute([
                $userId,
                mb_substr($fullName, 0, 160),
                $role,
                $category,
                mb_substr($subject, 0, 180),
                mb_substr($body, 0, 5000),
            ]);

            $categoryLabel = $categories[$category] ?? 'Avis';
            $notifyAdmins(
                $db,
                'Nouvel avis reçu',
                $categoryLabel . ' de ' . ($fullName !== '' ? $fullName : 'un utilisateur') . ' : ' . $subject,
                $userId,
                $fullName
            );

            $message = 'Merci ! Votre avis a bien été transmis.';
            $oldSubject = $oldMessage = '';
            $oldCategory = 'question';
        }
    } elseif (isset($_POST['post_reply'])) {
        // Réponse dans le fil : autorisée à l'admin (tout avis) OU à l'auteur de l'avis (le sien).
        $fid = (int) ($_POST['feedback_id'] ?? 0);
        $replyText = trim((string) ($_POST['reply_message'] ?? ''));
        if ($fid > 0 && $replyText !== '') {
            $fbStmt = $db->prepare("SELECT id, author_id, subject, status FROM interim_feedback WHERE id = ? LIMIT 1");
            $fbStmt->execute([$fid]);
            $fb = $fbStmt->fetch(PDO::FETCH_ASSOC);

            $authorId = $fb ? (int) $fb['author_id'] : 0;
            $canReply = $fb && ($isAdmin || $authorId === $userId);

            if ($canReply) {
                $db->prepare(
                    "INSERT INTO interim_feedback_replies (feedback_id, author_id, author_name, author_role, message, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                )->execute([$fid, $userId, mb_substr($fullName, 0, 160), $role, mb_substr($replyText, 0, 5000)]);

                $subject = (string) $fb['subject'];
                $extract = mb_substr($replyText, 0, 160);

                if ($userId === $authorId) {
                    // L'auteur relance : on rouvre et on prévient les admins.
                    $db->prepare("UPDATE interim_feedback SET status = 'open', handled_at = NULL WHERE id = ?")->execute([$fid]);
                    $notifyAdmins($db, 'Nouvelle réponse à un avis', 'Réponse de ' . ($fullName !== '' ? $fullName : 'un utilisateur') . ' sur « ' . $subject . ' » : ' . $extract, $userId, $fullName);
                    $message = 'Votre réponse a été envoyée.';
                } else {
                    // Admin (ou autre) répond à l'auteur : on prévient l'auteur.
                    if ($authorId > 0) {
                        famijobNotify($db, $authorId, 'feedback', 'Réponse à votre avis', 'Réponse à votre avis « ' . $subject . ' » : ' . $extract, 'avis.php', $userId, $fullName);
                    }
                    $message = 'Réponse envoyée à ' . (string) (famijobActorName($db, $authorId) ?: 'l\'auteur') . '.';
                }
            }
        }
    } elseif ($isAdmin && isset($_POST['resolve_feedback'])) {
        $fid = (int) ($_POST['feedback_id'] ?? 0);
        if ($fid > 0) {
            $db->prepare("UPDATE interim_feedback SET status = 'resolved', handled_by_user_id = ?, handled_at = NOW() WHERE id = ?")->execute([$userId, $fid]);
            try {
                $row = $db->prepare("SELECT author_id, subject FROM interim_feedback WHERE id = ? LIMIT 1");
                $row->execute([$fid]);
                $fb = $row->fetch(PDO::FETCH_ASSOC);
                if ($fb && (int) $fb['author_id'] > 0 && (int) $fb['author_id'] !== $userId) {
                    famijobNotify($db, (int) $fb['author_id'], 'feedback', 'Avis traité', 'Votre avis « ' . (string) $fb['subject'] . ' » a été marqué comme traité.', 'avis.php', $userId, $fullName);
                }
            } catch (Exception $e) {}
            $message = 'Avis marqué comme traité.';
        }
    } elseif ($isAdmin && isset($_POST['reopen_feedback'])) {
        $fid = (int) ($_POST['feedback_id'] ?? 0);
        if ($fid > 0) {
            $db->prepare("UPDATE interim_feedback SET status = 'open', handled_at = NULL WHERE id = ?")->execute([$fid]);
            $message = 'Avis rouvert.';
        }
    } elseif ($isAdmin && isset($_POST['delete_feedback'])) {
        $fid = (int) ($_POST['feedback_id'] ?? 0);
        if ($fid > 0) {
            try { $db->prepare("DELETE FROM interim_feedback_replies WHERE feedback_id = ?")->execute([$fid]); } catch (Exception $e) {}
            $db->prepare("DELETE FROM interim_feedback WHERE id = ?")->execute([$fid]);
            $message = 'Avis supprimé.';
        }
    }

    // PRG : on revient proprement (evite le renvoi de formulaire au refresh).
    $qs = [];
    if ($isAdmin && isset($_GET['status'])) { $qs['status'] = (string) $_GET['status']; }
    if ($message !== '') { $qs['m'] = $message; }
    if ($formError === '') {
        header('Location: avis.php' . (!empty($qs) ? '?' . http_build_query($qs) : ''));
        exit();
    }
}

if (isset($_GET['m'])) { $message = (string) $_GET['m']; }

// --- Liste ---
if ($isAdmin) {
    $statusFilter = (string) ($_GET['status'] ?? 'open');
    if (!in_array($statusFilter, ['open', 'resolved', 'all'], true)) {
        $statusFilter = 'open';
    }
    $sql = "SELECT f.* FROM interim_feedback f";
    $params = [];
    if ($statusFilter !== 'all') {
        $sql .= " WHERE f.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY f.created_at DESC LIMIT 300";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $feedbackList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $openCount = (int) $db->query("SELECT COUNT(*) FROM interim_feedback WHERE status = 'open'")->fetchColumn();
} else {
    $statusFilter = 'mine';
    $stmt = $db->prepare("SELECT * FROM interim_feedback WHERE author_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$userId]);
    $feedbackList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $openCount = 0;
}

// --- Réponses (fil) pour les avis affichés ---
$repliesByFeedback = [];
$fbIds = array_map(static function ($f) { return (int) $f['id']; }, $feedbackList);
if (!empty($fbIds)) {
    $ph = implode(',', array_fill(0, count($fbIds), '?'));
    try {
        $rstmt = $db->prepare("SELECT * FROM interim_feedback_replies WHERE feedback_id IN ($ph) ORDER BY created_at ASC, id ASC");
        $rstmt->execute($fbIds);
        foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $rep) {
            $repliesByFeedback[(int) $rep['feedback_id']][] = $rep;
        }
    } catch (Exception $e) {}
}

function fjaCategoryLabel($cat, array $categories)
{
    return $categories[$cat] ?? 'Autre';
}
function fjaFmtDate($value)
{
    try { return (new DateTimeImmutable((string) $value))->format('d/m/Y à H:i'); } catch (Exception $e) { return ''; }
}
?>
<!DOCTYPE html>
<html lang="<?= e(famiLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avis & suggestions - FamiJob</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#eef3f0; --card:#fff; --line:#dde6df; --text:#1b2c25; --muted:#5c6f67; --accent:#2d5a37; --shadow:0 14px 34px rgba(22,49,33,.1); }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px 16px 60px; background:var(--bg); font-family:'Manrope',sans-serif; color:var(--text); }
        .page { max-width:860px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; border-radius:22px; padding:22px 24px; box-shadow:var(--shadow); margin-bottom:18px; }
        .hero h1 { margin:6px 0 4px; font-size:1.5rem; }
        .hero p { margin:0; opacity:.9; font-size:.92rem; }
        .hero a.back { color:#fff; text-decoration:none; font-weight:700; background:rgba(255,255,255,.16); padding:8px 14px; border-radius:999px; display:inline-block; }
        .card { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:18px; margin-bottom:18px; }
        .card h2 { margin:0 0 14px; font-size:1.1rem; }
        label { display:block; margin-bottom:6px; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:800; }
        input[type=text], textarea, select { width:100%; border:1px solid #cfdad3; border-radius:10px; padding:10px 12px; font-family:inherit; font-size:.95rem; margin-bottom:14px; }
        textarea { min-height:120px; resize:vertical; }
        .btn { border:none; border-radius:10px; padding:11px 18px; font-weight:800; cursor:pointer; font-family:inherit; font-size:.9rem; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-soft { background:#edf5ef; color:var(--accent); }
        .btn-ko { background:#fae4e1; color:#a13e35; }
        .alert { padding:11px 14px; border-radius:12px; font-weight:700; margin-bottom:16px; background:#dff3e3; color:#1d6a39; }
        .alert.err { background:#fae4e1; color:#a13e35; }
        .filters { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .chip { text-decoration:none; padding:8px 14px; border-radius:999px; font-weight:700; font-size:.84rem; background:#fff; color:var(--muted); box-shadow:var(--shadow); }
        .chip.active { background:var(--accent); color:#fff; }
        .fb { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:16px 18px; margin-bottom:12px; border-left:5px solid #cfdad3; }
        .fb.open { border-left-color:var(--accent); }
        .fb.resolved { border-left-color:#9bb0a3; opacity:.96; }
        .fb-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline; }
        .fb-subject { font-weight:800; font-size:1rem; margin:0; }
        .fb-cat { font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:800; color:#fff; background:#3f6b4d; padding:3px 9px; border-radius:999px; }
        .fb-msg { color:#2a3b33; font-size:.94rem; line-height:1.5; margin:10px 0; white-space:pre-wrap; }
        .fb-meta { color:#93a29a; font-size:.78rem; }
        .fb-status { font-size:.72rem; font-weight:800; text-transform:uppercase; }
        .fb-status.open { color:var(--accent); }
        .fb-status.resolved { color:#7d8f84; }
        .thread { margin:12px 0 4px; display:flex; flex-direction:column; gap:8px; }
        .bubble { border-radius:12px; padding:10px 12px; font-size:.92rem; line-height:1.45; max-width:88%; white-space:pre-wrap; }
        .bubble .who { font-weight:800; font-size:.76rem; margin-bottom:3px; }
        .bubble .when { color:#93a29a; font-size:.72rem; margin-top:4px; }
        .bubble.admin { background:#e9f3ec; border:1px solid #d5e8db; align-self:flex-start; }
        .bubble.admin .who { color:var(--accent); }
        .bubble.user { background:#eef2f7; border:1px solid #dde5ef; align-self:flex-end; }
        .bubble.user .who { color:#3a5a80; }
        .legacy-note { background:#f3f8f5; border-radius:10px; padding:10px 12px; margin-top:10px; font-size:.9rem; }
        .reply-form { margin-top:12px; border-top:1px dashed var(--line); padding-top:12px; }
        .reply-form textarea { min-height:56px; margin-bottom:8px; }
        .row-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; }
        .empty { background:#fff; border-radius:16px; box-shadow:var(--shadow); padding:28px; text-align:center; color:var(--muted); }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a class="back" href="index.php">⬅ Retour FamiJob</a>
        <h1>💬 Avis &amp; suggestions</h1>
        <p><?= $isAdmin ? 'Vous voyez tous les avis, vous pouvez répondre et clôturer.' : 'Une question, une idée, un souci ? Faites-le nous savoir — vous serez notifié des réponses.' ?></p>
    </div>

    <?php if ($message !== ''): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
    <?php if ($formError !== ''): ?><div class="alert err"><?= e($formError) ?></div><?php endif; ?>

    <div class="card">
        <h2>Émettre un avis</h2>
        <form method="post">
            <?= csrfField() ?>
            <label for="category">Type</label>
            <select id="category" name="category">
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $oldCategory === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="subject">Objet</label>
            <input type="text" id="subject" name="subject" maxlength="180" placeholder="En quelques mots…" value="<?= e($oldSubject) ?>">
            <label for="message">Message</label>
            <textarea id="message" name="message" maxlength="5000" placeholder="Décrivez votre question ou votre suggestion…"><?= e($oldMessage) ?></textarea>
            <button class="btn btn-primary" type="submit" name="submit_feedback" value="1">Envoyer</button>
        </form>
    </div>

    <?php if ($isAdmin): ?>
    <div class="filters">
        <a class="chip <?= $statusFilter === 'open' ? 'active' : '' ?>" href="?status=open">À traiter<?= $openCount > 0 ? ' (' . $openCount . ')' : '' ?></a>
        <a class="chip <?= $statusFilter === 'resolved' ? 'active' : '' ?>" href="?status=resolved">Traités</a>
        <a class="chip <?= $statusFilter === 'all' ? 'active' : '' ?>" href="?status=all">Tous</a>
    </div>
    <?php endif; ?>

    <h2 style="margin:6px 2px 12px;font-size:1.05rem;"><?= $isAdmin ? 'Avis reçus' : 'Mes avis' ?></h2>

    <?php if (empty($feedbackList)): ?>
        <div class="empty"><?= $isAdmin ? 'Aucun avis pour ce filtre.' : 'Vous n\'avez pas encore émis d\'avis.' ?></div>
    <?php else: ?>
        <?php foreach ($feedbackList as $fb): ?>
            <?php
                $status = (string) $fb['status'];
                $fid = (int) $fb['id'];
                $replies = $repliesByFeedback[$fid] ?? [];
            ?>
            <div class="fb <?= $status === 'resolved' ? 'resolved' : 'open' ?>">
                <div class="fb-head">
                    <p class="fb-subject"><?= e((string) $fb['subject']) ?></p>
                    <span class="fb-cat"><?= e(fjaCategoryLabel((string) $fb['category'], $categories)) ?></span>
                </div>
                <p class="fb-msg"><?= e((string) $fb['message']) ?></p>
                <div class="fb-meta">
                    <?php if ($isAdmin): ?>
                        Par <strong><?= e((string) ($fb['author_name'] ?? 'Utilisateur')) ?></strong>
                        (<?= e((string) ($fb['author_role'] ?? '')) ?>) · <?= e(fjaFmtDate($fb['created_at'])) ?>
                    <?php else: ?>
                        <?= e(fjaFmtDate($fb['created_at'])) ?>
                    <?php endif; ?>
                    · <span class="fb-status <?= $status ?>"><?= $status === 'resolved' ? 'Traité' : 'En attente' ?></span>
                </div>

                <?php if (trim((string) ($fb['admin_note'] ?? '')) !== ''): ?>
                    <div class="legacy-note"><strong>Réponse :</strong> <?= e((string) $fb['admin_note']) ?></div>
                <?php endif; ?>

                <?php if (!empty($replies)): ?>
                    <div class="thread">
                        <?php foreach ($replies as $rep): ?>
                            <?php $repIsAdmin = ((string) ($rep['author_role'] ?? '') === 'admin'); ?>
                            <div class="bubble <?= $repIsAdmin ? 'admin' : 'user' ?>">
                                <div class="who"><?= e((string) ($rep['author_name'] ?? 'Utilisateur')) ?><?= $repIsAdmin ? ' · Admin' : '' ?></div>
                                <?= e((string) $rep['message']) ?>
                                <div class="when"><?= e(fjaFmtDate($rep['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php // Formulaire de réponse : admin (tout avis) ou auteur (le sien). ?>
                <form method="post" class="reply-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="feedback_id" value="<?= $fid ?>">
                    <textarea name="reply_message" maxlength="5000" placeholder="<?= $isAdmin ? 'Répondre à l\'auteur (il sera notifié)…' : 'Répondre / apporter une précision (l\'équipe sera notifiée)…' ?>"></textarea>
                    <div class="row-actions">
                        <button class="btn btn-primary" type="submit" name="post_reply" value="1">Répondre</button>
                        <?php if ($isAdmin): ?>
                            <?php if ($status !== 'resolved'): ?>
                                <button class="btn btn-soft" type="submit" name="resolve_feedback" value="1">Marquer traité</button>
                            <?php else: ?>
                                <button class="btn btn-soft" type="submit" name="reopen_feedback" value="1">Rouvrir</button>
                            <?php endif; ?>
                            <button class="btn btn-ko" type="submit" name="delete_feedback" value="1" onclick="return confirm('Supprimer cet avis et tout son fil ?');">Supprimer</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
