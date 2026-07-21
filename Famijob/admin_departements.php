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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();

    if (isset($_POST['add_department'])) {
        $name = trim((string) ($_POST['department_name'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        if ($name === '') {
            $error = 'Indiquez un nom de département.';
        } elseif (mb_strlen($name) > 120) {
            $error = 'Nom de département trop long (120 caractères max).';
        } else {
            // Existe déjà (à la casse/aux accents près) ? -> on réactive au lieu de dupliquer.
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
                $message = 'Département « ' . $name .' » activé.';
            } else {
                $db->prepare("INSERT INTO departments (department_name, is_active) VALUES (?, 1)")->execute([$name]);
                $message = 'Département « ' . $name . ' » ajouté.';
            }
        }
    } elseif (isset($_POST['deactivate_department'])) {
        $id = (int) ($_POST['department_id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE departments SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
            $message = 'Département désactivé. Il n\'apparaît plus dans les demandes, le matching et les disponibilités.';
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

    // PRG
    $params = [];
    if ($message !== '') { $params['m'] = $message; }
    if ($error !== '') { $params['e'] = $error; }
    header('Location: admin_departements.php' . (!empty($params) ? '?' . http_build_query($params) : ''));
    exit();
}

if (isset($_GET['m'])) { $message = (string) $_GET['m']; }
if (isset($_GET['e'])) { $error = (string) $_GET['e']; }

$activeDepartments = $db->query(
    "SELECT id, department_name, updated_at FROM departments WHERE is_active = 1 ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$inactiveDepartments = $db->query(
    "SELECT id, department_name, updated_at FROM departments WHERE is_active = 0 ORDER BY department_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= e(famiLocale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des départements - FamiJob</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#eef3f0; --card:#fff; --line:#dde6df; --text:#1b2c25; --muted:#5c6f67; --accent:#2d5a37; --shadow:0 14px 34px rgba(22,49,33,.1); }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px 16px 60px; background:var(--bg); font-family:'Manrope',sans-serif; color:var(--text); }
        .page { max-width:760px; margin:0 auto; }
        .hero { background:linear-gradient(135deg,#264e35,#3f6b4d); color:#fff; border-radius:22px; padding:22px 24px; box-shadow:var(--shadow); margin-bottom:18px; }
        .hero h1 { margin:6px 0 4px; font-size:1.5rem; }
        .hero p { margin:0; opacity:.9; font-size:.92rem; }
        .hero a.back { color:#fff; text-decoration:none; font-weight:700; background:rgba(255,255,255,.16); padding:8px 14px; border-radius:999px; display:inline-block; }
        .card { background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:18px; margin-bottom:18px; }
        .card h2 { margin:0 0 14px; font-size:1.1rem; }
        .add-row { display:flex; gap:10px; flex-wrap:wrap; }
        input[type=text] { flex:1; min-width:220px; border:1px solid #cfdad3; border-radius:10px; padding:11px 12px; font-family:inherit; font-size:.95rem; }
        .btn { border:none; border-radius:10px; padding:11px 16px; font-weight:800; cursor:pointer; font-family:inherit; font-size:.88rem; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-soft { background:#edf5ef; color:var(--accent); }
        .btn-warn { background:#fdf0dd; color:#9a6a15; }
        .btn-ko { background:#fae4e1; color:#a13e35; }
        .alert { padding:11px 14px; border-radius:12px; font-weight:700; margin-bottom:16px; background:#dff3e3; color:#1d6a39; }
        .alert.err { background:#fae4e1; color:#a13e35; }
        .dept { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:11px 4px; border-bottom:1px solid var(--line); }
        .dept:last-child { border-bottom:none; }
        .dept .name { font-weight:700; }
        .dept .acts { display:flex; gap:6px; }
        .muted { color:var(--muted); font-size:.86rem; }
        .empty { color:var(--muted); padding:8px 4px; }
        .inactive .name { color:#93a29a; text-decoration:line-through; }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <a class="back" href="index.php">⬅ Retour FamiJob</a>
        <h1>🏷️ Gestion des départements</h1>
        <p>Ajoutez ou retirez des départements. Tout est enregistré en base et s'applique partout : demandes, matching, disponibilités, vue horaire.</p>
    </div>

    <?php if ($message !== ''): ?><div class="alert"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>Ajouter un département</h2>
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
            <?php foreach ($activeDepartments as $dept): ?>
                <div class="dept">
                    <span class="name"><?= e((string) $dept['department_name']) ?></span>
                    <div class="acts">
                        <form method="post" onsubmit="return confirm('Retirer « <?= e((string) $dept['department_name']) ?> » des listes ? (réactivable ensuite)');">
                            <?= csrfField() ?>
                            <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                            <button class="btn btn-warn" type="submit" name="deactivate_department" value="1">Retirer</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($inactiveDepartments)): ?>
    <div class="card">
        <h2>Départements retirés (<?= count($inactiveDepartments) ?>)</h2>
        <p class="muted" style="margin-top:-6px;">Ils n'apparaissent nulle part. Vous pouvez les réactiver, ou les supprimer définitivement.</p>
        <?php foreach ($inactiveDepartments as $dept): ?>
            <div class="dept inactive">
                <span class="name"><?= e((string) $dept['department_name']) ?></span>
                <div class="acts">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                        <button class="btn btn-soft" type="submit" name="activate_department" value="1">Réactiver</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Supprimer DÉFINITIVEMENT ce département ?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="department_id" value="<?= (int) $dept['id'] ?>">
                        <button class="btn btn-ko" type="submit" name="delete_department" value="1">Supprimer</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
