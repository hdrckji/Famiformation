<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/widget.php';
require_once 'includes/theme.php';

// Accessible à tous les utilisateurs connectés. Les non-admins n'ont droit qu'à la
// catégorie « Paramètres utilisateur » (bénigne) ; les sections de gestion sont admin.
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

if (!$isAdmin) {
    $lang = currentLang();
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= t('Préférences', 'Voorkeuren') ?> - FamiFormation</title>
        <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
            .container { max-width: 640px; margin: 0 auto; }
            .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
            .topbar a { color: #2d5a37; text-decoration: none; font-weight: bold; }
            h1 { color: #2d5a37; margin: 0; }
            .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); padding: 24px; }
            .btn { border: none; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
            .btn-primary { background: #2d5a37; color: #fff; }
            .btn-light { background: #e9ecef; color: #333; }
            .muted { color: #7a8a80; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="topbar">
                <h1><?= t('Préférences', 'Voorkeuren') ?></h1>
                <a href="index.php">← <?= t('Retour à l\'accueil', 'Terug naar start') ?></a>
            </div>
            <div class="card">
                <h2 style="margin-top:0; color:#2d5a37;"><?= t('Paramètres utilisateur', 'Gebruikersinstellingen') ?></h2>
                <p class="muted"><?= t('Réglages personnels.', 'Persoonlijke instellingen.') ?></p>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <span style="font-weight:700;"><?= t('Langue', 'Taal') ?> :</span>
                    <a href="?lang=fr" class="btn <?= $lang === 'fr' ? 'btn-primary' : 'btn-light' ?>">🇫🇷 Français</a>
                    <a href="?lang=nl" class="btn <?= $lang === 'nl' ? 'btn-primary' : 'btn-light' ?>">🇳🇱 Nederlands</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

ensureModulesTable($db);

require_once __DIR__ . '/includes/ia_settings.php';
require_once __DIR__ . '/includes/perso_ui.php';
iaSettingsHandlePost($db);
require_once __DIR__ . '/includes/pdf_access.php';
pdfAccessHandlePost($db);
require_once __DIR__ . '/includes/storage_admin.php';
storageHandlePost($db);
require_once __DIR__ . '/includes/bulk.php';
require_once __DIR__ . '/includes/ia_usage.php';
iaUsageHandlePost($db);
require_once __DIR__ . '/includes/contrib_settings.php';
contribHandlePost($db);

// Enregistrement des préférences (ex : souhait d'anniversaire)
// Personnalisation : bascule d'UNE option (bouton + confirmation, ou clic droit sur un thème).
// Toutes les options passent par ce même point → cohérent et sûr (liste blanche de clés).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_perso'])) {
    requireValidCSRF();
    $key = (string) ($_POST['perso_key'] ?? '');
    $allowed = ['perso_enabled', 'anim_enabled', 'themes_enabled', 'welcome_enabled'];
    // Chaque événement a 4 clés : _event (l'événement lui-même) + _on (thème),
    // _anim (effets), _intro (animation de 1ère connexion).
    $evKeys = ['anniversaire', 'bienvenue'];
    if (function_exists('siteThemeCatalog')) {
        $evKeys = array_merge($evKeys, array_keys(siteThemeCatalog()));
    }
    foreach ($evKeys as $tk) {
        $allowed[] = 'theme_' . $tk . '_event';
        $allowed[] = 'theme_' . $tk . '_on';
        $allowed[] = 'theme_' . $tk . '_anim';
        $allowed[] = 'theme_' . $tk . '_intro';
    }
    if (in_array($key, $allowed, true)) {
        $cur = widgetGet($db, $key, '1');
        widgetSet($db, $key, $cur === '1' ? '0' : '1');
    }
    header('Location: parametres.php#prefs');
    exit();
}

// Prix du stockage et de l'egress (saisis par l'admin) → sert au calcul du coût.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_costs'])) {
    requireValidCSRF();
    $ps = str_replace(',', '.', (string) ($_POST['price_storage_gb'] ?? '0'));
    $pe = str_replace(',', '.', (string) ($_POST['price_egress_gb'] ?? '0'));
    widgetSet($db, 'price_storage_gb', (string) max(0, (float) $ps));
    widgetSet($db, 'price_egress_gb', (string) max(0, (float) $pe));
    $_SESSION['module_flash'] = "✅ Prix enregistrés.";
    header('Location: parametres.php#prefs');
    exit();
}

$flash = '';
if (!empty($_SESSION['module_flash'])) {
    $flash = $_SESSION['module_flash'];
    unset($_SESSION['module_flash']);
}
// Progression de la traduction par lots (pour l'enchaînement automatique)
$xlate = $_SESSION['xlate'] ?? null;
unset($_SESSION['xlate']);

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
$moduleIds = [];
foreach ($allModules as $m) {
    $moduleIds[(int) $m['id']] = true;
}
$byParent = [];
foreach ($allModules as $m) {
    $pid = (int) ($m['parent_id'] ?? 0);
    // Parent inexistant (module parent supprimé) : on rattache le module à la racine
    // pour qu'il reste TOUJOURS visible dans la gestion (sinon il devient invisible).
    if ($pid !== 0 && !isset($moduleIds[$pid])) {
        $pid = 0;
    }
    $byParent[$pid][] = $m;
}
// Tri de l'arborescence : « order » (ordre actuel/base) ou « alpha » (A→Z par nom),
// en CONSERVANT la hiérarchie (on ne trie que les frères de chaque parent).
$moduleSort = (($_GET['msort'] ?? '') === 'alpha') ? 'alpha' : 'order';
if ($moduleSort === 'alpha') {
    foreach ($byParent as $pid => &$kids) {
        usort($kids, function ($a, $b) {
            return strcasecmp((string) ($a['nom'] ?? ''), (string) ($b['nom'] ?? ''));
        });
    }
    unset($kids);
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

// Un module a-t-il au moins un DESCENDANT (enfant, petit-enfant...) verrouillé ?
// Sert à alerter en rouge avant une suppression irréversible.
function moduleHasLockedDescendant(array $byParent, $parentId)
{
    if (empty($byParent[$parentId])) {
        return false;
    }
    foreach ($byParent[$parentId] as $child) {
        if (!empty($child['is_locked'])) {
            return true;
        }
        if (moduleHasLockedDescendant($byParent, (int) $child['id'])) {
            return true;
        }
    }
    return false;
}

/**
 * Rendu en arborescence des modules visibles par un profil.
 * - À la racine : on filtre par accès ($checkVisibility).
 * - Dans les sous-modules : tout est hérité (comme sur le site), donc pas de re-filtre.
 */
function renderProfileModuleTree(array $byParent, $parentId, $profileKey, $depth, $checkVisibility, $reorderUnlocked = false)
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

        // Flèches de réorganisation (uniquement à la racine, quand le mode est déverrouillé)
        $reorderBtns = '';
        if ($reorderUnlocked && $depth === 0) {
            $btn = 'border:1px solid #cdd8d0; background:#fff; border-radius:5px; cursor:pointer; padding:0 6px; font-size:0.8rem;';
            $reorderBtns = '<form method="POST" action="module_save.php" style="display:inline-block; margin-right:6px;">'
                . csrfField()
                . '<input type="hidden" name="action" value="module_move">'
                . '<input type="hidden" name="id" value="' . (int) $mod['id'] . '">'
                . '<input type="hidden" name="return" value="parametres.php#histprofil">'
                . '<button type="submit" name="dir" value="up" title="Monter" style="' . $btn . '">↑</button>'
                . '<button type="submit" name="dir" value="down" title="Descendre" style="' . $btn . '">↓</button>'
                . '</form>';
        }

        // Enfants rendus d'abord (pour savoir s'il y en a), repliés par défaut.
        $childrenHtml = renderProfileModuleTree($byParent, (int) $mod['id'], $profileKey, $depth + 1, false, $reorderUnlocked);
        $hasChildren = ($childrenHtml !== '');
        $wrapId = 'pt_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string) $profileKey) . '_' . (int) $mod['id'];
        $toggle = $hasChildren
            ? '<button type="button" onclick="togglePt(\'' . $wrapId . '\', this)" title="Développer / réduire" style="border:1px solid #cdd8d0; background:#eef5ef; color:#2d5a37; border-radius:5px; cursor:pointer; padding:0 6px; margin-right:5px; font-size:0.78rem;">▸</button>'
            : '';

        $html .= '<div style="padding:3px 0 3px ' . $pad . 'px;">'
            . $reorderBtns
            . $toggle
            . ($depth > 0 ? '<span style="color:#9bb3a3;">↳ </span>' : '')
            . '<span>' . $icon . '</span> '
            . '<span style="font-weight:' . ($depth === 0 ? '700' : '600') . '; color:#244230;">' . htmlspecialchars($mod['nom']) . '</span>'
            . '</div>';
        if ($hasChildren) {
            $html .= '<div id="' . $wrapId . '" style="display:none;">' . $childrenHtml . '</div>';
        }
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
        .btn:disabled, .btn[disabled] { opacity: 0.4; cursor: not-allowed; box-shadow: none; }
        select:disabled { opacity: 0.5; cursor: not-allowed; background: #f1f1f1; }
        .tree-toggle { background:#e8f5e9; border:1px solid #bcdcc6; cursor:pointer; font-size:1.05rem; line-height:1; color:#2d5a37; width:28px; height:28px; border-radius:7px; margin-right:8px; display:inline-flex; align-items:center; justify-content:center; vertical-align:middle; transition:background .12s, color .12s; }
        .tree-toggle:hover { background:#2d5a37; color:#fff; }
        .tree-spacer { display:inline-block; width:28px; margin-right:8px; }
        .child-count { display:inline-block; margin-left:8px; font-size:0.72rem; font-weight:700; color:#2d5a37; background:#e8f5e9; padding:2px 9px; border-radius:999px; vertical-align:middle; }
        .type-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; font-size:0.76rem; font-weight:700; white-space:nowrap; }
        /* Paramètres administrateur : séparation NETTE entre chaque réglage
           (avant, tout était collé dans un seul bloc → illisible). */
        .admin-settings > div { border-top:3px solid #e3ece5; margin-top:26px; padding-top:22px; }
        .admin-settings > div:first-of-type { border-top:none; margin-top:10px; padding-top:0; }
        .admin-settings > div > h3:first-child { margin-top:0; }

        .type-container { background:#e8f5e9; color:#2d5a37; }
        /* Sous-types d'Élément : C = affiche du contenu (PDF/vidéo) · S = fonction spéciale dédiée */
        .type-content { background:#e3f0fb; color:#1f5c8c; }
        .type-special { background:#fff3e0; color:#8a5a00; }
        .type-empty   { background:#f1f1f1; color:#8f9a94; }
        .type-badge .tb-letter { display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:4px; background:rgba(0,0,0,.12); font-size:0.7rem; font-weight:800; }
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
        <button class="tab-btn" onclick="showTab('widget', this)">Widget</button>
        <button class="tab-btn" onclick="showTab('contenu', this)">Stockage</button>
        <button class="tab-btn" onclick="showTab('api', this)">API</button>
        <button class="tab-btn" onclick="showTab('prefs', this)">Préférences</button>
    </div>

    <!-- ONGLET : API (coûts IA) -->
    <div id="tab-api" class="tab-content">
        <?php renderApiUsageTab($db); ?>
    </div>

    <!-- ONGLET : Contenu (stockage) -->
    <div id="tab-contenu" class="tab-content">
        <?php renderStorageTab($db); ?>
    </div>

    <!-- ONGLET : Gestion des modules -->
    <div id="tab-modules" class="tab-content active">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <h2 style="margin:0; color:#2d5a37;">Modules</h2>
                <button type="button" class="btn btn-primary" onclick="openModal('createModal')">➕ Créer un module</button>
            </div>
            <div style="display:flex; align-items:center; gap:8px; margin:12px 0 6px; font-size:0.85rem; flex-wrap:wrap;">
                <span class="muted" style="font-weight:700;">Trier :</span>
                <a href="parametres.php?msort=order#modules" class="btn <?= $moduleSort === 'order' ? 'btn-primary' : 'btn-light' ?>" style="padding:5px 12px;">↕ Ordre actuel</a>
                <a href="parametres.php?msort=alpha#modules" class="btn <?= $moduleSort === 'alpha' ? 'btn-primary' : 'btn-light' ?>" style="padding:5px 12px;">🔤 A → Z</a>
                <span class="muted" style="font-size:0.8rem;">(la hiérarchie est conservée : on trie les modules d'un même niveau)</span>
            </div>
            <div style="display:flex; align-items:center; gap:8px; margin:0 0 10px; font-size:0.8rem; flex-wrap:wrap;">
                <span class="muted" style="font-weight:700;">Types :</span>
                <span class="type-badge type-container">📁 Conteneur</span><span class="muted">contient d'autres modules</span>
                <span class="type-badge type-content">📄 Élément <span class="tb-letter">C</span></span><span class="muted">affiche du <strong>contenu</strong> (PDF / vidéo)</span>
                <span class="type-badge type-special">⚙️ Élément <span class="tb-letter">S</span></span><span class="muted">fonction <strong>spéciale</strong> (ex. Formation présentiel, Classement)</span>
            </div>
            <?php bulkBar('module'); ?>
            <table class="bulk-table" data-entity="module">
                <thead>
                    <tr><?php bulkAllTh(); ?><th>Icône</th><th>Nom</th><th>Type</th><th>Organiser</th><th>Accès</th><th>Statut</th><th>Actions</th><th style="text-align:right;">Verrou</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orderedModules as $m): $depth = (int) ($m['_depth'] ?? 0); $lk = !empty($m['is_locked']); $childCount = isset($byParent[(int) $m['id']]) ? count($byParent[(int) $m['id']]) : 0; $hasChildren = $childCount > 0; $hasLockedDesc = moduleHasLockedDescendant($byParent, (int) $m['id']); ?>
                    <tr data-id="<?= (int) $m['id'] ?>" data-parent="<?= (int) ($m['parent_id'] ?? 0) ?>"<?= $depth > 0 ? ' style="display:none;"' : '' ?>>
                        <?php bulkCheck((int) $m['id']); ?>
                        <td><?= moduleIconHtml($m, '1.6rem') ?></td>
                        <td>
                            <div style="padding-left:<?= $depth * 18 ?>px;">
                                <?php if ($hasChildren): ?><button type="button" class="tree-toggle" data-expanded="0" onclick="toggleModuleChildren(<?= (int) $m['id'] ?>, this)" title="Afficher / masquer les sous-modules">▸</button><?php else: ?><span class="tree-spacer"></span><?php endif; ?><?= $depth > 0 ? '↳ ' : '' ?><strong><?= htmlspecialchars($m['nom']) ?></strong><?php if ($hasChildren): ?><span class="child-count"><?= $childCount ?> sous-module<?= $childCount > 1 ? 's' : '' ?></span><?php endif; ?>
                                <?php if (!empty($m['is_locked'])): ?> <span title="Verrouillé">🔒</span><?php endif; ?>
                                <div class="muted" style="font-size:0.82rem;"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                                <?php if (!empty($m['link'])): ?><div class="muted" style="font-size:0.76rem;">🔗 module de base → <?= htmlspecialchars($m['link']) ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($m['is_container']) || $hasChildren): ?>
                                <span class="type-badge type-container">📁 Conteneur</span>
                            <?php else:
                                // Sous-type d'Élément : C = contenu (PDF/vidéo), S = fonction spéciale (page dédiée).
                                $elHasContent = !empty($m['pdf_path']) || !empty($m['video_path']) || !empty($m['contenu_ia']);
                                $elHasLink = trim((string) ($m['link'] ?? '')) !== '';
                            ?>
                                <?php if ($elHasContent): ?>
                                    <span class="type-badge type-content" title="Élément CONTENU — affiche un PDF et/ou une vidéo">📄 Élément <span class="tb-letter">C</span></span>
                                <?php elseif ($elHasLink): ?>
                                    <span class="type-badge type-special" title="Élément SPÉCIAL — fonction dédiée (ex. Formation présentiel, Classement)">⚙️ Élément <span class="tb-letter">S</span></span>
                                <?php else: ?>
                                    <span class="type-badge type-empty" title="Élément vide — en attente de contenu">📄 Élément</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="module_save.php" style="display:flex; gap:4px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="module_reparent">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php">
                                <select name="new_parent" style="max-width:150px; padding:5px; border:1px solid #ccc; border-radius:6px; font-size:0.82rem;" <?= $lk ? 'disabled' : '' ?>>
                                    <option value="0" <?= empty($m['parent_id']) ? 'selected' : '' ?>>— Racine —</option>
                                    <?php foreach ($orderedModules as $cand): ?>
                                        <?php if ((int) $cand['id'] === (int) $m['id']) { continue; } ?>
                                        <option value="<?= (int) $cand['id'] ?>" <?= ((int) ($m['parent_id'] ?? 0) === (int) $cand['id']) ? 'selected' : '' ?>><?= str_repeat('— ', (int) ($cand['_depth'] ?? 0)) . htmlspecialchars($cand['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-light" style="padding:3px 10px;" title="<?= $lk ? 'Module verrouillé — déverrouillez-le pour déplacer' : 'Valider le déplacement' ?>" <?= $lk ? 'disabled' : '' ?>>➜</button>
                            </form>
                        </td>
                        <td><?= htmlspecialchars(rolesLabel($m, $profiles)) ?></td>
                        <td>
                            <?php if ((int) $m['is_active'] === 1): ?><span class="pill on">Actif</span><?php else: ?><span class="pill off">Inactif</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-light" onclick="openModal('editModal_<?= (int) $m['id'] ?>')" title="<?= $lk ? 'Module verrouillé — déverrouillez-le pour modifier' : 'Modifier' ?>" <?= $lk ? 'disabled' : '' ?>>✏️</button>
                                <form method="POST" action="module_save.php" style="display:inline;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="return" value="parametres.php">
                                    <button type="submit" class="btn btn-light" title="<?= $lk ? 'Module verrouillé — déverrouillez-le pour changer le statut' : 'Activer / Désactiver' ?>" <?= $lk ? 'disabled' : '' ?>><?= (int) $m['is_active'] === 1 ? '⏸' : '▶' ?></button>
                                </form>
                                <button type="button" class="btn btn-danger" style="<?= $hasLockedDesc ? 'background:#9b1c1c; box-shadow:0 0 0 2px #ffb3b3;' : '' ?>" title="<?= $lk ? 'Module verrouillé — déverrouillez-le pour supprimer' : ($hasLockedDesc ? 'Contient des sous-modules VERROUILLÉS — suppression irréversible' : 'Supprimer') ?>" <?= $lk ? 'disabled' : '' ?> onclick="askDeleteModule(<?= (int) $m['id'] ?>, <?= htmlspecialchars(json_encode((string) $m['nom'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= $hasLockedDesc ? 'true' : 'false' ?>, <?= $hasChildren ? 'true' : 'false' ?>)"><?= $hasLockedDesc ? '⚠️🗑' : '🗑' ?></button>
                            </div>
                        </td>
                        <td style="text-align:right;">
                            <?php if (!empty($m['is_locked'])): ?>
                                <button type="button" class="btn" style="background:#fde8c8; color:#8a5a00; padding:6px 10px;" title="Verrouillé — cliquer pour déverrouiller (mot de passe requis)" onclick="askPassword('toggle_lock', <?= (int) $m['id'] ?>)">🔒</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-light" style="padding:6px 10px;" title="Déverrouillé — cliquer pour verrouiller (mot de passe requis)" onclick="askPassword('toggle_lock', <?= (int) $m['id'] ?>)">🔓</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orderedModules)): ?>
                    <tr><td colspan="9" class="muted" style="text-align:center;">Aucun module créé pour l'instant.</td></tr>
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
            <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="🔍 Rechercher par nom, identifiant ou agence..." style="width:100%; box-sizing:border-box; margin:14px 0; padding:10px 12px; border:1px solid #cfdad3; border-radius:10px; font-size:0.95rem;">
            <table>
                <thead><tr><th>Nom</th><th>Identifiant</th><th>Profil</th><th>Agence</th><th>Statut</th><th>Fiche</th></tr></thead>
                <tbody id="usersTbody">
                    <?php foreach ($usersList as $u): ?>
                    <tr data-search="<?= htmlspecialchars(strtolower(trim($u['nom'] . ' ' . $u['prenom'] . ' ' . $u['identifiant'] . ' ' . ($u['interim'] ?? '')))) ?>">
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

            <?php bulkBar('profile'); ?>
            <table class="bulk-table" data-entity="profile">
                <thead><tr><?php bulkAllTh(); ?><th>Profil</th><th>Clé technique</th><th>Utilisateurs</th><th>Verrou</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($profilsRows as $p): ?>
                    <tr>
                        <?php bulkCheck((int) $p['id']); ?>
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
                    <?php if (empty($profilsRows)): ?><tr><td colspan="6" class="muted">Aucun profil.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php $reorderUnlocked = !empty($_SESSION['reorder_unlocked']); ?>
        <div class="card" style="margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Accès aux modules par profil</h2>
                <?php if ($reorderUnlocked): ?>
                    <form method="POST" action="module_save.php" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="lock_reorder">
                        <button type="submit" class="btn btn-primary">✅ Terminer la réorganisation</button>
                    </form>
                <?php endif; ?>
            </div>
            <p class="muted">Arborescence des modules vue par chaque profil. <strong>👁</strong> = voir le site comme ce profil. <strong>🖐</strong> = modifier l'ordre (mot de passe). Admin et Teamcoach voient tous les modules.</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">
                <?php foreach ($profiles as $key => $lbl): ?>
                    <?php $tree = renderProfileModuleTree($byParent, 0, $key, 0, true, $reorderUnlocked); ?>
                    <div style="border:1px solid #e3ece5; border-radius:12px; padding:14px; background:#fafcfb;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid #e3ece5; padding-bottom:6px;">
                            <span style="font-weight:800; color:#2d5a37;"><?= htmlspecialchars($lbl) ?></span>
                            <span style="display:flex; gap:5px;">
                                <a href="apercu.php?role=<?= urlencode($key) ?>&back=<?= urlencode('parametres.php#histprofil') ?>" title="Voir ce que ce profil voit en se connectant" style="text-decoration:none; font-size:0.9rem; border:1px solid #cdd8d0; border-radius:6px; padding:1px 7px; color:#2d5a37;">👁</a>
                                <?php if (!$reorderUnlocked): ?>
                                    <button type="button" title="Modifier l'ordre" onclick="askPassword('unlock_reorder', 0)" style="font-size:0.9rem; border:1px solid #cdd8d0; border-radius:6px; padding:1px 7px; background:#fff; cursor:pointer;">🖐</button>
                                <?php endif; ?>
                            </span>
                        </div>
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
            <input type="text" id="agenceSearch" onkeyup="filterAgences()" placeholder="🔍 Rechercher une agence..." style="width:100%; box-sizing:border-box; margin:14px 0; padding:10px 12px; border:1px solid #cfdad3; border-radius:10px; font-size:0.95rem;">
            <table>
                <thead><tr><th>Agence</th><th>Collaborateurs rattachés</th></tr></thead>
                <tbody id="agencesTbody">
                    <?php foreach ($agencesList as $ag): ?>
                    <tr data-search="<?= htmlspecialchars(strtolower((string) $ag)) ?>"><td><?= htmlspecialchars($ag) ?></td><td><?= (int) ($agenceCounts[$ag] ?? 0) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($agencesList)): ?><tr><td colspan="2" class="muted">Aucune agence pour l'instant.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ONGLET : Widget d'accueil -->
    <div id="tab-widget" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; color:#2d5a37;">Widget d'accueil</h2>
                <button type="button" class="btn btn-light" onclick="openModal('widgetAccessModal')">👁 Qui a accès ?</button>
            </div>
            <p class="muted">Le bloc affiché en haut de l'accueil (météo, date, horaires, infos qui défilent). En construction — pour l'instant visible uniquement par l'admin.</p>

            <div style="display:flex; align-items:center; gap:14px; margin-top:10px;">
                <span style="font-weight:700;">État :
                    <?php if (widgetEnabled($db)): ?><span class="pill on">Activé</span><?php else: ?><span class="pill off">Désactivé</span><?php endif; ?>
                </span>
                <form method="POST" action="widget_save.php" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_enabled">
                    <input type="hidden" name="return" value="parametres.php#widget">
                    <button type="submit" class="btn <?= widgetEnabled($db) ? 'btn-danger' : 'btn-primary' ?>"><?= widgetEnabled($db) ? 'Désactiver' : 'Activer' ?></button>
                </form>
            </div>

            <p class="muted" style="margin-top:18px;">Prochaines étapes : la météo, les horaires, et la composition des sous-widgets.</p>
        </div>

        <!-- Phrases qui défilent -->
        <?php $wPhrases = widgetPhrases($db, false); ?>
        <div class="card" style="margin-top:20px;">
            <h2 style="margin-top:0; color:#2d5a37;">Phrases qui défilent</h2>
            <p class="muted">Blagues et infos jardinerie affichées au centre du widget (défilement infini). Elles seront rejointes plus tard par les questions de quiz déjà faites.</p>

            <form method="POST" action="widget_save.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_phrase">
                <input type="hidden" name="return" value="parametres.php#widget">
                <div style="flex:1; min-width:260px;">
                    <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Nouvelle phrase</label>
                    <input type="text" name="texte" required maxlength="500" placeholder="Ex : Le paillage garde l'humidité du sol…" style="width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #ccc; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Type</label>
                    <select name="categorie" style="padding:9px 10px; border:1px solid #ccc; border-radius:8px;">
                        <option value="info">🌱 Info jardinerie</option>
                        <option value="blague">😄 Blague</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">➕ Ajouter</button>
            </form>

            <button type="button" id="phrasesToggle" class="btn btn-light" style="margin-bottom:4px;" onclick="famiTogglePhrases()">▸ Voir les phrases (<?= count($wPhrases) ?>)</button>
            <div id="phrasesList" style="display:none; margin-top:10px;">
            <?php bulkBar('phrase'); ?>
            <table class="bulk-table" data-entity="phrase">
                <thead><tr><?php bulkAllTh(); ?><th>Phrase (modifiable)</th><th>Affichée</th><th>Suppr.</th></tr></thead>
                <tbody>
                    <?php foreach ($wPhrases as $ph): ?>
                    <tr>
                        <?php bulkCheck((int) $ph['id']); ?>
                        <td>
                            <form method="POST" action="widget_save.php" style="display:flex; gap:8px; align-items:center;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="edit_phrase">
                                <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php#widget">
                                <input type="text" name="texte" value="<?= htmlspecialchars($ph['texte']) ?>" maxlength="500" style="flex:1; min-width:220px; padding:6px 8px; border:1px solid #ccc; border-radius:6px;">
                                <select name="categorie" style="padding:6px; border:1px solid #ccc; border-radius:6px;">
                                    <option value="info" <?= $ph['categorie'] !== 'blague' ? 'selected' : '' ?>>Info</option>
                                    <option value="blague" <?= $ph['categorie'] === 'blague' ? 'selected' : '' ?>>Blague</option>
                                </select>
                                <button type="submit" class="btn btn-light" style="padding:5px 9px;" title="Enregistrer">💾</button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="widget_save.php" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_phrase">
                                <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php#widget">
                                <button type="submit" class="btn <?= !empty($ph['actif']) ? 'btn-light' : 'btn-danger' ?>" style="padding:5px 10px;"><?= !empty($ph['actif']) ? '👁 Oui' : '🚫 Non' ?></button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="widget_save.php" style="display:inline;" onsubmit="return confirm('Supprimer cette phrase ?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_phrase">
                                <input type="hidden" name="id" value="<?= (int) $ph['id'] ?>">
                                <input type="hidden" name="return" value="parametres.php#widget">
                                <button type="submit" class="btn btn-danger" style="padding:5px 10px;">🗑</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($wPhrases)): ?><tr><td colspan="4" class="muted">Aucune phrase pour l'instant.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
            <script>
            (function () {
                var N = <?= count($wPhrases) ?>;
                function setPh(open) {
                    var w = document.getElementById('phrasesList'), b = document.getElementById('phrasesToggle');
                    if (!w || !b) { return; }
                    w.style.display = open ? 'block' : 'none';
                    b.textContent = (open ? '▾ Masquer' : '▸ Voir') + ' les phrases (' + N + ')';
                    try { sessionStorage.setItem('famiPhOpen', open ? '1' : '0'); } catch (e) {}
                }
                window.famiTogglePhrases = function () {
                    var w = document.getElementById('phrasesList');
                    setPh(w && w.style.display === 'none');
                };
                // Reste dépliée après un rechargement (ajout/modif d'une phrase).
                try { if (sessionStorage.getItem('famiPhOpen') === '1') { setPh(true); } } catch (e) {}
            })();
            </script>
        </div>

        <!-- Révision quiz : synchronisation en direct -->
        <?php $qStats = widgetQuizStats($db, $_SESSION['user_id'] ?? null); ?>
        <div class="card" style="margin-top:20px;">
            <h2 style="margin-top:0; color:#2d5a37;">Révision des quiz</h2>
            <p class="muted">Le widget affiche des questions du module Quiz (avec la bonne réponse), uniquement parmi les quiz que la personne a déjà réalisés. Les questions sont lues <strong>en direct</strong> : ajouter ou supprimer une question dans le module Quiz met le widget à jour automatiquement.</p>

            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:6px;">
                <?php if ($qStats['ok']): ?>
                    <span class="pill on">🔄 Synchronisé en direct</span>
                <?php else: ?>
                    <span class="pill off">⚠️ Module Quiz introuvable</span>
                <?php endif; ?>
                <span style="font-weight:700; color:#244230;"><?= (int) $qStats['total'] ?> question<?= $qStats['total'] > 1 ? 's' : '' ?></span>
                <span class="muted">dans <?= (int) $qStats['themes'] ?> quiz</span>
            </div>

            <p class="muted" style="margin-top:10px;">
                Aperçu pour <strong>votre compte</strong> : <strong><?= (int) $qStats['user'] ?></strong> question<?= $qStats['user'] > 1 ? 's' : '' ?> seraient affichée<?= $qStats['user'] > 1 ? 's' : '' ?> dans votre widget
                <?php if ($qStats['user'] === 0): ?>
                    <br><span style="color:#b26a00;">→ 0 pour l'instant : vous n'avez pas encore passé de quiz (ou aucune question n'existe pour ces quiz). Réalisez un quiz pour voir ses questions apparaître.</span>
                <?php endif; ?>
            </p>
            <a href="admin_questions.php" class="btn btn-light" style="margin-top:4px;">✏️ Gérer les questions de quiz</a>
        </div>

        <!-- Météo des sites (lecture seule) -->
        <?php $wSites = widgetSites($db); ?>
        <div class="card" style="margin-top:20px;">
            <h2 style="margin-top:0; color:#2d5a37;">Météo des sites</h2>
            <p class="muted">Aperçu de la météo par lieu de travail. La liste des sites se gère dans l'onglet <strong>Préférences</strong> → Paramètres administrateur (pas ici).</p>
            <table>
                <thead><tr><th>Site</th><th>Ville</th><th>Météo actuelle</th></tr></thead>
                <tbody>
                    <?php foreach ($wSites as $s): ?>
                        <?php $w = widgetWeather($db, $s); ?>
                        <tr>
                            <td style="font-weight:700; color:#244230;"><?= htmlspecialchars($s['nom']) ?></td>
                            <td><?= htmlspecialchars((string) $s['ville']) ?></td>
                            <td>
                                <?php if ($w): ?>
                                    <span style="font-weight:700;"><?= $w['emoji'] ?> <?= (int) $w['temp'] ?>°C</span>
                                <?php elseif ($s['latitude'] === null): ?>
                                    <span class="muted">Ville non géocodée</span>
                                <?php else: ?>
                                    <span class="muted">Indisponible</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($wSites)): ?><tr><td colspan="3" class="muted">Aucun site. Ajoutez-en dans l'onglet Préférences.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modale : accès au widget -->
    <div id="widgetAccessModal" class="modal-backdrop">
        <div class="modal-card">
            <h3>Qui a accès au widget ?</h3>
            <p class="muted">Profils qui verront le widget sur l'accueil. (Rien de coché = tout le monde.)</p>
            <form method="POST" action="widget_save.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_access">
                <input type="hidden" name="return" value="parametres.php#widget">
                <?php $wroles = widgetRoles($db); ?>
                <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:6px;">
                    <?php foreach ($profiles as $key => $lbl): ?>
                        <label style="display:flex; align-items:center; gap:6px; font-weight:600;">
                            <input type="checkbox" name="roles[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $wroles, true) ? 'checked' : '' ?>> <?= htmlspecialchars($lbl) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" onclick="closeModal('widgetAccessModal')">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <div id="tab-prefs" class="tab-content">
        <!-- Catégorie 1 : Paramètres utilisateur (bénins, pour tout le monde) -->
        <div class="card">
            <h2 style="margin-top:0; color:#2d5a37;">Paramètres utilisateur</h2>
            <p class="muted">Réglages personnels, accessibles à tout le monde.</p>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span style="font-weight:700;">Langue :</span>
                <a href="?lang=fr#prefs" class="btn <?= currentLang() === 'fr' ? 'btn-primary' : 'btn-light' ?>">🇫🇷 Français</a>
                <a href="?lang=nl#prefs" class="btn <?= currentLang() === 'nl' ? 'btn-primary' : 'btn-light' ?>">🇳🇱 Nederlands</a>
            </div>
        </div>

        <!-- Catégorie 2 : Paramètres administrateur -->
        <div class="card admin-settings" style="margin-top:20px;">
            <h2 style="margin-top:0; color:#2d5a37;">Paramètres administrateur</h2>
            <p class="muted">Réservé aux administrateurs.</p>

            <?php
                $onVolume = (defined('FAMI_STORAGE_BASE') && FAMI_STORAGE_BASE !== (__DIR__ . '/uploads'));
                require_once __DIR__ . '/includes/storage_stats.php';
                $stUse   = famiStorageUsage();
                storageRecordSample($db, $stUse['total']); // point d'historique (facturation au pro rata)
                $stMonth = storageMonthUsage($db);
                $egBytes = egressMonth($db);
                $priceSt = (float) widgetGet($db, 'price_storage_gb', '0');
                $priceEg = (float) widgetGet($db, 'price_egress_gb', '0');

                $goNow = famiBytesToGo($stUse['total']);   // volume à l'instant T
                $goEg  = famiBytesToGo($egBytes);

                // Stockage : on facture des « Go-mois » = volume MOYEN × durée écoulée.
                $costStSoFar = $stMonth['gb_month'] * $priceSt;
                // Projection fin de mois si le volume actuel est conservé jusqu'au bout.
                $costStProj  = $costStSoFar + ($goNow * $priceSt * max(0, 1 - $stMonth['elapsed']));

                $costEg      = $goEg * $priceEg;
                $costTot     = $costStSoFar + $costEg;   // accumulé à ce jour
                $costTotProj = $costStProj + $costEg;    // projection fin de mois
                $catLabels = ['video' => '🎬 Vidéos', 'video_raw' => '🎬 Vidéos (sources en attente)', 'pdf' => '📄 PDF', 'icons' => '🖼️ Icônes'];
            ?>
            <div style="border:1px solid <?= $onVolume ? '#cfe6d5' : '#f0d9a8' ?>; background:<?= $onVolume ? '#f2f9f4' : '#fdf6e6' ?>; border-radius:10px; padding:10px 14px; margin-bottom:6px; font-size:.9rem;">
                🗄️ <strong>Stockage des fichiers :</strong>
                <?php if ($onVolume): ?>
                    <span style="color:#256b39; font-weight:700;">Volume persistant ✓</span>
                    <span class="muted">(<?= htmlspecialchars(FAMI_STORAGE_BASE) ?>)</span> — les PDF/vidéos survivent aux redéploiements.
                <?php else: ?>
                    <span style="color:#8a6d1a; font-weight:700;">Local — non persistant ⚠</span>
                    <span class="muted">Aucun volume détecté. Attache un volume Railway au service pour ne pas perdre les fichiers à chaque redéploiement.</span>
                <?php endif; ?>
            </div>

            <!-- 💰 Coût d'hébergement des contenus = stockage + trafic (egress) -->
            <div style="border:1px solid #e2e6ea; background:#fbfcfc; border-radius:10px; padding:14px 16px; margin-bottom:16px;">
                <h3 style="margin:0 0 4px; color:#244230; font-size:1.05rem;">💰 Coût d'hébergement des contenus</h3>
                <p class="muted" style="margin:0 0 12px; font-size:.85rem;">Renseigne les prix de ton hébergeur, le site calcule le coût réel. <em>(Chez OVH l'egress est gratuit → mets 0.)</em></p>

                <form method="POST" action="parametres.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_costs" value="1">
                    <div>
                        <label style="display:block; font-weight:700; color:#244230; font-size:.82rem;">Prix stockage ($/Go/mois)</label>
                        <input type="text" name="price_storage_gb" value="<?= htmlspecialchars((string) $priceSt) ?>" placeholder="0.01" style="width:130px; padding:8px 10px; border:1px solid #ccc; border-radius:8px;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; color:#244230; font-size:.82rem;">Prix egress ($/Go envoyé)</label>
                        <input type="text" name="price_egress_gb" value="<?= htmlspecialchars((string) $priceEg) ?>" placeholder="0.05" style="width:130px; padding:8px 10px; border:1px solid #ccc; border-radius:8px;">
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>

                <table style="margin:0;">
                    <tbody>
                        <tr>
                            <td style="font-weight:700; color:#244230;">📦 Stockage actuel</td>
                            <td><?= famiFormatSize($stUse['total']) ?> <span class="muted">(<?= (int) $stUse['files'] ?> fichier<?= $stUse['files'] > 1 ? 's' : '' ?>)</span></td>
                            <td></td>
                        </tr>
                        <?php foreach ($stUse['by'] as $cat => $b): ?>
                        <tr>
                            <td class="muted" style="padding-left:22px; font-size:.85rem;">↳ <?= htmlspecialchars($catLabels[$cat] ?? $cat) ?></td>
                            <td class="muted" style="font-size:.85rem;"><?= famiFormatSize($b) ?></td>
                            <td></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td style="font-weight:700; color:#244230;">⏳ Stockage facturé <span class="muted" style="font-weight:400;">(pro rata : volume moyen × durée)</span></td>
                            <td><?= famiFormatSize($stMonth['avg_bytes']) ?> <span class="muted">en moyenne · <?= number_format($stMonth['gb_month'], 3, ',', ' ') ?> Go-mois</span></td>
                            <td style="text-align:right; font-weight:700;"><?= number_format($costStSoFar, 3, ',', ' ') ?> $</td>
                        </tr>
                        <tr>
                            <td style="font-weight:700; color:#244230;">📡 Trafic envoyé (ce mois-ci)</td>
                            <td><?= famiFormatSize($egBytes) ?></td>
                            <td style="text-align:right; font-weight:700;"><?= number_format($costEg, 3, ',', ' ') ?> $</td>
                        </tr>
                        <tr style="border-top:2px solid #dde3e0;">
                            <td style="font-weight:800; color:#2d5a37;">TOTAL accumulé à ce jour</td>
                            <td class="muted" style="font-size:.85rem;"><?= number_format($stMonth['elapsed'] * 100, 0) ?> % du mois écoulé</td>
                            <td style="text-align:right; font-weight:800; color:#2d5a37; font-size:1.05rem;"><?= number_format($costTot, 2, ',', ' ') ?> $</td>
                        </tr>
                        <tr>
                            <td class="muted">🔮 Projection fin de mois <span style="font-size:.82rem;">(si tu gardes le stockage actuel)</span></td>
                            <td></td>
                            <td style="text-align:right; font-weight:700; color:#54606b;"><?= number_format($costTotProj, 2, ',', ' ') ?> $</td>
                        </tr>
                    </tbody>
                </table>
                <p class="muted" style="margin:10px 0 0; font-size:.8rem;">
                    ℹ️ <strong>Stockage = facturé au pro rata</strong> (Go × durée) : si tu stockes 10 Go pendant 20 jours puis que tu supprimes tout, ces 20 jours restent facturés. Le site historise donc le volume et l'intègre dans le temps — supprimer des fichiers <strong>arrête</strong> le compteur, mais n'efface pas ce qui est déjà consommé.<br>
                    ℹ️ <strong>Trafic</strong> = uniquement ce qui <strong>sort réellement</strong> du serveur : un fichier relu depuis le cache du navigateur n'est <strong>ni envoyé ni facturé</strong>. Tout repart à zéro chaque mois (comme la facture). Le suivi a démarré à la mise en place de cette fonction.
                </p>
            </div>

            <?php iaSettingsCard($db); ?>
            <?php pdfAccessCard($db); ?>
            <?php contribSettingsCard($db); ?>

            <!-- 🎨 PERSONNALISATION : options « fun » regroupées, chaque bascule via un bouton
                 (confirmation à la désactivation) ou, pour les thèmes, un clic droit. -->
            <?php
                $persoOn   = (widgetGet($db, 'perso_enabled', '1') === '1');
                $animOn    = (widgetGet($db, 'anim_enabled', '1') === '1');
                $themesOn  = (widgetGet($db, 'themes_enabled', '1') === '1');
                $welcomeOn = (widgetGet($db, 'welcome_enabled', '1') === '1');
                $activeTheme = activeSiteTheme($db);
                $bdT = birthdayTheme();

                // Bouton de bascule ON/OFF (formulaire autonome). Confirmation à la désactivation.
                $btnToggle = function ($key, $isOn, $confirmOnDisable = '') {
                    persoSwitch($key, $isOn, $confirmOnDisable);
                };

                // Liste des thèmes (anniversaire + catalogue) avec leur état on/anim.
                $themeChips = ['anniversaire' => ['nom' => '🎂 Anniversaire', 'accent' => $bdT['accent']]];
                foreach (siteThemeCatalog() as $tk => $tv) {
                    $themeChips[$tk] = ['nom' => (is_array($tv['nom']) ? $tv['nom'][0] : $tv['nom']), 'accent' => $tv['accent']];
                }
            ?>
            <div style="border-top:1px solid #eee; padding-top:14px; margin-top:6px;">
                <h3 style="margin:0 0 4px; color:#244230; font-size:1.35rem;">🎨 Personnalisation</h3>
                <p class="muted" style="margin:0 0 14px;">Options qui rendent le site attractif. Chaque bascule se fait par un <strong>bouton</strong> (confirmation à la désactivation). Une catégorie coupée est grisée.</p>

                <!-- INTERRUPTEUR MAÎTRE -->
                <div style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; background:#f3f8f4; border:1px solid #d9e8dd; border-radius:12px; padding:14px 16px;">
                    <div>
                        <div style="font-weight:800; color:#244230; font-size:1.05rem;">Personnalisation du site</div>
                        <div class="muted" style="font-size:.86rem;">Coupez tout d'un clic pour un site <strong>sobre / sérieux</strong>. Les réglages restent mémorisés.</div>
                    </div>
                    <?php $btnToggle('perso_enabled', $persoOn, 'Désactiver TOUTE la personnalisation ? Le site deviendra sobre (ni animations, ni thèmes).'); ?>
                </div>

                <!-- Sous-catégories (grisées si le maître est coupé) -->
                <div style="<?= $persoOn ? '' : 'opacity:.45; pointer-events:none;' ?> transition:opacity .2s; margin-top:6px;">


                    <!-- 🎨 THÈMES -->
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; border-top:1px solid #eee; padding-top:16px; margin-top:16px;">
                        <h3 style="margin:0; color:#2d5a37; font-size:1.2rem;">🎨 Thèmes</h3>
                        <?php $btnToggle('themes_enabled', $themesOn, 'Désactiver toute la catégorie Thèmes ?'); ?>
                    </div>
                    <div style="<?= $themesOn ? '' : 'opacity:.5; pointer-events:none;' ?> border-left:3px solid #e3ece5; padding-left:14px; margin:12px 0 6px;">
                        <p class="muted" style="margin:0 0 4px;">Le visuel du site change automatiquement selon la date.
                            <?php if ($activeTheme): ?><br><strong style="color:<?= htmlspecialchars($activeTheme['accent']) ?>;">Thème actif aujourd'hui : <?= htmlspecialchars(is_array($activeTheme['nom']) ? $activeTheme['nom'][0] : $activeTheme['nom']) ?></strong><?php else: ?><br>Aucun thème actif aujourd'hui.<?php endif; ?>
                        </p>
                        <?php renderEventThemeCards($db); ?>
                    </div>

                    <!-- À VENIR -->
                    <div style="border-top:1px solid #eee; padding-top:14px; margin-top:10px;">
                        <h3 style="margin:2px 0 4px; color:#9aa6a0; font-size:1.05rem;">🏅 Badges <span style="font-size:.72rem; font-weight:700; background:#eef1ef; color:#8a968f; border-radius:999px; padding:2px 8px;">à venir</span></h3>
                        <h3 style="margin:6px 0 2px; color:#9aa6a0; font-size:1.05rem;">🥚 Easter eggs <span style="font-size:.72rem; font-weight:700; background:#eef1ef; color:#8a968f; border-radius:999px; padding:2px 8px;">à venir</span></h3>
                    </div>
                </div>

                <!-- Menu contextuel (clic droit) des thèmes + formulaire de bascule partagé -->
                <div id="themeCtx" style="position:fixed; z-index:100000; display:none; background:#fff; border:1px solid #d0d7d2; border-radius:10px; box-shadow:0 10px 34px rgba(0,0,0,.2); padding:6px; min-width:220px;">
                    <button type="button" data-act="preview" style="display:block; width:100%; text-align:left; border:none; background:none; padding:9px 12px; border-radius:7px; cursor:pointer; font-weight:600; color:#244230;">👁 Aperçu du thème</button>
                    <button type="button" data-act="toggleOn" style="display:block; width:100%; text-align:left; border:none; background:none; padding:9px 12px; border-radius:7px; cursor:pointer; font-weight:600; color:#244230;"></button>
                    <button type="button" data-act="toggleAnim" style="display:block; width:100%; text-align:left; border:none; background:none; padding:9px 12px; border-radius:7px; cursor:pointer; font-weight:600; color:#244230;"></button>
                </div>
                <form id="persoToggleForm" method="POST" action="parametres.php" style="display:none;">
                    <?= csrfField() ?>
                    <input type="hidden" name="toggle_perso" value="1">
                    <input type="hidden" name="perso_key" id="persoToggleKey" value="">
                </form>
                <script>
                (function () {
                    var menu = document.getElementById('themeCtx');
                    var form = document.getElementById('persoToggleForm');
                    var keyInput = document.getElementById('persoToggleKey');
                    if (!menu || !form) { return; }
                    var cur = null;
                    function show(chip, x, y) {
                        cur = chip;
                        var on = chip.getAttribute('data-on') === '1', anim = chip.getAttribute('data-anim') === '1';
                        menu.querySelector('[data-act=toggleOn]').textContent = on ? '⬜ Désactiver le thème' : '✅ Activer le thème';
                        menu.querySelector('[data-act=toggleAnim]').textContent = anim ? '🚫 Couper l’animation' : '✨ Activer l’animation';
                        menu.style.left = Math.min(x, window.innerWidth - 236) + 'px';
                        menu.style.top = Math.min(y, window.innerHeight - 150) + 'px';
                        menu.style.display = 'block';
                    }
                    function hide() { menu.style.display = 'none'; cur = null; }
                    document.querySelectorAll('.theme-chip').forEach(function (chip) {
                        chip.addEventListener('contextmenu', function (e) { e.preventDefault(); show(chip, e.clientX, e.clientY); });
                        var t;
                        chip.addEventListener('touchstart', function () { t = setTimeout(function () { chip._sup = true; var r = chip.getBoundingClientRect(); show(chip, r.left, r.bottom); }, 500); }, { passive: true });
                        chip.addEventListener('touchend', function () { clearTimeout(t); });
                        chip.addEventListener('touchmove', function () { clearTimeout(t); });
                        chip.addEventListener('click', function (e) { if (chip._sup) { e.preventDefault(); chip._sup = false; } });
                    });
                    menu.querySelector('[data-act=preview]').addEventListener('click', function () { if (cur) { window.location = cur.getAttribute('href'); } });
                    menu.querySelector('[data-act=toggleOn]').addEventListener('click', function () { if (cur) { keyInput.value = 'theme_' + cur.getAttribute('data-key') + '_on'; form.submit(); } });
                    menu.querySelector('[data-act=toggleAnim]').addEventListener('click', function () { if (cur) { keyInput.value = 'theme_' + cur.getAttribute('data-key') + '_anim'; form.submit(); } });
                    document.addEventListener('click', function (e) { if (menu.style.display === 'block' && !menu.contains(e.target)) { hide(); } });
                    window.addEventListener('scroll', hide, true);
                })();
                </script>
            </div>

            <!-- Sites Famiflora (source unique : fiche collaborateur + widget) -->
            <div style="border-top:1px solid #eee; padding-top:14px; margin-top:16px;">
                <h3 style="margin:0 0 6px; color:#244230;">📍 Sites Famiflora</h3>
                <p class="muted">Cette liste sert à la fois au menu « Lieu de travail » de la fiche collaborateur et à la météo du widget. Ajoute un site avec sa ville (coordonnées météo trouvées automatiquement, Open-Meteo).</p>
                <?php $wSitesAdmin = widgetSites($db); ?>
                <form method="POST" action="widget_save.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:8px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_site">
                    <input type="hidden" name="return" value="parametres.php#prefs">
                    <div style="flex:1; min-width:200px;">
                        <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Nom du site</label>
                        <input type="text" name="nom" maxlength="100" placeholder="Ex : Famiflora Mouscron" style="width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #ccc; border-radius:8px;">
                    </div>
                    <div style="min-width:180px;">
                        <label style="display:block; font-weight:700; color:#244230; font-size:0.85rem;">Ville</label>
                        <input type="text" name="ville" required maxlength="100" placeholder="Ex : Mouscron" style="width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #ccc; border-radius:8px;">
                    </div>
                    <button type="submit" class="btn btn-primary">➕ Ajouter</button>
                </form>
                <table>
                    <thead><tr><th>Site</th><th>Ville</th><th>Météo</th><th>Suppr.</th></tr></thead>
                    <tbody>
                        <?php foreach ($wSitesAdmin as $s): ?>
                        <tr>
                            <td style="font-weight:700; color:#244230;"><?= htmlspecialchars($s['nom']) ?></td>
                            <td><?= htmlspecialchars((string) $s['ville']) ?></td>
                            <td>
                                <?php if ($s['latitude'] !== null): ?>
                                    <span class="pill on">Coordonnées OK</span>
                                <?php else: ?>
                                    <form method="POST" action="widget_save.php" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="regeocode_site">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <input type="hidden" name="return" value="parametres.php#prefs">
                                        <button type="submit" class="btn btn-light" style="padding:5px 10px;" title="Réessayer de trouver les coordonnées">⚠️ Retrouver</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" action="widget_save.php" style="display:inline;" onsubmit="return confirm('Supprimer ce site ? Les utilisateurs affiliés perdront leur lieu de travail.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_site">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <input type="hidden" name="return" value="parametres.php#prefs">
                                    <button type="submit" class="btn btn-danger" style="padding:5px 10px;">🗑</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($wSitesAdmin)): ?><tr><td colspan="4" class="muted">Aucun site pour l'instant.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Traduction néerlandaise (rattrapage de l'existant) -->
            <?php
            $phrasesToTranslate = (int) $db->query("SELECT COUNT(*) FROM widget_phrases WHERE texte_nl IS NULL OR texte_nl = ''")->fetchColumn();
            $quizToTranslate = 0;
            try {
                $quizToTranslate = (int) $db->query("SELECT COUNT(*) FROM quiz_questions WHERE question_text_nl IS NULL OR question_text_nl = ''")->fetchColumn();
            } catch (Exception $e) {
            }
            ?>
            <div style="border-top:1px solid #eee; padding-top:14px; margin-top:16px;">
                <h3 style="margin:0 0 6px; color:#244230;">🌍 Traduction néerlandaise</h3>
                <?php $mmEmail = getenv('MYMEMORY_EMAIL'); $mmHasEmail = ($mmEmail !== false && trim((string) $mmEmail) !== ''); ?>
                <p class="muted">Les nouvelles phrases et questions sont traduites automatiquement à l'enregistrement. Ces boutons traduisent l'<strong>existant</strong> en <strong>enchaînant les lots tout seuls</strong> jusqu'au bout (ou jusqu'à la limite quotidienne gratuite). Un seul clic suffit.</p>
                <p class="muted" style="margin:0 0 12px;">Mode : <strong style="color:#2d5a37;"><?= $mmHasEmail ? 'quota étendu — email détecté (~50 000 mots/jour)' : 'quota standard — sans email (~5 000 mots/jour)' ?></strong>.<?= $mmHasEmail ? '' : ' Ajoute la variable MYMEMORY_EMAIL sur Railway pour ×10 (toujours gratuit).' ?></p>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <form id="xlatePhrasesForm" method="POST" action="widget_save.php" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="translate_phrases_batch">
                        <input type="hidden" name="return" value="parametres.php#prefs">
                        <button type="submit" class="btn btn-primary">🌍 Traduire les phrases (reste : <?= $phrasesToTranslate ?>)</button>
                    </form>
                    <form id="xlateQuizForm" method="POST" action="widget_save.php" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="translate_quiz_batch">
                        <input type="hidden" name="return" value="parametres.php#prefs">
                        <button type="submit" class="btn btn-primary">🌍 Traduire les quiz (reste : <?= $quizToTranslate ?>)</button>
                    </form>
                    <span id="xlateStatus" style="font-weight:700; color:#2d5a37; display:none;">⏳ Traduction en cours…</span>
                </div>
                <?php if ($xlate && (int) $xlate['rest'] > 0 && (int) $xlate['done'] > 0): ?>
                <script>
                (function () {
                    var s = document.getElementById('xlateStatus');
                    if (s) { s.style.display = 'inline'; s.textContent = '⏳ Traduction en cours… (restant : <?= (int) $xlate['rest'] ?>)'; }
                    var formId = <?= json_encode($xlate['type'] === 'quiz' ? 'xlateQuizForm' : 'xlatePhrasesForm') ?>;
                    setTimeout(function () { var f = document.getElementById(formId); if (f) { f.submit(); } }, 1200);
                })();
                </script>
                <?php elseif ($xlate && (int) $xlate['rest'] > 0 && (int) $xlate['done'] === 0): ?>
                <div class="muted" style="margin-top:10px; color:#a13e35; font-weight:700;">⚠️ Traduction arrêtée (limite quotidienne gratuite atteinte, ou service momentanément indisponible). Restant : <?= (int) $xlate['rest'] ?>. Réessaie plus tard<?= $mmHasEmail ? '' : ', ou configure MYMEMORY_EMAIL' ?>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
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

<?php bulkModalAndJs('parametres.php'); ?>

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

<!-- Modale : confirmation de suppression d'un module (remplace le confirm() du navigateur) -->
<div id="delModal" class="modal-backdrop">
    <div class="modal-card" style="max-width:520px;">
        <div style="text-align:center;">
            <div id="delIcon" style="font-size:3rem; line-height:1;">🗑</div>
            <h3 id="delTitle" style="margin:8px 0 6px;">Supprimer ce module ?</h3>
            <p id="delMsg" style="color:#555; line-height:1.55; margin:0;"></p>
            <p id="delWarn" style="display:none; background:#fdecec; border:1px solid #f5b5b5; color:#8a1f1f; font-weight:700; padding:11px 13px; border-radius:10px; margin:14px 0 0; text-align:left; line-height:1.5;"></p>
        </div>
        <form method="POST" action="module_save.php" style="margin-top:20px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delId" value="">
            <input type="hidden" name="return" value="parametres.php">
            <label style="display:block; font-weight:700; color:#244230; margin:14px 0 4px; text-align:left;">Mot de passe administrateur</label>
            <input type="password" name="admin_password" required autocomplete="off" placeholder="Mot de passe de verrouillage" style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
            <div class="modal-actions">
                <button type="button" class="btn btn-light" onclick="closeModal('delModal')">Annuler</button>
                <button type="submit" id="delConfirmBtn" class="btn btn-danger">🗑 Supprimer définitivement</button>
            </div>
        </form>
    </div>
</div>

<!-- Modale : confirmation avant de basculer un interrupteur (tous les switchs passent par ici) -->
<div id="switchModal" class="modal-backdrop">
    <div class="modal-card" style="max-width:460px;">
        <div style="text-align:center;">
            <div id="swIcon" style="font-size:2.6rem; line-height:1;">⚙️</div>
            <h3 id="swTitle" style="margin:8px 0 6px;">Confirmer le changement</h3>
            <p id="swMsg" style="color:#555; line-height:1.55; margin:0;"></p>
        </div>
        <div class="modal-actions" style="margin-top:20px;">
            <button type="button" class="btn btn-light" onclick="closeModal('switchModal')">Annuler</button>
            <button type="button" id="swConfirm" class="btn btn-primary">Confirmer</button>
        </div>
    </div>
</div>

<script>
    // --- Bascule d'un interrupteur : on demande TOUJOURS confirmation via une modale. ---
    var _swForm = null;
    function askSwitch(btn, label, isOn) {
        _swForm = btn.closest('form');
        document.getElementById('swIcon').textContent = isOn ? '🔕' : '🔔';
        document.getElementById('swTitle').textContent = isOn ? 'Désactiver ?' : 'Activer ?';
        document.getElementById('swMsg').textContent =
            'Veux-tu vraiment ' + (isOn ? 'désactiver' : 'activer') + ' : ' + label + ' ?';
        var c = document.getElementById('swConfirm');
        c.style.background = isOn ? '#c94a42' : '';
        c.textContent = isOn ? 'Oui, désactiver' : 'Oui, activer';
        openModal('switchModal');
    }
    document.addEventListener('DOMContentLoaded', function () {
        var c = document.getElementById('swConfirm');
        if (c) { c.addEventListener('click', function () { if (_swForm) { _swForm.submit(); } }); }
        // Ré-active l'onglet depuis l'ancre (#prefs…) après un rechargement (ex : aperçu de thème).
        var h = (location.hash || '').replace('#', '');
        if (h && document.getElementById('tab-' + h)) {
            document.querySelectorAll('.tab-btn').forEach(function (b) {
                if ((b.getAttribute('onclick') || '').indexOf("showTab('" + h + "'") !== -1) { showTab(h, b); }
            });
        }
        // Restaure la position de défilement mémorisée juste avant l'aperçu.
        try {
            var y = sessionStorage.getItem('fami_prefs_scroll');
            if (y !== null) { sessionStorage.removeItem('fami_prefs_scroll'); requestAnimationFrame(function () { window.scrollTo(0, parseInt(y, 10) || 0); }); }
        } catch (e) {}
    });
    // Mémorise la position avant de lancer / arrêter un aperçu (liens ?theme=…).
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href*="theme="]') : null;
        if (a) { try { sessionStorage.setItem('fami_prefs_scroll', String(window.scrollY || window.pageYOffset || 0)); } catch (x) {} }
    }, true);

    // Ouvre la modale de suppression, adaptée au module (et alerte si sous-modules verrouillés).
    function askDeleteModule(id, nom, hasLocked, hasChildren) {
        document.getElementById('delId').value = id;
        document.getElementById('delTitle').textContent = 'Supprimer « ' + nom + ' » ?';
        var msg = document.getElementById('delMsg');
        var warn = document.getElementById('delWarn');
        var icon = document.getElementById('delIcon');
        var btn = document.getElementById('delConfirmBtn');
        msg.textContent = hasChildren
            ? 'Ce module et TOUS ses sous-modules seront supprimés définitivement.'
            : 'Ce module sera supprimé définitivement.';
        if (hasLocked) {
            icon.textContent = '⚠️';
            warn.style.display = 'block';
            warn.textContent = '⚠️ ATTENTION : ce module contient des sous-modules VERROUILLÉS. Ils seront supprimés eux aussi. Cette action est IRRÉVERSIBLE.';
            btn.style.background = '#9b1c1c';
            btn.style.boxShadow = '0 0 0 2px #ffb3b3';
        } else {
            icon.textContent = '🗑';
            warn.style.display = 'none';
            btn.style.background = '';
            btn.style.boxShadow = '';
        }
        openModal('delModal');
    }
    function askPassword(action, id) {
        document.getElementById('pwdAction').value = action;
        document.getElementById('pwdId').value = id;
        var titles = {
            'delete': 'Supprimer ce module verrouillé',
            'toggle_lock': 'Verrouiller / déverrouiller le module',
            'delete_profile': 'Supprimer ce profil verrouillé',
            'toggle_lock_profile': 'Verrouiller / déverrouiller le profil',
            'unlock_reorder': "Modifier l'ordre des modules"
        };
        document.getElementById('pwdTitle').textContent = titles[action] || 'Confirmation';
        document.getElementById('pwdInput').value = '';
        openModal('pwdModal');
    }
    function toggleModuleChildren(id, btn) {
        var expanded = btn.getAttribute('data-expanded') === '1';
        if (expanded) {
            collapseModuleDescendants(id);
            btn.setAttribute('data-expanded', '0');
            btn.textContent = '▸';
        } else {
            document.querySelectorAll('tr[data-parent="' + id + '"]').forEach(function (tr) { tr.style.display = ''; });
            btn.setAttribute('data-expanded', '1');
            btn.textContent = '▾';
        }
    }
    function collapseModuleDescendants(id) {
        document.querySelectorAll('tr[data-parent="' + id + '"]').forEach(function (tr) {
            tr.style.display = 'none';
            var childBtn = tr.querySelector('.tree-toggle');
            if (childBtn) { childBtn.setAttribute('data-expanded', '0'); childBtn.textContent = '▸'; }
            collapseModuleDescendants(tr.getAttribute('data-id'));
        });
    }
    function expandAllModules() {
        document.querySelectorAll('tr[data-parent]').forEach(function (tr) { tr.style.display = ''; });
        document.querySelectorAll('.tree-toggle').forEach(function (b) { b.setAttribute('data-expanded', '1'); b.textContent = '▾'; });
    }
    function collapseAllModules() {
        document.querySelectorAll('tr[data-parent]').forEach(function (tr) {
            if (tr.getAttribute('data-parent') !== '0') { tr.style.display = 'none'; }
        });
        document.querySelectorAll('.tree-toggle').forEach(function (b) { b.setAttribute('data-expanded', '0'); b.textContent = '▸'; });
    }
    function filterUsers() {
        var q = (document.getElementById('userSearch').value || '').toLowerCase().trim();
        document.querySelectorAll('#usersTbody tr').forEach(function (tr) {
            var s = tr.getAttribute('data-search') || '';
            tr.style.display = (q === '' || s.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    function filterAgences() {
        var q = (document.getElementById('agenceSearch').value || '').toLowerCase().trim();
        document.querySelectorAll('#agencesTbody tr').forEach(function (tr) {
            var s = tr.getAttribute('data-search') || '';
            tr.style.display = (q === '' || s.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    function togglePt(id, btn) {
        var el = document.getElementById(id);
        if (!el) { return; }
        if (el.style.display === 'none') { el.style.display = 'block'; btn.textContent = '▾'; }
        else { el.style.display = 'none'; btn.textContent = '▸'; }
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
    // Rouvre l'onglet indiqué par le hash (#histprofil) après un rechargement (réorganisation)
    document.addEventListener('DOMContentLoaded', function () {
        var name = (location.hash || '').replace('#', '');
        if (!name) { return; }
        var target = null;
        document.querySelectorAll('.tab-btn').forEach(function (b) {
            var oc = b.getAttribute('onclick') || '';
            if (oc.indexOf("'" + name + "'") !== -1) { target = b; }
        });
        if (target) { showTab(name, target); }
    });
</script>
<?php
// --- Apercu de theme EN PLACE (sans quitter la page) — ajout non destructif ---
$__famiThemes = [];
$__bd = function_exists('birthdayTheme') ? birthdayTheme() : [];
$__famiThemes['anniversaire'] = [
    'nom'       => is_array($__bd['nom'] ?? null) ? $__bd['nom'][0] : ($__bd['nom'] ?? '🎂 Anniversaire'),
    'accent'    => $__bd['accent'] ?? '#e0245e',
    'particles' => $__bd['particles'] ?? ['🎈', '🎉', '🎂'],
];
foreach (siteThemeCatalog() as $__k => $__t) {
    $__famiThemes[$__k] = [
        'nom'       => is_array($__t['nom']) ? $__t['nom'][0] : $__t['nom'],
        'accent'    => $__t['accent'] ?? '#2d5a37',
        'particles' => $__t['particles'] ?? ['✨'],
    ];
}
?>
<style>
@keyframes famiFall { to { transform: translateY(115vh) rotate(360deg); } }
@keyframes famiPop  { from { transform: translateX(-50%) scale(.6); opacity: 0; } to { transform: translateX(-50%) scale(1); opacity: 1; } }
</style>
<script>
window.FAMI_THEMES = <?= json_encode($__famiThemes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
    function preview(key) {
        var t = window.FAMI_THEMES[key];
        if (!t) { return; }
        var old = document.getElementById('famiThemePreview');
        if (old) { old.remove(); }
        var ov = document.createElement('div');
        ov.id = 'famiThemePreview';
        ov.style.cssText = 'position:fixed; inset:0; top:0; left:0; right:0; bottom:0; z-index:99999; pointer-events:none; overflow:hidden;';
        document.body.appendChild(ov);
        var parts = (t.particles && t.particles.length) ? t.particles : ['✨'];
        for (var i = 0; i < 36; i++) {
            var s = document.createElement('span');
            s.textContent = parts[i % parts.length];
            var dur = 2.6 + Math.random() * 2.6;
            s.style.cssText = 'position:absolute; top:-10%; left:' + (Math.random() * 100) + '%; font-size:' + (18 + Math.random() * 22) + 'px; opacity:.95; animation:famiFall ' + dur + 's linear ' + (Math.random() * 1.4) + 's forwards;';
            ov.appendChild(s);
        }
        var b = document.createElement('div');
        b.textContent = t.nom || '';
        b.style.cssText = 'position:absolute; top:15%; left:50%; transform:translateX(-50%); background:' + (t.accent || '#2d5a37') + '; color:#fff; padding:10px 22px; border-radius:999px; font-weight:800; font-size:1.1rem; box-shadow:0 10px 30px rgba(0,0,0,.25); animation:famiPop .5s ease;';
        ov.appendChild(b);
        setTimeout(function () {
            ov.style.transition = 'opacity .6s';
            ov.style.opacity = '0';
            setTimeout(function () { if (ov && ov.parentNode) { ov.remove(); } }, 650);
        }, 4200);
    }
    window.famiPreviewTheme = preview;
    document.querySelectorAll('.theme-chip').forEach(function (chip) {
        chip.addEventListener('click', function (e) {
            if (chip._sup) { return; }
            e.preventDefault();
            preview(chip.getAttribute('data-key'));
        });
    });
})();
</script>

</body>
</html>
