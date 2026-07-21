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

// --- Table ---
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

            // Prévenir tous les admins qu'un nouvel avis est arrivé (boîte à notif).
            try {
                $categoryLabel = $categories[$category] ?? 'Avis';
                $admins = $db->query("SELECT id FROM utilisateurs WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $adminId) {
                    $adminId = (int) $adminId;
                    if ($adminId <= 0 || $adminId === $userId) {
                        continue;
                    }
                    famijobNotify(
                        $db,
                        $adminId,
                        'feedback',
                        'Nouvel avis reçu',
                        $categoryLabel . ' de ' . ($fullName !== '' ? $fullName : 'un utilisateur') . ' : ' . $subject,
                        'avis.php',
                        $userId,
                        $fullName
                    );
                }
            } catch (Exception $e) {}

            $message = 'Merci ! Votre avis a bien été transmis.';
            $oldSubject = $oldMessage = '';
            $oldCategory = 'question';
        }
    } elseif ($isAdmin && isset($_POST['resolve_feedback'])) {
        $fid = (int) ($_POST['feedback_id'] ?? 0);
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        if ($fid > 0) {
            $stmt = $db->prepare(
                "UPDATE interim_feedback
                 SET status = 'resolved', admin_note = ?, handled_by_user_id = ?, handled_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([($note !== '' ? mb_substr($note, 0, 5000) : null), $userId, $fid]);

            // Prévenir l'auteur que son avis a été traité (via la boîte à notif).
            try {
                $row = $db->prepare("SELECT author_id, subject FROM interim_feedback WHERE id = ? LIMIT 1");
                $row->execute([$fid]);
                $fb = $row->fetch(PDO::FETCH_ASSOC);
                if ($fb && (int) $fb['author_id'] > 0) {
                    $notifBody = 'Votre avis « ' . (string) $fb['subject'] . ' » a été traité.'
                        . ($note !== '' ? ' Réponse : ' . $note : '');
                    famijobNotify($db, (int) $fb['author_id'], 'feedback', 'Avis traité', $notifBody, 'avis.php', $userId, famijobActorName($db, $userId));
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
            $db->prepare("DELETE FROM interim_feedback WHERE id = ?")->execute([$fid]);
            $message = 'Avis supprimé.';
        }
    }
}

// --- Liste ---
if ($isAdmin) {
    $statusFilter = (string) ($_GET['status'] ?? 'open');
    if (!in_array($statusFilter, ['open', 'resolved', 'all'], true)) {
        $statusFilter = 'open';
    }
    $sql = "SELECT f.*, h.prenom AS handler_prenom, h.nom AS handler_nom
            FROM interim_feedback f
            LEFT JOIN utilisateurs h ON h.id = f.handled_by_user_id";
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

function fjaCategoryLabel($cat, array $categories)
{
    return $categories[$cat] ?? 'Autre';
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
        .fb.resolved { border-left-color:#9bb0a3; opacity:.92; }
        .fb-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline; }
        .fb-subject { font-weight:800; font-size:1rem; margin:0; }
        .fb-cat { font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; font-weight:800; color:#fff; background:#3f6b4d; padding:3px 9px; border-radius:999px; }
        .fb-msg { color:#2a3b33; font-size:.94rem; line-height:1.5; margin:10px 0; white-space:pre-wrap; }
        .fb-meta { color:#93a29a; font-size:.78rem; }
        .fb-status { font-size:.72rem; font-weight:800; text-transform:uppercase; }
        .fb-status.open { color:var(--accent); }
        .fb-status.resolved { color:#7d8f84; }
        .fb-note { background:#f3f8f5; border-radius:10px; padding:10px 12px; margin-top:10px; font-size:.9rem; }
        .admin-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; align-items:flex-start; }
        .admin-actions textarea { min-height:60px; margin-bottom:8px; }
        .empty { background:#fff; border-radius:16px; box-shadow:var(--shadow); padding:28px; text-align:center; color:var(--muted); }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a class="back" href="index.php">⬅ Retour FamiJob</a>
        <h1>💬 Avis &amp; suggestions</h1>
        <p><?= $isAdmin ? 'Vous voyez tous les avis émis par les utilisateurs.' : 'Une question, une idée, un souci ? Faites-le nous savoir.' ?></p>
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
                $createdLabel = '';
                try { $createdLabel = (new DateTimeImmutable((string) $fb['created_at']))->format('d/m/Y à H:i'); } catch (Exception $e) {}
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
                        (<?= e((string) ($fb['author_role'] ?? '')) ?>) · <?= e($createdLabel) ?>
                    <?php else: ?>
                        <?= e($createdLabel) ?>
                    <?php endif; ?>
                    · <span class="fb-status <?= $status ?>"><?= $status === 'resolved' ? 'Traité' : 'En attente' ?></span>
                </div>

                <?php if (trim((string) ($fb['admin_note'] ?? '')) !== ''): ?>
                    <div class="fb-note"><strong>Réponse :</strong> <?= e((string) $fb['admin_note']) ?></div>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <div class="admin-actions">
                        <?php if ($status !== 'resolved'): ?>
                        <form method="post" style="flex:1; min-width:240px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="feedback_id" value="<?= (int) $fb['id'] ?>">
                            <textarea name="admin_note" placeholder="Réponse / note (optionnel) — l'auteur sera notifié"></textarea>
                            <button class="btn btn-primary" type="submit" name="resolve_feedback" value="1">Marquer traité</button>
                        </form>
                        <?php else: ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="feedback_id" value="<?= (int) $fb['id'] ?>">
                            <button class="btn btn-soft" type="submit" name="reopen_feedback" value="1">Rouvrir</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Supprimer cet avis ?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="feedback_id" value="<?= (int) $fb['id'] ?>">
                            <button class="btn btn-ko" type="submit" name="delete_feedback" value="1">Supprimer</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
