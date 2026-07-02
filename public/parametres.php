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

$profiles = moduleProfiles($db);
$icons    = moduleIconChoices();
// renderModuleFields(), rolesLabel(), moduleIconHtml(), adminPasswordOk() viennent de includes/modules.php

// Profils gérables (table `profils`), pour l'ajout / suppression
ensureProfilesTable($db);
$profilsRows = [];
try {
    $profilsRows = $db->query("SELECT id, cle, libelle, is_core, is_locked FROM profils ORDER BY libelle ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $profilsRows = [];
}

// Tous les modules, organisés en arbre (parents puis enfants indentés)
$allModules = getAllModules($db);
$byParent = [];
foreach ($allModules as $m) {
    $byParent[(int) ($m['parent_id'] ?? 0)][] = $m;
}
function flattenModules(array $byParent, $parentId, $depth, array &$out)
{
    if (empty($byParent[$parentId])) {
        return;
    }
    foreach ($byParent[$parentId] as $mod) {
        $mod['_depth'] = $depth;
        $out[] = $mod;
        flattenModules($byParent, (int) $mod['id'], $depth + 1, $out);
    }
}

/**
 * Rendu en arborescence des modules visibles par un profil.
 * - À la racine : on filtre par accès ($checkVisibility).
 * - Dans les sous-modules : tout est hérité (comme sur le site), donc pas de re-filtre.
 */
function renderProfileModuleTree(array $byParent, $parentId, $profileKey, $depth, $checkVisibility)
{
    if (empty($byParent[$parentId])) {
        return '';
    }
    $html = '';
    foreach ($byParent[$parentId] as $mod) {
        if ((int) ($mod['is_active'] ?? 1) !== 1) {
            continue; // module inactif : non visible par les utilisateurs
        }
        if ($checkVisibility && !userCanSeeModule($mod, $profileKey)) {
            continue;
        }
        $pad = 6 + $depth * 20;
        $icon = function_exists('moduleIcon') ? moduleIcon($mod) : '📄';
        $html .= '<div style="padding:3px 0 3px ' . $pad . 'px;">'
            . ($depth > 0 ? '<span style="color:#9bb3a3;">↳ </span>' : '')
            . '<span>' . $icon . '</span> '
            . '<span style="font-weight:' . ($depth === 0 ? '700' : '600') . '; color:#244230;">' . htmlspecialchars($mod['nom']) . '</span>'
            . '</div>';
        $html .= renderProfileModuleTree($byParent, (int) $mod['id'], $profileKey, $depth + 1, false);
    }
    return $html;
}

$orderedModules = [];
flattenModules($byParent, 0, 0, $orderedModules);

// Données pour les onglets de gestion
$usersList = $db->query("SELECT id, nom, prenom, identifiant, email, role, interim, statut, account_activation_pending, mot_de_passe FROM utilisateurs WHERE role <> 'agence_interim' ORDER BY nom ASC, prenom ASC")->fetchAll(PDO::FETCH_ASSOC);

$roleCounts = [];
foreach ($db->query("SELECT role, COUNT(*) AS c FROM utilisateurs GROUP BY role")->fetchAll(PDO::FETCH_ASSOC) as $rc) {
    $roleCounts[$rc['role']] = (int) $rc['c'];
}

$agencesList = [];
try {
    $agencesList = $db->query("SELECT nom_agence FROM interim_agences ORDER BY nom_agence ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $agencesList = [];
}
$agenceCounts = [];
foreach ($db->query("SELECT interim, COUNT(*) AS c FROM utilisateurs WHERE interim IS NOT NULL AND interim <> '' GROUP BY interim")->fetchAll(PDO::FETCH_ASSOC) as $ac) {
    $agenceCounts[$ac['interim']] = (int) $ac['c'];
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
        <button class="tab-btn" onclick="showTab('histuser', this)">Gestion des utilisateurs</button>
        <button class="tab-btn" onclick="showTab('histprofil', this)">Gestion des profils</button>
        <button class="tab-btn" onclick="showTab('histagence', this)">Gestion des agences</button>
        <button class="tab-btn" onclick="showTab('prefs', this)">Préférences</button>
    </div>

    <!-- ONGLET : Gestion des modules -->
    <div id="tab-modules" class="tab-content active">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Modules</h2>
                <div style="display:flex; gap:8px;">
                    <form method="POST" action="module_save.php" style="display:inline;" onsubmit="return confirm('Traduire en néerlandais tous les modules qui n\'ont pas encore de traduction ?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="translate_all">
                        <input type="hidden" name="return" value="parametres.php">
                        <button type="submit" class="btn btn-light" title="Traduit en NL les modules sans traduction (ex : Aide)">🌐 Traduire en NL</button>
                    </form>
                    <button type="button" class="btn btn-primary" onclick="openModal('createModal')">➕ Créer un module</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Icône</th><th>Nom</th><th>Type</th><th>Organiser</th><th>Accès</th><th>Statut</th><th>Verrou</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orderedModules as $m): $depth = (int) ($m['_depth'] ?? 0); ?>
                    <tr>
                        <td><?= moduleIconHtml($m, '1.6rem') ?></td>
                        <td>
                            <div style="padding-left:<?= $depth * 18 ?>px;">
                                <?= $depth > 0 ? '↳ ' : '' ?><strong><?= htmlspecialchars($m['nom']) ?></strong>
                                <?php if (!empty($m['is_locked'])): ?> <span title="Verrouillé">🔒</span><?php endif; ?>
                                <div class="muted" style="font-size:0.82rem;"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                                <?php if (!empty($m['link'])): ?><div class="muted" style="font-size:0.76rem;">🔗 module de base → <?= htmlspecialchars($m['link']) ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td><?= !empty($m['is_container']) ? 'Conteneur' : 'Contenu' ?></td>
                        <td>
                            <form method="POST" action="module_save.php" style="display:inline-block;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="module_move">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php">
                                <button type="submit" name="dir" value="up" class="btn btn-light" style="padding:3px 8px;" title="Monter">↑</button>
                                <button type="submit" name="dir" value="down" class="btn btn-light" style="padding:3px 8px;" title="Descendre">↓</button>
                            </form>
                            <form method="POST" action="module_save.php" style="display:flex; gap:4px; margin-top:5px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="module_reparent">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php">
                                <select name="new_parent" style="max-width:150px; padding:5px; border:1px solid #ccc; border-radius:6px; font-size:0.82rem;">
                                    <option value="0" <?= empty($m['parent_id']) ? 'selected' : '' ?>>— Racine —</option>
                                    <?php foreach ($orderedModules as $cand): ?>
                                        <?php if ((int) $cand['id'] === (int) $m['id']) { continue; } ?>
                                        <option value="<?= (int) $cand['id'] ?>" <?= ((int) ($m['parent_id'] ?? 0) === (int) $cand['id']) ? 'selected' : '' ?>><?= str_repeat('— ', (int) ($cand['_depth'] ?? 0)) . htmlspecialchars($cand['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-light" style="padding:3px 8px;" title="Déplacer dans ce module">→</button>
                            </form>
                        </td>
                        <td><?= htmlspecialchars(rolesLabel($m, $profiles)) ?></td>
                        <td>
                            <?php if ((int) $m['is_active'] === 1): ?><span class="pill on">Actif</span><?php else: ?><span class="pill off">Inactif</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($m['is_locked'])): ?>
                                <button type="button" class="btn" style="background:#fde8c8; color:#8a5a00;" title="Verrouillé — cliquer pour déverrouiller (mot de passe requis)" onclick="askPassword('toggle_lock', <?= (int) $m['id'] ?>)">🔒 Verrouillé</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-light" title="Déverrouillé — cliquer pour verrouiller (mot de passe requis)" onclick="askPassword('toggle_lock', <?= (int) $m['id'] ?>)">🔓 Déverrouillé</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-light" onclick="openModal('editModal_<?= (int) $m['id'] ?>')">✏️</button>
                                <form method="POST" action="module_save.php" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="return" value="parametres.php">
                                    <button type="submit" class="btn btn-light" title="Activer / Désactiver"><?= (int) $m['is_active'] === 1 ? '⏸' : '▶' ?></button>
                                </form>
                                <?php if (!empty($m['is_locked'])): ?>
                                    <button type="button" class="btn btn-danger" onclick="askPassword('delete', <?= (int) $m['id'] ?>)" title="Supprimer (verrouillé)">🗑</button>
                                <?php else: ?>
                                    <form method="POST" action="module_save.php" style="display:inline;" onsubmit="return confirm('Supprimer définitivement ce module (et ses sous-modules) ?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="return" value="parametres.php">
                                        <button type="submit" class="btn btn-danger" title="Supprimer">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orderedModules)): ?>
                    <tr><td colspan="8" class="muted" style="text-align:center;">Aucun module créé pour l'instant.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ONGLET : Gestion des utilisateurs -->
    <div id="tab-histuser" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Utilisateurs (<?= count($usersList) ?>)</h2>
                <a href="admin.php" class="btn btn-primary">Gérer dans RH</a>
            </div>
            <table>
                <thead><tr><th>Nom</th><th>Identifiant</th><th>Profil</th><th>Agence</th><th>Statut</th><th>Fiche</th></tr></thead>
                <tbody>
                    <?php foreach ($usersList as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars(trim($u['nom'] . ' ' . $u['prenom'])) ?></td>
                        <td class="muted"><?= htmlspecialchars($u['identifiant']) ?></td>
                        <td><?= htmlspecialchars($profiles[$u['role']] ?? $u['role']) ?></td>
                        <td><?= htmlspecialchars($u['interim'] !== null && $u['interim'] !== '' ? $u['interim'] : '—') ?></td>
                        <td>
                            <?php if (($u['statut'] ?? '') === 'inactif'): ?><span class="pill off">Inactif</span>
                            <?php elseif (!empty($u['account_activation_pending']) || empty($u['mot_de_passe'])): ?><span class="pill" style="background:#fff3cd;color:#856404;">En attente</span>
                            <?php else: ?><span class="pill on">Actif</span><?php endif; ?>
                        </td>
                        <td><a href="admin_user.php?id=<?= (int) $u['id'] ?>" title="Voir la fiche">🔎</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ONGLET : Gestion des profils -->
    <div id="tab-histprofil" class="tab-content">
        <div class="card">
            <h2 style="margin-top:0; color:#2d5a37;">Profils</h2>
            <p class="muted">Ajoutez ou supprimez des profils. Un nouveau profil apparaît automatiquement dans la liste d'accès des modules.</p>

            <form method="POST" action="module_save.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_profile">
                <div>
                    <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Nom du profil</label>
                    <input type="text" name="profile_label" required maxlength="100" placeholder="Ex : Responsable rayon" style="padding:9px 10px; border:1px solid #ccc; border-radius:8px; min-width:220px;">
                </div>
                <button type="submit" class="btn btn-primary">➕ Ajouter le profil</button>
            </form>

            <table>
                <thead><tr><th>Profil</th><th>Clé technique</th><th>Utilisateurs</th><th>Verrou</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($profilsRows as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['libelle']) ?><?= !empty($p['is_core']) ? ' <span class="pill on">base</span>' : '' ?></td>
                        <td class="muted"><?= htmlspecialchars($p['cle']) ?></td>
                        <td><?= (int) ($roleCounts[$p['cle']] ?? 0) ?></td>
                        <td>
                            <?php if (!empty($p['is_locked'])): ?>
                                <button type="button" class="btn" style="background:#fde8c8; color:#8a5a00;" title="Verrouillé — cliquer pour déverrouiller (mot de passe requis)" onclick="askPassword('toggle_lock_profile', <?= (int) $p['id'] ?>)">🔒 Verrouillé</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-light" title="Déverrouillé — cliquer pour verrouiller (mot de passe requis)" onclick="askPassword('toggle_lock_profile', <?= (int) $p['id'] ?>)">🔓 Déverrouillé</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['is_locked'])): ?>
                                <button type="button" class="btn btn-danger" onclick="askPassword('delete_profile', <?= (int) $p['id'] ?>)" title="Supprimer (verrouillé)">Supprimer</button>
                            <?php else: ?>
                                <form method="POST" action="module_save.php" onsubmit="return confirm('Supprimer le profil « <?= htmlspecialchars(addslashes($p['libelle'])) ?> » ?');" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_profile">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:6px 12px;">Supprimer</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($profilsRows)): ?><tr><td colspan="5" class="muted">Aucun profil.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="margin-top:20px;">
            <h2 style="margin-top:0; color:#2d5a37;">Accès aux modules par profil</h2>
            <p class="muted">Aperçu (lecture seule) de l'arborescence des modules vue par chaque profil, pour vérifier. Pour modifier un accès, ouvrez le module dans « Gestion des modules ». Admin et Teamcoach voient tous les modules.</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:16px;">
                <?php foreach ($profiles as $key => $lbl): ?>
                    <?php $tree = renderProfileModuleTree($byParent, 0, $key, 0, true); ?>
                    <div style="border:1px solid #e3ece5; border-radius:12px; padding:14px; background:#fafcfb;">
                        <div style="font-weight:800; color:#2d5a37; margin-bottom:8px; border-bottom:1px solid #e3ece5; padding-bottom:6px;"><?= htmlspecialchars($lbl) ?></div>
                        <?= $tree !== '' ? $tree : '<div class="muted">Aucun module.</div>' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ONGLET : Gestion des agences -->
    <div id="tab-histagence" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Agences intérim (<?= count($agencesList) ?>)</h2>
                <a href="admin_agences_interim.php" class="btn btn-primary">Gérer les agences</a>
            </div>
            <table>
                <thead><tr><th>Agence</th><th>Collaborateurs rattachés</th></tr></thead>
                <tbody>
                    <?php foreach ($agencesList as $ag): ?>
                    <tr><td><?= htmlspecialchars($ag) ?></td><td><?= (int) ($agenceCounts[$ag] ?? 0) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($agencesList)): ?><tr><td colspan="2" class="muted">Aucune agence pour l'instant.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-prefs" class="tab-content"><div class="card"><div class="soon">Préférences (langue FR/NL, personnalisation) — à venir.</div></div></div>
</div>

<!-- Modale création -->
<div id="createModal" class="modal-backdrop">
    <div class="modal-card">
        <h3>Nouveau module</h3>
        <form method="POST" action="module_save.php" enctype="multipart/form-data">
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
<?php foreach ($orderedModules as $m): ?>
<div id="editModal_<?= (int) $m['id'] ?>" class="modal-backdrop">
    <div class="modal-card">
        <h3>Modifier « <?= htmlspecialchars($m['nom']) ?> »</h3>
        <form method="POST" action="module_save.php" enctype="multipart/form-data" onsubmit="return confirm('Enregistrer les modifications de ce module ?');">
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

<!-- Modale : confirmation par mot de passe admin (verrou / suppression d'un module verrouillé) -->
<div id="pwdModal" class="modal-backdrop">
    <div class="modal-card">
        <h3 id="pwdTitle">Confirmation</h3>
        <p>Entrez le <strong>mot de passe de verrouillage</strong> pour confirmer.</p>
        <form method="POST" action="module_save.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="pwdAction" value="">
            <input type="hidden" name="id" id="pwdId" value="">
            <input type="hidden" name="return" value="parametres.php">
            <input type="password" name="admin_password" id="pwdInput" placeholder="Mot de passe de verrouillage" required style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeModal('pwdModal')">Annuler</button>
                <button type="submit" class="btn btn-primary">Confirmer</button>
            </div>
        </form>
    </div>
</div>

<script>
    function askPassword(action, id) {
        document.getElementById('pwdAction').value = action;
        document.getElementById('pwdId').value = id;
        var titles = {
            'delete': 'Supprimer ce module verrouillé',
            'toggle_lock': 'Verrouiller / déverrouiller le module',
            'delete_profile': 'Supprimer ce profil verrouillé',
            'toggle_lock_profile': 'Verrouiller / déverrouiller le profil'
        };
        document.getElementById('pwdTitle').textContent = titles[action] || 'Confirmation';
        document.getElementById('pwdInput').value = '';
        openModal('pwdModal');
    }
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
