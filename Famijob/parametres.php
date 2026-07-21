<?php
require_once 'config.php';
verifierConnexion($db);

$role = (string) ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: ../index.php');
    exit();
}

ensureDepartmentsTable($db);
ensureStudentDepartmentLinksTable($db);

$message = '';
$error = '';

// Onglet actif du hub Paramètres.
$section = (string) ($_GET['section'] ?? 'departements');
$allowedSections = ['departements'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'departements';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    // -------- Départements --------
    if (isset($_POST['add_department'])) {
        $name = trim((string) ($_POST['department_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        if ($name === '') {
            $error = 'Indiquez un nom de département.';
        } elseif (mb_strlen($name) > 120) {
            $error = 'Nom de département trop long (120 caractères max).';
        } else {
            $target = famijobNormalizeDepartmentName($name);
            $existingId = 0;
            foreach ($db->query("SELECT id, department_name FROM departments")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (famijobNormalizeDepartmentName($row['department_name']) === $target) {
                    $existingId = (int) $row['id'];
                    break;
                }
            }
            if ($existingId > 0) {
                $db->prepare("UPDATE departments SET is_active = 1, updated_at = NOW() WHERE id = ?")->execute([$existingId]);
                $message = 'Département « ' . $name . ' » activé.';
            } else {
                $db->prepare("INSERT INTO departments (department_name, is_active) VALUES (?, 1)")->execute([$name]);
                $message = 'Département « ' . $name . ' » ajouté.';
            }
        }
    } elseif (isset($_POST['deactivate_department'])) {
        $id = (int) ($_POST['department_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE departments SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
            $message = 'Département retiré. Il n\'apparaît plus dans les demandes, le matching et les disponibilités.';
        }
    } elseif (isset($_POST['activate_department'])) {
        $id = (int) ($_POST['department_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE departments SET is_active = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
            $message = 'Département réactivé.';
        }
    } elseif (isset($_POST['delete_department'])) {
        $id = (int) ($_POST['department_id'] ?? 0);
        if ($id > 0) {
            try { $db->prepare("DELETE FROM student_department_links WHERE department_id = ?")->execute([$id]); } catch (Exception $e) {}
            $db->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
            $message = 'Département supprimé définitivement.';
        }
    }

    $params = ['section' => 'departements'];
    if ($message !== '') { $params['m'] = $message; }
    if ($error !== '') { $params['e'] = $error; }
    header('Location: parametres.php?' . http_build_query($params));
    exit();
}

if (isset($_GET['m'])) { $message = (string) $_GET['m']; }
if (isset($_GET['e'])) { $error = (string) $_GET['e']; }

$activeDepartments = $db->query(
    "SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$inactiveDepartments = $db->query(
    "SELECT id, department_name FROM departments WHERE is_active = 0 ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= e(famiLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - FamiJob</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#eef3f0; --card:#fff; --line:#dde6df; --text:#1b2c25; --muted:#5c6f67; --accent:#2d5a37; --shadow:0 14px 34px rgba(22,49,33,.1); }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px 16px 60px; background:var(--bg); font-family:'Manrope',sans-serif; color:var(--text); }
        .page { max-width:900px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; border-radius:22px; padding:22px 24px; box-shadow:var(--shadow); margin-bottom:18px; }
        .hero h1 { margin:6px 0 4px; font-size:1.5rem; }
        .hero p { margin:0; opacity:.9; font-size:.92rem; }
        .hero a.back { color:#fff; text-decoration:none; font-weight:700; background:rgba(255,255,255,.16); padding:8px 14px; border-radius:999px; display:inline-block; }
        .layout { display:grid; grid-template-columns:220px 1fr; gap:18px; align-items:start; }
        .nav { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:10px; position:sticky; top:16px; }
        .nav a { display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--text); font-weight:700; padding:11px 12px; border-radius:12px; font-size:.92rem; }
        .nav a.active { background:#edf5ef; color:var(--accent); }
        .nav a:hover { background:#f3f8f5; }
        .nav .soon { color:#9bb0a3; font-weight:600; font-size:.82rem; padding:11px 12px; }
        .card { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:18px; margin-bottom:18px; }
        .card h2 { margin:0 0 6px; font-size:1.12rem; }
        .card .sub { color:var(--muted); font-size:.9rem; margin:0 0 14px; }
        .add-row { display:flex; gap:10px; flex-wrap:wrap; }
        input[type=text] { flex:1; min-width:220px; border:1px solid #cfdad3; border-radius:10px; padding:11px 12px; font-family:inherit; font-size:.95rem; }
        .btn { border:none; border-radius:10px; padding:11px 16px; font-weight:800; cursor:pointer; font-family:inherit; font-size:.88rem; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-soft { background:#edf5ef; color:var(--accent); }
        .btn-warn { background:#fdf0dd; color:#9a6a15; }
        .btn-ko { background:#fae4e1; color:#a13e35; }
        .alert { padding:11px 14px; border-radius:12px; font-weight:700; margin-bottom:16px; background:#dff3e3; color:#1d6a39; }
        .alert.err { background:#fae4e1; color:#a13e35; }
        .chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
        .chip { display:inline-flex; align-items:center; gap:8px; background:#f3f8f5; border:1px solid #e0ebe3; border-radius:999px; padding:6px 6px 6px 14px; font-weight:700; font-size:.9rem; }
        .chip.off { opacity:.7; }
        .chip.off .cn { color:#93a29a; text-decoration:line-through; }
        .chip form { display:inline; }
        .chip button { border:none; background:transparent; cursor:pointer; font-size:.95rem; padding:4px 6px; border-radius:999px; color:#a13e35; }
        .chip button.act { color:#2d5a37; }
        .chip button:hover { background:#fff; }
        .muted { color:var(--muted); font-size:.86rem; }
        .empty { color:var(--muted); padding:6px 2px; }
        @media (max-width:720px){ .layout{ grid-template-columns:1fr; } .nav{ position:static; display:flex; gap:6px; } }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a class="back" href="index.php">⬅ Retour FamiJob</a>
        <h1>⚙️ Paramètres</h1>
        <p>Configurez l'application sans toucher au code : tout est enregistré en base.</p>
    </div>

    <?php if ($message !== ''): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <div class="layout">
        <nav class="nav">
            <a class="<?= $section === 'departements' ? 'active' : '' ?>" href="?section=departements">🏷️ Départements</a>
            <span class="soon">Plus de réglages à venir…</span>
        </nav>

        <div class="content">
            <?php if ($section === 'departements'): ?>
                <div class="card">
                    <h2>Ajouter un département</h2>
                    <p class="sub">S'applique partout : demandes, matching, disponibilités, vue horaire.</p>
                    <form method="post" class="add-row">
                        <?= csrfField() ?>
                        <input type="text" name="department_name" maxlength="120" placeholder="Nom du département (ex. Abbaye)" required>
                        <button class="btn btn-primary" type="submit" name="add_department" value="1">Ajouter</button>
                    </form>
                </div>

                <div class="card">
                    <h2>Départements actifs (<?= count($activeDepartments) ?>)</h2>
                    <?php if (empty($activeDepartments)): ?>
                        <div class="empty">Aucun département actif.</div>
                    <?php else: ?>
                        <div class="chips">
                            <?php foreach ($activeDepartments as $dept): ?>
                                <span class="chip">
                                    <span class="cn"><?= e((string) $dept['department_name']) ?></span>
                                    <form method="post" onsubmit="return confirm('Retirer « <?= e((string) $dept['department_name']) ?> » ? (réactivable ensuite)');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                                        <button type="submit" name="deactivate_department" value="1" title="Retirer">✕</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($inactiveDepartments)): ?>
                <div class="card">
                    <h2>Départements retirés (<?= count($inactiveDepartments) ?>)</h2>
                    <p class="sub">Ils n'apparaissent nulle part. Réactivez-les, ou supprimez-les définitivement.</p>
                    <div class="chips">
                        <?php foreach ($inactiveDepartments as $dept): ?>
                            <span class="chip off">
                                <span class="cn"><?= e((string) $dept['department_name']) ?></span>
                                <form method="post" title="Réactiver">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                                    <button type="submit" name="activate_department" value="1" class="act" title="Réactiver">↩</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Supprimer DÉFINITIVEMENT ce département ?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                                    <button type="submit" name="delete_department" value="1" title="Supprimer définitivement">🗑</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
