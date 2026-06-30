<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

$moduleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$module = $moduleId > 0 ? getModuleById($db, $moduleId) : null;

if (!$module || (!$isAdmin && (int) $module['is_active'] !== 1)) {
    header('Location: index.php');
    exit();
}

$flash = '';
if (!empty($_SESSION['module_flash'])) {
    $flash = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}

$isContainer = !empty($module['is_container']);
$children = $isContainer ? getModules($db, $moduleId, !$isAdmin) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($module['nom']) ?> - FamiFormation</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: url('background.jpg') no-repeat center center fixed; background-size: cover; margin: 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        .header { text-align: center; padding: 30px 20px 10px; }
        .logo-main { max-width: 150px; }
        h1 { color: #2d5a37; background: rgba(255,255,255,0.92); padding: 12px 30px; border-radius: 30px; display: inline-block; }
        .desc { color: #2d5a37; background: rgba(255,255,255,0.85); padding: 8px 20px; border-radius: 20px; margin-top: 8px; }
        .back-link { align-self: flex-start; margin: 16px 0 0 16px; color: #2d5a37; text-decoration: none; font-weight: bold; background: rgba(255,255,255,0.9); padding: 10px 18px; border-radius: 20px; }
        .tiles-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 25px; width: 90%; max-width: 1100px; margin: 30px 0; }
        .tile { background: rgba(255,255,255,0.95); border-radius: 20px; padding: 30px; text-align: center; text-decoration: none; color: #333; box-shadow: 0 10px 25px rgba(0,0,0,0.1); transition: all 0.3s ease; position: relative; }
        .tile:hover { transform: translateY(-8px); box-shadow: 0 15px 35px rgba(0,0,0,0.2); }
        .tile-icon { font-size: 3rem; }
        .tile-title { font-size: 1.3rem; font-weight: 700; color: #2d5a37; margin: 10px 0; }
        .tile-desc { font-size: 0.92rem; color: #666; }
        .tile.inactive { opacity: 0.5; }
        .content-card { background: rgba(255,255,255,0.96); border-radius: 18px; padding: 32px; width: 90%; max-width: 900px; margin: 30px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .flash { background: #fff8e1; border: 1px solid #ffe082; color: #6a5400; padding: 12px 18px; border-radius: 12px; width: 90%; max-width: 900px; margin-top: 16px; font-weight: 700; }
        .admin-actions { margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn { border: none; border-radius: 10px; padding: 10px 18px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-create { background: #2d5a37; color: #fff; }
        .btn-danger { background: #c94a42; color: #fff; }
        /* Modale */
        .modal-backdrop { position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-card { background: #fff; border-radius: 14px; padding: 28px; max-width: 460px; width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .modal-card h3 { margin-top: 0; color: #2d5a37; }
        .modal-card label { display:block; font-weight:700; color:#244230; margin: 12px 0 4px; }
        .modal-card input[type=text], .modal-card textarea { width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; font:inherit; }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
        .btn-cancel { background:#e9ecef; color:#333; }
    </style>
</head>
<body>
    <a href="<?= !empty($module['parent_id']) ? 'module.php?id=' . (int) $module['parent_id'] : 'index.php' ?>" class="back-link">⬅ Retour</a>
    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo-main"><br>
        <h1><?= moduleIconHtml($module, '1.6rem') ?> <?= htmlspecialchars($module['nom']) ?></h1>
        <?php if (!empty($module['description'])): ?>
            <div class="desc"><?= htmlspecialchars($module['description']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?><div class="flash"><?= $flash ?></div><?php endif; ?>

    <?php if ($isContainer): ?>
        <div class="tiles-container">
            <?php foreach ($children as $child): ?>
                <a href="module.php?id=<?= (int) $child['id'] ?>" class="tile <?= ((int) $child['is_active'] !== 1) ? 'inactive' : '' ?>">
                    <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                    <div class="tile-title"><?= htmlspecialchars($child['nom']) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars($child['description'] ?? '') ?></div>
                    <?php if ($isAdmin): ?>
                        <form method="POST" action="module_save.php" onsubmit="return confirm('Supprimer ce module ?');" style="margin-top:12px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $child['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="font-size:0.8rem;padding:6px 12px;">🗑 Supprimer</button>
                        </form>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if (empty($children)): ?>
                <div class="content-card" style="text-align:center;">Aucun sous-module pour l'instant.</div>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
            <div class="admin-actions">
                <button type="button" class="btn btn-create" onclick="document.getElementById('createModal').style.display='flex';">➕ Ajouter un sous-module</button>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="content-card">
            <?php if (!empty($module['pdf_path']) || !empty($module['video_path'])): ?>
                <p>Le contenu de ce module s'affichera ici (PDF / vidéo). <em>Affichage du contenu à venir (étape suivante).</em></p>
            <?php else: ?>
                <p style="text-align:center;color:#666;">Ce module n'a pas encore de contenu. <?php if ($isAdmin): ?>L'ajout de contenu (PDF / vidéo) arrive à l'étape suivante.<?php endif; ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin && $isContainer): ?>
    <div id="createModal" class="modal-backdrop">
        <div class="modal-card">
            <h3>Nouveau sous-module</h3>
            <form method="POST" action="module_save.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="parent_id" value="<?= (int) $module['id'] ?>">
                <label>Nom du module</label>
                <input type="text" name="nom" required maxlength="150">
                <label>Description (quelques mots)</label>
                <textarea name="description" rows="2" maxlength="500"></textarea>
                <label style="margin-top:14px;"><input type="checkbox" name="is_container" value="1"> Mon module contient d'autres modules</label>
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="document.getElementById('createModal').style.display='none';">Annuler</button>
                    <button type="submit" class="btn btn-create">Créer</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
