<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

// Réservé à l'admin
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

ensureModulesTable($db);

$flash = '';
if (!empty($_SESSION['module_flash'])) {
    $flash = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}

$modules  = getModules($db, null, false); // tous les modules racine (actifs + inactifs)
$profiles = moduleProfiles();
$icons    = moduleIconChoices();

/**
 * Affiche les champs communs d'un module (création ou édition).
 */
function renderModuleFields($formId, $module, $profiles, $icons)
{
    $nom         = htmlspecialchars($module['nom'] ?? '');
    $desc        = htmlspecialchars($module['description'] ?? '');
    $isContainer = !empty($module['is_container']);
    $curIcon     = trim((string) ($module['icon'] ?? ''));
    $curRoles    = array_filter(array_map('trim', explode(',', (string) ($module['roles'] ?? ''))));
    ?>
    <label>Nom du module</label>
    <input type="text" name="nom" required maxlength="150" value="<?= $nom ?>">
    <label>Description (quelques mots)</label>
    <textarea name="description" rows="2" maxlength="500"><?= $desc ?></textarea>
    <label class="chk"><input type="checkbox" name="is_container" value="1" <?= $isContainer ? 'checked' : '' ?>> Ce module contient d'autres modules</label>
    <label>Icône</label>
    <input type="hidden" name="icon" id="<?= $formId ?>_icon" value="<?= htmlspecialchars($curIcon) ?>">
    <div class="icon-wrap" id="<?= $formId ?>_iconwrap">
        <?php foreach ($icons as $em): ?>
            <button type="button" class="icon-opt <?= ($em === $curIcon) ? 'sel' : '' ?>" onclick="pickIcon('<?= $formId ?>','<?= $em ?>',this)"><?= $em ?></button>
        <?php endforeach; ?>
    </div>
    <label>Accès — quels profils voient ce module ? <small>(aucun coché = visible par tous)</small></label>
    <div class="roles-wrap">
        <?php foreach ($profiles as $key => $lbl): ?>
            <label class="role-chk"><input type="checkbox" name="roles[]" value="<?= $key ?>" <?= in_array($key, $curRoles, true) ? 'checked' : '' ?>> <?= htmlspecialchars($lbl) ?></label>
        <?php endforeach; ?>
    </div>
    <?php
}

function rolesLabel($module, $profiles)
{
    $roles = array_filter(array_map('trim', explode(',', (string) ($module['roles'] ?? ''))));
    if (empty($roles)) {
        return 'Tous';
    }
    $labels = [];
    foreach ($roles as $r) {
        $labels[] = $profiles[$r] ?? $r;
    }
    return implode(', ', $labels);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - FamiFormation</title>
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .topbar a { color: #2d5a37; text-decoration: none; font-weight: bold; }
        h1 { color: #2d5a37; margin: 0; }
        .flash { background: #dff3e3; border: 1px solid #b6e0c2; color: #1d6a39; padding: 12px 18px; border-radius: 12px; margin-bottom: 18px; font-weight: 700; }
        .tabs { display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 2px solid #d9e3dc; margin-bottom: 20px; }
        .tab-btn { background: none; border: none; padding: 12px 16px; font-weight: 700; color: #5a6b60; cursor: pointer; border-radius: 8px 8px 0 0; }
        .tab-btn.active { background: #2d5a37; color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); padding: 24px; }
        .btn { border: none; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #2d5a37; color: #fff; }
        .btn-danger { background: #c94a42; color: #fff; }
        .btn-light { background: #e9ecef; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 0.92rem; vertical-align: middle; }
        th { background: #e8f5e9; color: #1d6f42; }
        .muted { color: #888; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .pill.on { background: #e8f5e9; color: #2d5a37; }
        .pill.off { background: #f9e1e1; color: #a83232; }
        .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .soon { color: #888; font-style: italic; padding: 20px; text-align: center; }
        /* Modale */
        .modal-backdrop { position: fixed; inset: 0; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-backdrop.open { display: flex; }
        .modal-card { background: #fff; border-radius: 14px; padding: 26px; max-width: 520px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .modal-card h3 { margin-top: 0; color: #2d5a37; }
        .modal-card label { display: block; font-weight: 700; color: #244230; margin: 14px 0 4px; }
        .modal-card input[type=text], .modal-card textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font: inherit; }
        .modal-card .chk { font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .icon-wrap { display: flex; flex-wrap: wrap; gap: 6px; }
        .icon-opt { font-size: 1.3rem; background: #f4f7f6; border: 2px solid transparent; border-radius: 10px; padding: 6px 8px; cursor: pointer; }
        .icon-opt.sel { border-color: #2d5a37; background: #e8f5e9; }
        .roles-wrap { display: flex; flex-wrap: wrap; gap: 12px; }
        .role-chk { font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <a href="index.php">⬅ Retour à l'accueil</a>
        <h1>⚙️ Paramètres</h1>
        <span></span>
    </div>

    <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('modules', this)">Gestion des modules</button>
        <button class="tab-btn" onclick="showTab('histmod', this)">Historique des modules</button>
        <button class="tab-btn" onclick="showTab('histuser', this)">Historique des utilisateurs</button>
        <button class="tab-btn" onclick="showTab('histprofil', this)">Historique des profils</button>
        <button class="tab-btn" onclick="showTab('histagence', this)">Historique des agences</button>
        <button class="tab-btn" onclick="showTab('prefs', this)">Préférences</button>
    </div>

    <!-- ONGLET : Gestion des modules -->
    <div id="tab-modules" class="tab-content active">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Modules</h2>
                <button type="button" class="btn btn-primary" onclick="openModal('createModal')">➕ Créer un module</button>
            </div>
            <table>
                <thead>
                    <tr><th>Icône</th><th>Nom</th><th>Type</th><th>Accès</th><th>Statut</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $m): ?>
                    <tr>
                        <td style="font-size:1.4rem;"><?= moduleIcon($m) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($m['nom']) ?></strong>
                            <div class="muted" style="font-size:0.82rem;"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                        </td>
                        <td><?= !empty($m['is_container']) ? 'Conteneur' : 'Contenu' ?></td>
                        <td><?= htmlspecialchars(rolesLabel($m, $profiles)) ?></td>
                        <td>
                            <?php if ((int) $m['is_active'] === 1): ?>
                                <span class="pill on">Actif</span>
                            <?php else: ?>
                                <span class="pill off">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-light" onclick="openModal('editModal_<?= (int) $m['id'] ?>')">✏️ Modifier</button>
                                <form method="POST" action="module_save.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="return" value="parametres.php">
                                    <button type="submit" class="btn btn-light"><?= (int) $m['is_active'] === 1 ? '⏸ Désactiver' : '▶ Activer' ?></button>
                                </form>
                                <form method="POST" action="module_save.php" onsubmit="return confirm('Supprimer définitivement ce module (et ses sous-modules) ?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="return" value="parametres.php">
                                    <button type="submit" class="btn btn-danger">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($modules)): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;">Aucun module créé pour l'instant.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ONGLETS à venir -->
    <div id="tab-histmod" class="tab-content"><div class="card"><div class="soon">Historique des modules — à venir.</div></div></div>
    <div id="tab-histuser" class="tab-content"><div class="card"><div class="soon">Historique des utilisateurs — à venir.</div></div></div>
    <div id="tab-histprofil" class="tab-content"><div class="card"><div class="soon">Historique des profils — à venir.</div></div></div>
    <div id="tab-histagence" class="tab-content"><div class="card"><div class="soon">Historique des agences — à venir.</div></div></div>
    <div id="tab-prefs" class="tab-content"><div class="card"><div class="soon">Préférences (langue FR/NL, personnalisation) — à venir.</div></div></div>
</div>

<!-- Modale création -->
<div id="createModal" class="modal-backdrop">
    <div class="modal-card">
        <h3>Nouveau module</h3>
        <form method="POST" action="module_save.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="return" value="parametres.php">
            <?php renderModuleFields('create', [], $profiles, $icons); ?>
            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeModal('createModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">Créer le module</button>
            </div>
        </form>
    </div>
</div>

<!-- Modales édition (une par module) -->
<?php foreach ($modules as $m): ?>
<div id="editModal_<?= (int) $m['id'] ?>" class="modal-backdrop">
    <div class="modal-card">
        <h3>Modifier « <?= htmlspecialchars($m['nom']) ?> »</h3>
        <form method="POST" action="module_save.php" onsubmit="return confirm('Enregistrer les modifications de ce module ?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="return" value="parametres.php">
            <?php renderModuleFields('edit' . (int) $m['id'], $m, $profiles, $icons); ?>
            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeModal('editModal_<?= (int) $m['id'] ?>')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
    function showTab(name, btn) {
        document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.remove('active'); });
        document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
    }
    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    function pickIcon(formId, emoji, btn) {
        document.getElementById(formId + '_icon').value = emoji;
        var wrap = document.getElementById(formId + '_iconwrap');
        if (wrap) { wrap.querySelectorAll('.icon-opt').forEach(function (b) { b.classList.remove('sel'); }); }
        btn.classList.add('sel');
    }
</script>
</body>
</html>
