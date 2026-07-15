<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/rendezvous.php';
require_once 'includes/i18n_nl.php'; // moduleContenu() / moduleQuizJson() : version NL si dispo

// En mode aperçu, l'admin voit la page comme l'utilisateur (boutons admin masqués)
$isAdmin = ((($_SESSION['role'] ?? '') === 'admin') && !isApercuActif());

$moduleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$module = $moduleId > 0 ? getModuleById($db, $moduleId) : null;

if (!$module || (!$isAdmin && (int) $module['is_active'] !== 1)) {
    header('Location: index.php');
    exit();
}
// Contrôle d'accès par profil (le module et ses parents restreignent la visibilité).
if (!$isAdmin && function_exists('userCanSeeModule') && !userCanSeeModule($module, currentDisplayRole())) {
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

// Structure « contenu » : ce module est-il un sous-module écrit/vidéo, ou un conteneur qui en regroupe ?
// Droits de contribution (non-admin autorisé dans une zone) — voir includes/contrib_settings.php.
require_once __DIR__ . '/includes/contrib_settings.php';
$actorRole = (string) ($_SESSION['role'] ?? '');
$canContribHere = !$isAdmin && contribRoleAllowed($db, $actorRole) && contribModuleInZone($db, (int) $module['id']);

$isContentChild = in_array((string) ($module['content_kind'] ?? ''), ['ecrit', 'video'], true);
$hasContentChildren = false;
foreach ($children as $__c) {
    if (in_array((string) ($__c['content_kind'] ?? ''), ['ecrit', 'video'], true)) { $hasContentChildren = true; break; }
}
// Le formulaire « Ajout de contenu » : sur un élément vierge OU un conteneur-contenu (pour compléter), jamais sur un sous-module enfant.
$showContentForm = empty($module['is_booking']) && !$isContentChild && (empty($isContainer) || $hasContentChildren);

// Y a-t-il déjà du contenu (sur l'élément OU sur ses sous-modules Le guide / Vidéo) ?
// Calculé ici (tôt) pour placer le bloc « Ajout de contenu » EN PREMIER quand le module est vierge.
$existPdf = !empty($module['pdf_path']) ? $module['pdf_path'] : null;
$existVideo = !empty($module['video_path']) ? $module['video_path'] : null;
$existVideoProc = (($module['video_status'] ?? '') === 'processing');
if ($isContainer) {
    foreach ($children as $cc) {
        if (($cc['content_kind'] ?? '') === 'ecrit' && !empty($cc['pdf_path'])) { $existPdf = $cc['pdf_path']; }
        if (($cc['content_kind'] ?? '') === 'video') {
            if (!empty($cc['video_path'])) { $existVideo = $cc['video_path']; }
            if (($cc['video_status'] ?? '') === 'processing') { $existVideoProc = true; }
        }
    }
}
$hasAnyContent = $existPdf || $existVideo || $existVideoProc;

// Quiz DÉJÀ en place ? Un remplacement de contenu l'efface : on doit prévenir avant,
// sinon on perd un quiz relu et corrigé à la main sans s'en apercevoir.
$existingQuizNb = 0;
try {
    $qq = $db->prepare("SELECT quiz_json FROM modules WHERE (id = ? OR parent_id = ?) AND quiz_json IS NOT NULL AND quiz_json <> '' LIMIT 1");
    $qq->execute([$moduleId, $moduleId]);
    $qj = json_decode((string) $qq->fetchColumn(), true);
    $existingQuizNb = (is_array($qj) && !empty($qj['questions'])) ? count($qj['questions']) : 0;
} catch (Exception $e) {}
// Module vierge (aucun contenu) : on remonte le formulaire d'ajout tout en haut.
$emptyContentFocus = $showContentForm && !$hasAnyContent;

// Page vidéo dédiée (gabarit Famiformation) : module non-conteneur avec vidéo et sans PDF.
$mVideoStatus = (string) ($module['video_status'] ?? '');
$mHasVideoAny = !empty($module['video_path']) || $mVideoStatus === 'processing' || $mVideoStatus === 'failed';
$isVideoPage = !$isContainer && empty($module['is_booking']) && $mHasVideoAny && empty($module['pdf_path']);
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(moduleNom($module)) ?> — FamiFormation</title>
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
        /* Contenu importé mais pas encore relu : ce n'est PAS un module désactivé, c'est un
           travail en cours. On le montre comme tel, avec un accès direct à la relecture. */
        .tile-review { border: 2px dashed #e8a13a; background: linear-gradient(180deg, #fffaf2, #fff5e6); }
        .tile-review .tile-title { color: #8a5a00; }
        .tile-review .tile-desc { color: #a06f21; font-weight: 600; }
        .badge-eval { display:inline-block; background:#2d5a37; color:#fff; font-size:0.78rem; font-weight:700; padding:4px 12px; border-radius:20px; margin-top:8px; }
        .tile .badge-eval { position:absolute; top:12px; right:12px; margin:0; }
        /* Actions du guide : au-dessus de la fiche, alignées à DROITE. */
        .guide-actions { width:92%; max-width:1040px; margin:14px auto -6px; display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
        .guide-actions .uni-ico { display:inline-flex; align-items:center; gap:6px; }
        @media print { .guide-actions { display:none !important; } }
        .content-card { background: rgba(255,255,255,0.96); border-radius: 18px; padding: 32px; width: 90%; max-width: 900px; margin: 30px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        /* Module vierge : le bloc « Ajout de contenu » remonte tout en haut (après le titre). */
        body.fami-empty-content .fami-rib { order:-10; }   /* le ruban reste EN PREMIER */
        body.fami-empty-content .topbar { order:-3; }
        body.fami-empty-content .header { order:-2; }
        body.fami-empty-content .content-card.add-content { order:-1; }
        .flash { background: #fff8e1; border: 1px solid #ffe082; color: #6a5400; padding: 12px 18px; border-radius: 12px; width: 90%; max-width: 900px; margin-top: 16px; font-weight: 700; }
        .admin-actions { margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn { border: none; border-radius: 10px; padding: 10px 18px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-create { background: #2d5a37; color: #fff; }
        .btn-danger { background: #c94a42; color: #fff; }
        /* Modale */
        .modal-backdrop { position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-card { background: #fff; border-radius: 14px; padding: 28px; max-width: 480px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .modal-card h3 { margin-top: 0; color: #2d5a37; }
        .modal-card label { display:block; font-weight:700; color:#244230; margin: 12px 0 4px; }
        .modal-card input[type=text], .modal-card textarea { width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; font:inherit; }
        .modal-card input[type=file] { width:100%; }
        .modal-card .chk { font-weight:600; display:flex; align-items:center; gap:8px; }
        .icon-wrap { display:flex; flex-wrap:wrap; gap:6px; }
        .icon-opt { font-size:1.3rem; background:#f4f7f6; border:2px solid transparent; border-radius:10px; padding:6px 8px; cursor:pointer; }
        .icon-opt.sel { border-color:#2d5a37; background:#e8f5e9; }
        .roles-wrap { display:flex; flex-wrap:wrap; gap:12px; }
        .role-chk { font-weight:600; display:flex; align-items:center; gap:6px; }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
        .btn-cancel { background:#e9ecef; color:#333; }
        /* Zones d'upload (PDF / vidéo) */
        .drop-zone { position: relative; border: 2.5px dashed #b9cdbf; border-radius: 14px; background:#f6faf7; padding: 26px 16px; text-align:center; cursor:pointer; transition: all .15s ease; margin: 14px 0 4px; }
        .drop-zone:hover { border-color:#2d5a37; background:#eef7f0; }
        .drop-zone.over { border-color:#2d5a37; background:#e3f2e7; }
        .drop-zone.has-file { border-style: solid; border-color:#2d5a37; background:#e8f5e9; }
        .dz-input { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer; }
        .dz-icon { font-size:2.6rem; line-height:1; }
        .dz-title { font-weight:800; color:#2d5a37; font-size:1.15rem; margin-top:6px; }
        .dz-hint { color:#6c7a70; font-size:0.85rem; margin-top:4px; }
        .dz-file { margin-top:10px; font-weight:800; color:#1d6a39; word-break:break-all;
                   background:#e7f6ec; border:1px solid #b6e0c2; border-radius:9px; padding:7px 10px; }
        /* Colonnes de dépôt : même largeur, même hauteur, la zone occupe toute sa colonne. */
        .dz-cols { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; align-items:stretch; }
        .dz-cols > div { display:flex; flex-direction:column; }
        .dz-cols .drop-zone { flex:1; display:flex; flex-direction:column; justify-content:center; }
        /* « Générer un quiz » : un vrai BLOC. En inline, son fond et son padding débordaient
           par-dessus la zone vidéo et masquaient le nom du fichier choisi. */
        .quiz-opt { display:flex; align-items:flex-start; gap:12px; margin-top:20px; padding:14px 16px;
                    background:#f4f7f6; border:1px solid #e1e8e3; border-radius:12px; cursor:pointer; }
        .quiz-opt:hover { background:#eef7f0; border-color:#cfe3d5; }
        .quiz-opt input { margin-top:3px; flex:none; }
        .quiz-opt strong { color:#244230; display:block; }
        .quiz-opt small { display:block; color:#7a8a80; font-weight:400; margin-top:2px; }
        .dz-existing { font-size:0.85rem; color:#555; margin:4px 0 2px; }
        /* Fichier déjà présent : un CLIC ouvre un choix (télécharger / supprimer).
           Avant, le clic téléchargeait directement — sans le vouloir, on lançait un
           téléchargement de plusieurs centaines de Mo. */
        .file-chip { display:flex; align-items:center; gap:8px; width:100%; text-align:left; margin:8px 0 2px;
            background:#eef7f0; border:1px solid #cfe3d5; color:#244230; border-radius:10px; padding:9px 12px;
            font:inherit; font-weight:700; cursor:pointer; transition:background .12s; }
        .file-chip:hover { background:#e3f2e7; }
        .file-chip small { margin-left:auto; font-weight:400; color:#7a8a80; font-size:.76rem; }
        /* Variante FINE (sous-titres .srt) : compacte, sur une ligne, moitié moins haute */
        .drop-zone--slim { padding:7px 12px; border-width:2px; margin:6px 0 2px; display:flex; align-items:center; gap:8px; text-align:left; }
        /* `display:flex` ci-dessus écrase l'attribut [hidden] : on le rétablit explicitement,
           sinon la zone .srt (masquée par défaut, révélée en tapant « srt ») resterait visible. */
        .drop-zone[hidden] { display:none !important; }
        .drop-zone--slim .dz-icon { font-size:1.05rem; margin:0; }
        .drop-zone--slim .dz-title { font-size:0.85rem; margin:0; }
        .drop-zone--slim .dz-hint { font-size:0.72rem; margin:0; color:#8a968f; }
        .drop-zone--slim .dz-file { margin:0 0 0 auto; font-size:0.8rem; }
        .topbar { width:100%; box-sizing:border-box; display:flex; align-items:center; justify-content:space-between; padding:16px; gap:12px; }
        .topbar .back-link { margin:0; align-self:auto; }
        .uni-actions { display:flex; gap:10px; }
        .uni-ico { width:46px; height:46px; display:inline-flex; align-items:center; justify-content:center; font-size:1.25rem; background:rgba(255,255,255,0.92); color:#2d5a37; border:none; border-radius:14px; cursor:pointer; text-decoration:none; box-shadow:0 4px 14px rgba(0,0,0,.14); transition:transform .12s ease, background .15s; }
        .uni-ico:hover { background:#2d5a37; color:#fff; transform:translateY(-2px); }
        .lang-switch { display:inline-flex; gap:6px; }
        .lang-btn { background:rgba(255,255,255,0.9); color:#2d5a37; text-decoration:none; padding:8px 14px; border-radius:20px; font-weight:700; font-size:0.85rem; box-shadow:0 4px 10px rgba(0,0,0,0.1); }
        .lang-btn.active { background:#2d5a37; color:#fff; }
        .lang-btn:hover { background:#fff; } .lang-btn.active:hover { background:#357a44; }
    </style>
</head>
<body class="<?= $emptyContentFocus ? 'fami-empty-content' : '' ?>">
    <?= apercuBanner($db) ?>
    <?php
        require_once __DIR__ . '/includes/pdf_access.php';
        $uniHasPdf = !empty($module['pdf_path']);
        $uniHasContent = (!empty($module['uniformized']) && !empty($module['contenu_ia']) && $uniHasPdf);
        $uniRole = function_exists('currentDisplayRole') ? currentDisplayRole() : ($_SESSION['role'] ?? '');
        $uniPdfUrl = $uniHasPdf ? moduleFileUrl($module['pdf_path']) : '';
        $canViewPdf = $uniHasContent && pdfCanView($db, $uniRole, !empty($isAdmin));

        // GATE QUIZ : un apprenant ne télécharge (guide OU vidéo) QUE s'il a validé le quiz
        // de la formation. Sans quiz, rien à valider → autorisé. L'admin n'est jamais bloqué.
        require_once __DIR__ . '/includes/quiz_pass.php';
        $quizGateOk = !empty($isAdmin)
            || quizUserPassed($db, !empty($module['parent_id']) ? (int) $module['parent_id'] : (int) $module['id'], (int) ($_SESSION['user_id'] ?? 0));

        $canDlPdf = $uniHasPdf && $quizGateOk && pdfCanDownload($db, $uniRole, !empty($isAdmin));
        // Vidéo : téléchargement réglable dans Paramètres → Préférences (désactivé par défaut).
        $uniHasVideo = !empty($module['video_path']);
        $uniVideoUrl = $uniHasVideo ? moduleFileUrl($module['video_path']) : '';
        $canDlVideo = $uniHasVideo && $quizGateOk && function_exists('videoCanDownload') && videoCanDownload($db, $uniRole, !empty($isAdmin));
    ?>
    <?php
        require_once __DIR__ . '/includes/topbar.php';
        // Le RUBAN ne contient QUE : retour · titre · notifs · paramètres · accueil ·
        // déconnexion · FR/NL. Rien d'autre ne s'y ajoute (les actions de la page vivent
        // dans la page).
        famiTopbar($db, [
            'back'  => !empty($module['parent_id']) ? 'module.php?id=' . (int) $module['parent_id'] : 'index.php',
            'title' => moduleNom($module),
        ]);
    ?>

    <?php if (empty($uniHasContent) && empty($isVideoPage)): ?>
    <div class="header">
        <img src="logo.png" alt="Famiflora" class="logo-main"><br>
        <h1><?= moduleIconHtml($module, '1.6rem') ?> <?= htmlspecialchars(moduleNom($module)) ?></h1>
        <?php if (moduleDesc($module) !== ''): ?>
            <div class="desc"><?= htmlspecialchars(moduleDesc($module)) ?></div>
        <?php endif; ?>
        <?php if (!$isContainer && !empty($module['a_evaluer'])): ?>
            <div><span class="badge-eval">📝 <?= t('À évaluer', 'Te evalueren') ?></span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($flash): ?><div class="flash"><?= $flash ?></div><?php endif; ?>

    <?php if (!empty($module['is_booking'])): ?>
        <?= renderRendezvousModule($db, $module, $isAdmin, currentDisplayRole(), $_SESSION['user_id'] ?? 0) ?>
    <?php elseif ($isContainer): ?>
        <div class="tiles-container">
            <?php foreach ($children as $child): ?>
                <?php if (!$isAdmin && function_exists('userCanSeeModule') && !userCanSeeModule($child, currentDisplayRole())) { continue; } ?>
                <?php
                    $childActive = ((int) $child['is_active'] === 1);
                    $childLink = trim((string) ($child['link'] ?? ''));
                    $childHref = $childLink !== '' ? $childLink : 'module.php?id=' . (int) $child['id'];
                    $childExternal = (stripos($childLink, 'http') === 0);
                ?>
                <?php if ($childActive): ?>
                <a href="<?= htmlspecialchars($childHref) ?>" class="tile<?= $isAdmin ? ' mod-tile' : '' ?>"<?= $isAdmin ? ' data-mod-id="' . (int) $child['id'] . '"' : '' ?><?= $childExternal ? ' target="_blank" rel="noopener"' : '' ?>>
                    <?php if (!empty($child['a_evaluer'])): ?><span class="badge-eval">📝</span><?php endif; ?>
                    <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                    <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars(moduleDesc($child)) ?></div>
                </a>
                <?php else: ?>
                    <?php
                        // « Inactif » était un MENSONGE : un contenu fraîchement importé n'est pas
                        // désactivé, il attend d'être RELU (on l'a caché exprès tant qu'il n'est pas
                        // validé). On le dit, et on donne le lien pour finir le travail.
                        $enRelecture = ((string) ($child['content_status'] ?? '') === 'pending')
                            && (!empty($child['contenu_ia']) || !empty($child['video_path']) || !empty($child['video_src_path']));
                        $peutRelire = $isAdmin || (int) ($child['contenu_by'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0);
                        $lienRelire = (($child['content_kind'] ?? '') === 'ecrit')
                            ? 'module_edit.php?id=' . (int) $child['id']
                            : 'module.php?id=' . (int) $child['id'];
                    ?>
                    <?php if ($enRelecture && $peutRelire): ?>
                    <a href="<?= htmlspecialchars($lienRelire) ?>" class="tile tile-review<?= $isAdmin ? ' mod-tile' : '' ?>"<?= $isAdmin ? ' data-mod-id="' . (int) $child['id'] . '"' : '' ?> title="<?= t('Ce contenu attend votre relecture. Il sera visible dès que vous l\'aurez validé.', 'Deze inhoud wacht op je nalezing. Ze wordt zichtbaar zodra je ze goedkeurt.') ?>">
                        <span class="badge-eval" style="background:#e8a13a;">✍️ <?= t('À relire', 'Na te lezen') ?></span>
                        <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                        <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                        <div class="tile-desc"><?= t('Pas encore visible par les apprenants — termine la relecture pour le publier.', 'Nog niet zichtbaar voor de lerenden — lees na en keur goed om te publiceren.') ?></div>
                    </a>
                    <?php else: ?>
                    <div class="tile inactive<?= $isAdmin ? ' mod-tile' : '' ?>"<?= $isAdmin ? ' data-mod-id="' . (int) $child['id'] . '"' : '' ?> title="<?= $isAdmin ? 'Module inactif — clic droit pour modifier' : t('Module inactif — réactive-le dans Gestion des modules', 'Module niet actief — heractiveer hem in Modulebeheer') ?>" style="cursor:<?= $isAdmin ? 'context-menu' : 'not-allowed' ?>;">
                        <span class="badge-eval" style="background:#999;"><?= t('Inactif', 'Niet actief') ?></span>
                        <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                        <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                        <div class="tile-desc"><?= htmlspecialchars(moduleDesc($child)) ?></div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($children)): ?>
                <div class="content-card" style="text-align:center;"><?= t("Aucun sous-module pour l'instant.", 'Nog geen submodules.') ?></div>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin && !empty($children)): ?>
        <div style="color:#fff; background:rgba(0,0,0,0.28); padding:6px 12px; border-radius:10px; font-size:0.82rem; margin-top:6px;">💡 Astuce admin : <strong>clic droit</strong> sur une tuile pour la <strong>modifier</strong>.</div>
        <?php endif; ?>
    <?php else: ?>
        <?php $isUni = !empty($module['uniformized']); ?>
        <?php $vStatus = (string) ($module['video_status'] ?? ''); ?>
        <?php
            // Quiz associé — JAMAIS affiché dans le guide/la vidéo. Bouton dédié vers quiz.php.
            $quizModuleId = 0;
            if (!empty($module['quiz_json'])) { $quizModuleId = (int) $module['id']; }
            elseif (!empty($module['parent_id'])) {
                try { $qst = $db->prepare("SELECT id FROM modules WHERE parent_id = ? AND quiz_json IS NOT NULL AND quiz_json <> '' LIMIT 1"); $qst->execute([(int) $module['parent_id']]); $qm = $qst->fetchColumn(); if ($qm) { $quizModuleId = (int) $qm; } } catch (Exception $e) {}
            }
            $quizHref = $quizModuleId > 0 ? ('quiz.php?id=' . $quizModuleId) : '';
            $canEditContent = ($isAdmin || !empty($canContribHere) || (int) ($module['contenu_by'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0));
            $isGuide = (!empty($module['uniformized']) && !empty($module['contenu_ia']));
            // Doutes de l'IA (champ "fix") : comptés pour les éditeurs, signalés bien visiblement.
            // Le guide ET le quiz peuvent en porter — on affiche un bandeau par source.
            $aiDoubts = ($canEditContent && $isGuide) ? famiCountDoubts($module['contenu_ia'] ?? '') : 0;
            $quizDoubts = $canEditContent ? famiCountDoubts($module['quiz_json'] ?? '') : 0;
        ?>
        <?php
            // BANDEAU « en relecture » : le contenu est caché tant qu'il n'est pas validé.
            // Sans ce rappel, on croit que l'import a échoué ou que le module est cassé.
            $pending = [];
            if ($canEditContent) {
                foreach ($children as $ch) {
                    if ((int) ($ch['is_active'] ?? 1) === 0
                        && (string) ($ch['content_status'] ?? '') === 'pending') {
                        $pending[] = $ch;
                    }
                }
            }
            $pendingGuide = 0;
            foreach ($pending as $ch) { if (($ch['content_kind'] ?? '') === 'ecrit') { $pendingGuide = (int) $ch['id']; } }
        ?>
        <?php if (!empty($pending)): ?>
        <a href="<?= $pendingGuide > 0 ? 'module_edit.php?id=' . $pendingGuide : 'module.php?id=' . (int) $pending[0]['id'] ?>" class="ai-doubt-banner" style="background:linear-gradient(180deg,#fff8e6,#ffefcc); border-color:#e8a13a; color:#7a4b00;">
            <span class="ai-doubt-ico">✍️</span>
            <span><strong><?= t('Ce contenu attend votre relecture', 'Deze inhoud wacht op je nalezing') ?></strong> — <?= t("il n'est pas encore visible par les apprenants. Il sera publié automatiquement dès que vous aurez validé la relecture (et le quiz, s'il y en a un).", 'ze is nog niet zichtbaar voor de lerenden. Ze wordt automatisch gepubliceerd zodra je de nalezing (en de quiz, indien aanwezig) goedkeurt.') ?></span>
            <span class="ai-doubt-cta"><?= t('Relire', 'Nalezen') ?> →</span>
        </a>
        <?php endif; ?>

        <?php if ($aiDoubts > 0): ?>
        <a href="module_edit.php?id=<?= (int) $module['id'] ?>" class="ai-doubt-banner">
            <span class="ai-doubt-ico">⚠️</span>
            <span><strong><?= (int) $aiDoubts ?> <?= $aiDoubts > 1 ? 'points signalés' : 'point signalé' ?> par l'IA dans le guide</strong> — à vérifier. Cliquez pour voir le détail et corriger.</span>
            <span class="ai-doubt-cta">Voir →</span>
        </a>
        <?php endif; ?>
        <?php if ($quizDoubts > 0): ?>
        <a href="module_quiz.php?id=<?= (int) $module['id'] ?>" class="ai-doubt-banner">
            <span class="ai-doubt-ico">⚠️</span>
            <span><strong><?= (int) $quizDoubts ?> <?= $quizDoubts > 1 ? 'questions douteuses' : 'question douteuse' ?> dans le quiz</strong> — l'IA n'est pas sûre de la bonne réponse. Cliquez pour trancher.</span>
            <span class="ai-doubt-cta">Contrôler →</span>
        </a>
        <style>
        .ai-doubt-banner { display:flex; align-items:center; gap:12px; max-width:820px; width:92%; margin:14px auto 0; text-decoration:none;
            background:linear-gradient(180deg,#fff3e0,#ffe6c7); border:2px solid #e8a13a; color:#7a4b00; padding:14px 18px; border-radius:14px;
            font-size:0.98rem; font-weight:600; box-shadow:0 8px 22px rgba(200,120,20,.28); animation:aiDoubtPulse 1.8s ease-in-out infinite; }
        .ai-doubt-banner:hover { background:linear-gradient(180deg,#ffe6c7,#ffdcae); }
        .ai-doubt-ico { font-size:1.6rem; line-height:1; flex:none; }
        .ai-doubt-cta { margin-left:auto; background:#8a5a00; color:#fff; border-radius:999px; padding:7px 16px; font-weight:800; white-space:nowrap; flex:none; }
        @keyframes aiDoubtPulse { 0%,100%{ box-shadow:0 8px 22px rgba(200,120,20,.28); } 50%{ box-shadow:0 8px 30px rgba(200,120,20,.5); } }
        @media (prefers-reduced-motion: reduce) { .ai-doubt-banner { animation:none; } }
        </style>
        <?php endif; ?>
        <?php if ($isVideoPage): ?>
            <?php require_once __DIR__ . '/includes/video_view.php'; ?>
            <?php renderVideoPage($module, $isAdmin, $quizHref); ?>
        <?php elseif ($vStatus === 'processing' && empty($module['video_src_path'])): ?>
            <div class="content-card" style="text-align:center;">
                <div style="font-size:2.4rem;">🎬</div>
                <div style="font-weight:800; color:#2d5a37; font-size:1.15rem; margin-top:6px;"><?= t('Vidéo en cours de préparation…', 'Video wordt voorbereid…') ?></div>
                <div style="color:#666; margin-top:6px;"><?= t('Compression automatique en 720p pour une lecture fluide. La vidéo apparaîtra ici toute seule.', 'Automatische compressie naar 720p voor vlot afspelen. De video verschijnt hier vanzelf.') ?></div>
            </div>
            <script>setTimeout(function () { location.reload(); }, 15000);</script>
        <?php elseif ($vStatus === 'failed'): ?>
            <div class="content-card" style="text-align:center; color:#b3261e;">
                ⚠ <?= t('La préparation de la vidéo a échoué.', 'De voorbereiding van de video is mislukt.') ?>
                <?php if ($isAdmin): ?><br><small><?= t('Réessaie en redéposant la vidéo via « Ajouter du contenu ».', 'Probeer opnieuw door de video opnieuw toe te voegen.') ?></small><?php endif; ?>
            </div>
        <?php elseif (!empty($module['video_path'])): ?>
            <div class="content-card">
                <video id="famiVideo" controls controlsList="nodownload" playsinline style="width:100%; border-radius:12px; background:#000;">
                    <source src="<?= htmlspecialchars(moduleFileUrl($module['video_path'])) ?>">
                    <?= t('Votre navigateur ne peut pas lire cette vidéo.', 'Uw browser kan deze video niet afspelen.') ?>
                </video>
                <div style="text-align:center; color:#7a8a80; font-size:.82rem; margin-top:8px;">⏱️ <?= t('Avance rapide désactivée — le retour en arrière reste possible.', 'Vooruitspoelen is uitgeschakeld — terugspoelen kan wel.') ?></div>
            </div>
            <script>
            (function () {
                var v = document.getElementById('famiVideo');
                if (!v) { return; }
                var maxT = 0;
                v.addEventListener('timeupdate', function () {
                    if (!v.seeking) { maxT = Math.max(maxT, v.currentTime); }
                });
                v.addEventListener('seeking', function () {
                    if (v.currentTime > maxT + 1) { v.currentTime = maxT; }
                });
            })();
            </script>
        <?php endif; ?>
        <?php if (!empty($module['pdf_path'])): ?>
            <?php if ($isUni && !empty($module['contenu_ia'])): ?>
                <?php if ($canViewPdf || $canDlPdf || $canDlVideo): ?>
                <div class="guide-actions">
                    <?php if ($canViewPdf): ?><button type="button" id="uniEye" class="uni-ico" title="<?= t('Voir le PDF original', 'Originele PDF bekijken') ?>" onclick="window.uniTogglePdf && window.uniTogglePdf()">👁</button><?php endif; ?>
                    <?php if ($canDlPdf): ?><button type="button" class="uni-ico" title="<?= t('Télécharger le guide (PDF, mise en page du site)', 'De gids downloaden (PDF, opmaak van de site)') ?>" onclick="window.print()">⤓ <span><?= t('Guide PDF', 'Gids PDF') ?></span></button><?php endif; ?>
                    <?php if ($canDlVideo): ?><button type="button" class="uni-ico" data-vid="<?= (int) $module['id'] ?>" onclick="famiVideoDownload(this)" title="<?= t('Télécharger la vidéo (intro + vidéo + fin)', 'De video downloaden (intro + video + slot)') ?>">🎬 <span><?= t('Vidéo', 'Video') ?></span></button><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php require_once __DIR__ . '/includes/content_view.php'; ?>
                <?php // moduleContenu() sert la version NL si l'utilisateur est en néerlandais (sinon FR). ?>
                <?php
                    // Date du document (jj/mm/aaaa), affichée sur la couverture à côté des autres repères.
                    $docDate = '';
                    $rawDate = (string) ($module['created_at'] ?? '');
                    if ($rawDate !== '') { $ts = strtotime($rawDate); if ($ts) { $docDate = date('d/m/Y', $ts); } }
                    renderUniformContent(moduleContenu($module), $uniPdfUrl, $canViewPdf, (array) json_decode((string) ($module['contenu_images'] ?? '[]'), true), $quizHref, $docDate);
                ?>
            <?php elseif ($isUni): ?>
                <div class="content-card" id="uniPdf" data-src="<?= htmlspecialchars(moduleFileUrl($module['pdf_path'])) ?>">
                    <div style="text-align:center; color:#2d5a37; font-weight:700;"><?= t('Chargement du document…', 'Document laden…') ?></div>
                </div>
            <?php else: ?>
                <div class="content-card" style="padding:0; overflow:hidden;">
                    <iframe src="<?= htmlspecialchars(moduleFileUrl($module['pdf_path'])) ?>" style="width:100%; height:80vh; border:none; border-radius:18px;"></iframe>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (empty($module['video_path']) && empty($module['pdf_path']) && $vStatus === '' && !$emptyContentFocus): ?>
            <div class="content-card" style="text-align:center; color:#666;"><?= t("Ce module n'a pas encore de contenu.", 'Deze module heeft nog geen inhoud.') ?></div>
        <?php endif; ?>
        <?php if ($canEditContent && ($isGuide || $quizModuleId > 0)): ?>
            <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin:10px 0 4px;">
                <?php if ($isGuide): ?><a href="module_edit.php?id=<?= (int) $module['id'] ?>" class="btn btn-create" style="text-decoration:none;">✏️ Modifier le guide</a><?php endif; ?>
                <?php if ($quizModuleId > 0): ?><a href="module_quiz.php?id=<?= $quizModuleId ?>" class="btn btn-create" style="text-decoration:none; background:#2d5a37; color:#fff;">📝 Contrôler le quiz</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($isAdmin || $canContribHere): ?>
        <div class="admin-actions">
            <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-create" onclick="document.getElementById('editModal').style.display='flex';">✏️ Modifier ce module</button>
            <?php endif; ?>
            <?php if ($isContainer): ?>
                <button type="button" class="btn btn-create" onclick="document.getElementById('createModal').style.display='flex';">➕ Ajouter un sous-module</button>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
        <div style="color:#fff; background:rgba(0,0,0,0.3); padding:8px 14px; border-radius:10px; font-size:0.85rem; margin-top:8px;">ℹ️ La suppression se fait dans ⚙️ Paramètres → Gestion des modules.</div>

        <!-- Modale : modifier ce module -->
        <div id="editModal" class="modal-backdrop">
            <div class="modal-card">
                <h3>Modifier ce module</h3>
                <form method="POST" action="module_save.php" enctype="multipart/form-data" onsubmit="return confirm('Enregistrer les modifications ?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                    <input type="hidden" name="return" value="module.php?id=<?= (int) $module['id'] ?>">
                    <?php renderModuleFields('medit', $module, moduleProfiles($db), moduleIconChoices()); ?>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-cancel" onclick="document.getElementById('editModal').style.display='none';">Annuler</button>
                        <button type="submit" class="btn btn-create">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isContainer): ?>
        <!-- Modale : ajouter un sous-module -->
        <div id="createModal" class="modal-backdrop">
            <div class="modal-card">
                <h3>Nouveau sous-module</h3>
                <form method="POST" action="module_save.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="parent_id" value="<?= (int) $module['id'] ?>">
                    <input type="hidden" name="return" value="module.php?id=<?= (int) $module['id'] ?>">
                    <?php renderModuleFields('mcreate', [], moduleProfiles($db), moduleIconChoices()); ?>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-cancel" onclick="document.getElementById('createModal').style.display='none';">Annuler</button>
                        <button type="submit" class="btn btn-create">Créer</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($children)): ?>
        <!-- Édition d'un sous-module par CLIC DROIT sur sa tuile : une modale par enfant. -->
        <?php foreach ($children as $child): ?>
        <div id="editModal_<?= (int) $child['id'] ?>" class="modal-backdrop">
            <div class="modal-card">
                <h3>Modifier « <?= htmlspecialchars(moduleNom($child)) ?> »</h3>
                <?php if (!empty($child['is_locked'])): ?>
                    <div style="background:#fff8e1; border:1px solid #ffe082; color:#6a5400; padding:10px 12px; border-radius:10px; font-weight:700; font-size:0.86rem;">🔒 Sous-module verrouillé — déverrouillez-le dans la Gestion des modules pour le modifier.</div>
                    <div class="modal-actions"><button type="button" class="btn btn-cancel" onclick="document.getElementById('editModal_<?= (int) $child['id'] ?>').style.display='none';">Fermer</button></div>
                <?php else: ?>
                <form method="POST" action="module_save.php" enctype="multipart/form-data" onsubmit="return confirm('Enregistrer les modifications ?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $child['id'] ?>">
                    <input type="hidden" name="return" value="module.php?id=<?= (int) $module['id'] ?>">
                    <?php renderModuleFields('mchild' . (int) $child['id'], $child, moduleProfiles($db), moduleIconChoices()); ?>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-cancel" onclick="document.getElementById('editModal_<?= (int) $child['id'] ?>').style.display='none';">Annuler</button>
                        <button type="submit" class="btn btn-create">Enregistrer</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Menu contextuel (clic droit / appui long sur une tuile) -->
        <div id="tileCtx" style="position:fixed; z-index:100000; display:none; background:#fff; border:1px solid #d0d7d2; border-radius:10px; box-shadow:0 10px 34px rgba(0,0,0,.2); padding:6px; min-width:190px;">
            <button type="button" data-act="edit" style="display:block; width:100%; text-align:left; border:none; background:none; padding:9px 12px; border-radius:7px; cursor:pointer; font-weight:600; color:#244230;">✏️ Modifier</button>
            <button type="button" data-act="open" style="display:block; width:100%; text-align:left; border:none; background:none; padding:9px 12px; border-radius:7px; cursor:pointer; font-weight:600; color:#244230;">➡ Ouvrir</button>
        </div>
        <script>
        (function () {
            var menu = document.getElementById('tileCtx');
            if (!menu) { return; }
            var curId = null, curHref = null;
            function show(x, y) {
                menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
                menu.style.top = Math.min(y, window.innerHeight - 110) + 'px';
                menu.style.display = 'block';
            }
            function hide() { menu.style.display = 'none'; curId = null; curHref = null; }
            document.querySelectorAll('.mod-tile').forEach(function (tile) {
                tile.addEventListener('contextmenu', function (e) {
                    e.preventDefault();
                    curId = tile.getAttribute('data-mod-id');
                    curHref = tile.getAttribute('href') || ('module.php?id=' + curId);
                    show(e.clientX, e.clientY);
                });
                var t;
                tile.addEventListener('touchstart', function () { t = setTimeout(function () { tile._sup = true; curId = tile.getAttribute('data-mod-id'); curHref = tile.getAttribute('href') || ('module.php?id=' + curId); var r = tile.getBoundingClientRect(); show(r.left, r.bottom); }, 500); }, { passive: true });
                tile.addEventListener('touchend', function () { clearTimeout(t); });
                tile.addEventListener('touchmove', function () { clearTimeout(t); });
                tile.addEventListener('click', function (e) { if (tile._sup) { e.preventDefault(); tile._sup = false; } });
            });
            menu.querySelector('[data-act=edit]').addEventListener('click', function () {
                if (curId) { var m = document.getElementById('editModal_' + curId); if (m) { m.style.display = 'flex'; } }
                hide();
            });
            menu.querySelector('[data-act=open]').addEventListener('click', function () { if (curHref) { window.location = curHref; } });
            document.addEventListener('click', function (e) { if (menu.style.display === 'block' && !menu.contains(e.target)) { hide(); } });
            window.addEventListener('scroll', hide, true);
        })();
        </script>
        <?php endif; ?>
        <?php endif; ?>

        <?php
            // ── ÉVALUATION + HISTORIQUE (la formation = le module parent, dès qu'elle a du contenu)
            $canManageEval = ($isAdmin || !empty($canContribHere));
            if ($canManageEval && $hasAnyContent && !$isContentChild):
                require_once __DIR__ . '/includes/evaluation.php';
                require_once __DIR__ . '/includes/versions.php';
                require_once __DIR__ . '/includes/ui_switch.php';
                famiSwitchCss();
                $ev = evalStatus($db, (int) $module['id']);
                $vers = $isAdmin ? versionsList($db, (int) $module['id']) : [];
                $regenMsg = $ev['nb'] > 0
                    ? "Régénérer le quiz ? L'actuel sera remplacé, et tes corrections manuelles perdues."
                    : "Générer le quiz à partir du guide et de la vidéo ?";
        ?>
        <details class="content-card evalfold" style="max-width:900px; text-align:left; margin:22px auto;">
            <summary>
                📝 Évaluation de la formation
                <span class="eval-badge<?= $ev['on'] ? ' on' : '' ?>"><?= $ev['on'] ? t('Activée', 'Actief') : t('Désactivée', 'Uit') ?></span>
            </summary>
            <div style="padding-top:14px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; padding-bottom:12px; border-bottom:1px solid #e6efe9;">
                <span style="font-weight:700; color:#244230;"><?= t('Cette formation est évaluée', 'Deze opleiding wordt geëvalueerd') ?></span>
                <form method="POST" action="module_save.php" style="margin:0; line-height:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="eval_toggle">
                    <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                    <label class="fsw">
                        <input type="checkbox" name="eval_on" value="1" <?= $ev['on'] ? 'checked' : '' ?> onchange="if(!confirm(this.checked ? <?= htmlspecialchars(json_encode(t('Activer l\'évaluation de cette formation ?', 'De evaluatie van deze opleiding inschakelen?')), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('Désactiver l\'évaluation ? Le quiz est conservé mais ne sera plus proposé aux apprenants.', 'Evaluatie uitschakelen? De quiz blijft bewaard maar wordt niet meer aangeboden.')), ENT_QUOTES) ?>)){this.checked=!this.checked;return;} this.form.submit();">
                        <span class="fsw-track"></span>
                    </label>
                </form>
            </div>

            <p style="color:#7a8a80; margin:12px 0;">
                <?php if ($ev['on']): ?>
                    Les apprenants passent un quiz à la fin de cette formation.
                <?php else: ?>
                    Cette formation n'est <strong>pas évaluée</strong> : aucun quiz n'est proposé.
                    <?php if ($ev['nb'] > 0): ?> Son quiz est <strong>conservé</strong> et repart dès que tu réactives l'évaluation.<?php endif; ?>
                <?php endif; ?>
            </p>

            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <?php if ($ev['nb'] > 0): ?>
                    <span class="pill-quiz">📝 <?= (int) $ev['nb'] ?> question<?= $ev['nb'] > 1 ? 's' : '' ?></span>
                    <a class="btn" style="background:#eef7f0; color:#2d5a37; border:1px solid #cfe3d5;" href="module_quiz.php?id=<?= (int) $ev['module_id'] ?>">✏️ Contrôler le quiz</a>
                <?php else: ?>
                    <span class="pill-quiz" style="background:#fff3e0; border-color:#f0d089; color:#8a5a00;">Aucun quiz</span>
                <?php endif; ?>

                <form method="POST" action="module_save.php" style="margin:0;" data-fee onsubmit="return confirm(<?= htmlspecialchars(json_encode($regenMsg, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="eval_generate">
                    <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                    <button type="submit" class="btn btn-create">🤖 <?= $ev['nb'] > 0 ? 'Régénérer le quiz' : 'Générer le quiz' ?></button>
                </form>

                <?php if ($ev['nb'] > 0 && $isAdmin): ?>
                    <button type="button" class="btn" style="background:#fdecec; color:#b3261e;" onclick="document.getElementById('delQuizModal').style.display='flex';">🗑️ Supprimer le quiz</button>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin && !empty($vers)): ?>
            <details class="verfold" style="margin-top:18px;">
                <summary>🕘 Historique des versions <span style="font-weight:400; color:#7a8a80;">— <?= count($vers) ?> archivée<?= count($vers) > 1 ? 's' : '' ?></span></summary>
                <div style="padding:8px 14px 14px;">
                    <p style="color:#7a8a80; font-size:.82rem; margin:0 0 10px;">Chaque remplacement de contenu archive la version précédente (guide, quiz, PDF, vidéo). Restaurer une version remet son contenu en place — l'état actuel est archivé au passage, donc rien n'est jamais perdu.</p>
                    <?php foreach ($vers as $v): $vq = versionQuizNb($v); ?>
                    <div class="ver-row">
                        <div>
                            <strong><?= htmlspecialchars(date('d/m/Y à H:i', strtotime((string) $v['created_at']))) ?></strong>
                            <div style="color:#7a8a80; font-size:.82rem;">
                                <?= htmlspecialchars(trim((string) (($v['prenom'] ?? '') . ' ' . ($v['unom'] ?? ''))) ?: 'auteur inconnu') ?>
                                · <?= $vq > 0 ? ((int) $vq) . ' question' . ($vq > 1 ? 's' : '') : 'sans quiz' ?>
                                <?= !empty($v['pdf_path']) ? ' · 📄 PDF' : '' ?><?= (!empty($v['video_path']) || !empty($v['video_src_path'])) ? ' · 🎬 vidéo' : '' ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <form method="POST" action="module_save.php" style="margin:0;" data-fee onsubmit="return confirm('Restaurer cette version ? Le contenu actuel sera archivé, puis remplacé.');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="version_restore">
                                <input type="hidden" name="version_id" value="<?= (int) $v['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                                <button type="submit" class="btn" style="background:#eef7f0; color:#2d5a37; border:1px solid #cfe3d5;">↩ Restaurer</button>
                            </form>
                            <form method="POST" action="module_save.php" style="margin:0;" onsubmit="return confirm('Supprimer définitivement cette version et ses fichiers ?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="version_delete">
                                <input type="hidden" name="version_id" value="<?= (int) $v['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                                <button type="submit" class="btn" style="background:#fdecec; color:#b3261e;">🗑️</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
            </div>
        </details>

        <?php if ($ev['nb'] > 0 && $isAdmin): ?>
        <div id="delQuizModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">🗑️</div>
                <div class="fc-modal-title">Supprimer le quiz ?</div>
                <div class="fc-modal-text">Ses <strong><?= (int) $ev['nb'] ?> questions</strong> et vos corrections manuelles seront <strong>perdues</strong>. Mot de passe admin requis.</div>
                <form method="POST" action="module_save.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="eval_delete_quiz">
                    <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                    <input type="password" name="admin_password" placeholder="Mot de passe admin" required style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; margin-bottom:10px;">
                    <div class="fc-modal-actions">
                        <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="document.getElementById('delQuizModal').style.display='none';">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <style>
        .pill-quiz { display:inline-block; background:#eef7f0; border:1px solid #cfe3d5; color:#2d5a37; border-radius:999px; padding:7px 14px; font-weight:700; font-size:.86rem; }
        /* Bloc evaluation : repliable (evite les manips accidentelles sur le switch). */
        .evalfold > summary { cursor:pointer; list-style:none; font-weight:800; color:#2d5a37; font-size:1.1rem; display:flex; align-items:center; gap:10px; }
        .evalfold > summary::-webkit-details-marker { display:none; }
        .evalfold > summary::before { content:'B8'; color:#3E8E4E; transition:transform .15s; }
        .evalfold[open] > summary::before { transform:rotate(90deg); }
        .eval-badge { margin-left:auto; font-size:.72rem; font-weight:800; border-radius:999px; padding:3px 10px; background:#eef1ef; color:#8a968f; }
        .eval-badge.on { background:#2d5a37; color:#fff; }
        .verfold { border:1px solid #dde7e1; border-radius:12px; background:#fbfdfc; }
        .verfold > summary { cursor:pointer; padding:12px 14px; font-weight:700; color:#244230; list-style:none; }
        .verfold > summary::-webkit-details-marker { display:none; }
        .verfold > summary::before { content:'\25B8'; display:inline-block; margin-right:8px; color:#3E8E4E; transition:transform .15s; }
        .verfold[open] > summary::before { transform:rotate(90deg); }
        .ver-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
                   background:#fff; border:1px solid #e6efe9; border-radius:10px; padding:10px 12px; margin-bottom:8px; }
        </style>
        <?php endif; ?>

        <?php if ($showContentForm): // $existPdf/$existVideo/$existVideoProc/$hasAnyContent calculés en tête de page ?>
        <div class="content-card add-content" style="max-width:900px; text-align:left; margin:26px auto;<?= $hasAnyContent ? '' : ' border:2px solid #bfe0c8; box-shadow:0 12px 34px rgba(30,90,55,.14);' ?>">
            <?php if ($hasAnyContent): ?>
                <button type="button" id="editContentBtn" onclick="toggleContentForm()" style="width:100%; border:none; background:linear-gradient(180deg,#eef7f0,#e0efe3); color:#2d5a37; font-weight:800; font-size:1.05rem; padding:16px; border-radius:12px; cursor:pointer;">🔄 Remplacer le contenu <span id="editContentCaret" style="opacity:.6;">▾</span></button>
            <?php else: ?>
                <h3 style="margin-top:0; color:#2d5a37; font-size:1.35rem;">📎 Importer des fichiers</h3>
                <p style="color:#666; margin:-4px 0 12px;">Déposez votre <strong>document</strong> et/ou votre <strong>vidéo</strong>. À la validation : « Guide » pour le document, « Vidéo » pour la vidéo.</p>
            <?php endif; ?>
            <div id="contentFormWrap"<?= $hasAnyContent ? ' style="display:none; margin-top:16px;"' : '' ?>>
            <?php if ($hasAnyContent): ?>
                <p style="color:#666; margin:0 0 12px;">Déposez un nouveau fichier pour <strong>remplacer</strong> celui d'à côté. Un remplacement crée une <strong>nouvelle version</strong> : le guide relu, sa traduction et le quiz sont refaits à partir du nouveau contenu, et la formation repasse par la relecture avant d'être republiée.</p>
            <?php endif; ?>
            <form id="contentForm" method="POST" action="module_save.php" enctype="multipart/form-data" onsubmit="return validateContent(event);">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="content">
                <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                <input type="hidden" name="return" value="module.php?id=<?= (int) $module['id'] ?>">

                <?php if (!empty($module['is_locked'])): ?>
                    <div style="background:#fff8e1; border:1px solid #ffe082; color:#6a5400; padding:10px 12px; border-radius:10px; font-weight:700; font-size:0.86rem;">🔒 Module verrouillé — mot de passe requis pour enregistrer.</div>
                    <label style="display:block; font-weight:700; color:#244230; margin:12px 0 4px;">Mot de passe de verrouillage</label>
                    <input type="password" name="admin_password" required autocomplete="off" placeholder="Mot de passe de verrouillage" style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
                <?php endif; ?>

                <!-- Les deux zones de dépôt, côte à côte et de même hauteur -->
                <div class="dz-cols">
                    <div>
                        <div class="drop-zone" id="dz_pdf" data-ext="pdf" data-max="30" data-what="document" data-has-existing="<?= !empty($module['pdf_path']) ? '1' : '0' ?>" data-remove="remove_pdf">
                            <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="dz-input">
                            <div class="dz-icon">📄</div>
                            <div class="dz-title">Guide</div>
                            <div class="dz-hint">Glissez votre document ici ou cliquez pour parcourir<br><small style="color:#8a968f;"><strong>PDF uniquement</strong> · jusqu'à 30 Mo<br>PowerPoint et Word ne sont pas acceptés : exportez-les en PDF (Fichier → Exporter → PDF).</small></div>
                            <div class="dz-file" hidden></div>
                        </div>
                        <?php if ($existPdf): ?>
                            <button type="button" class="file-chip" onclick="fileMenu('📄 Document', '<?= htmlspecialchars(moduleFileUrl($existPdf), ENT_QUOTES) ?>', 'remove_pdf')">
                                📄 <span>Document actuel</span> <small>cliquer pour télécharger ou supprimer</small>
                            </button>
                            <label class="chk" hidden><input type="checkbox" name="remove_pdf" id="rm_remove_pdf" value="1"></label>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="drop-zone" id="dz_video" data-ext="mp4,mov" data-max="1024" data-what="vidéo" data-has-existing="<?= !empty($module['video_path']) ? '1' : '0' ?>" data-remove="remove_video">
                            <input type="file" name="video_file" accept="video/mp4,video/quicktime,.mp4,.mov" class="dz-input">
                            <div class="dz-icon">🎬</div>
                            <div class="dz-title">Vidéo</div>
                            <div class="dz-hint">Glissez votre vidéo ici ou cliquez pour parcourir<br><small style="color:#8a968f;">MP4 ou MOV · jusqu'à 1 Go · <strong>format 16:9 conseillé</strong><br>Une vidéo verticale (9:16) laisse des bandes sur les côtés : elles sont habillées par l'image définie dans Paramètres → Créateur.</small></div>
                            <div class="dz-file" hidden></div>
                        </div>

                        <?php
                            // SOUS-TITRES — CACHÉS par défaut.
                            // Le site transcrit la vidéo tout seul (Whisper) : personne n'a besoin de
                            // fabriquer un .srt, et afficher ce champ ne fait qu'embrouiller.
                            // Il reste accessible à ceux qui EN ONT DÉJÀ un : il suffit de taper
                            // « srt » au clavier dans le formulaire pour le faire apparaître.
                            // (Il se montre aussi tout seul si un .srt est déjà attaché au module.)
                        ?>
                        <div class="drop-zone drop-zone--slim" id="dz_srt" data-ext="srt,vtt" data-max="5" data-what="sous-titres" hidden data-has-existing="<?= !empty($module['sub_src_path']) ? '1' : '0' ?>" data-remove="remove_srt" title="Facultatif : le site génère et traduit les sous-titres tout seul. Déposez un .srt seulement si vous en avez déjà un.">
                            <input type="file" name="srt_file" accept=".srt,.vtt,text/plain" class="dz-input">
                            <div class="dz-icon">💬</div>
                            <div class="dz-title">Sous-titres <span style="font-weight:400; color:#8a968f;">.srt · facultatif</span></div>
                            <div class="dz-hint">générés tout seuls sinon</div>
                            <div class="dz-file" hidden></div>
                        </div>
                        <script>
                        // Raccourci caché : taper « srt » (hors champ de saisie) ouvre la zone de dépôt.
                        // Le retaper la referme — et vide le fichier choisi, pour ne jamais envoyer
                        // en douce un .srt que l'on ne voit plus à l'écran.
                        (function () {
                            var dz = document.getElementById('dz_srt');
                            if (!dz) { return; }
                            if (dz.getAttribute('data-has-existing') === '1') { dz.hidden = false; return; }
                            var buf = '';
                            document.addEventListener('keydown', function (e) {
                                var t = e.target;
                                if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) { return; }
                                if (!e.key || e.key.length !== 1) { return; }
                                buf = (buf + e.key.toLowerCase()).slice(-3);
                                if (buf !== 'srt') { return; }
                                buf = '';

                                if (dz.hidden) {
                                    dz.hidden = false;
                                    dz.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                                    dz.animate(
                                        [{ boxShadow: '0 0 0 0 rgba(62,142,78,.6)' }, { boxShadow: '0 0 0 12px rgba(62,142,78,0)' }],
                                        { duration: 700, iterations: 2 }
                                    );
                                } else {
                                    var inp = dz.querySelector('.dz-input');
                                    if (inp) { inp.value = ''; }
                                    var shown = dz.querySelector('.dz-file');
                                    if (shown) { shown.hidden = true; shown.textContent = ''; }
                                    dz.hidden = true;
                                }
                            });
                        }());
                        </script>
                        <?php if (!empty($module['sub_src_path'])): ?>
                            <button type="button" class="file-chip" onclick="fileMenu('💬 Sous-titres', '<?= htmlspecialchars(moduleFileUrl($module['sub_src_path']), ENT_QUOTES) ?>', 'remove_srt')">
                                💬 <span>Sous-titres fournis</span> <small>cliquer pour télécharger ou supprimer</small>
                            </button>
                            <label class="chk" hidden><input type="checkbox" name="remove_srt" id="rm_remove_srt" value="1"></label>
                        <?php endif; ?>
                        <?php if ($existVideo): ?>
                            <button type="button" class="file-chip" onclick="fileMenu('🎬 Vidéo', '<?= htmlspecialchars(moduleFileUrl($existVideo), ENT_QUOTES) ?>', 'remove_video')">
                                🎬 <span>Vidéo actuelle</span> <small>cliquer pour télécharger ou supprimer</small>
                            </button>
                            <label class="chk" hidden><input type="checkbox" name="remove_video" id="rm_remove_video" value="1"></label>
                        <?php elseif ($existVideoProc): ?>
                            <div class="dz-existing">🎬 <span style="color:#8a5a00;">Vidéo en préparation…</span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="quiz-opt">
                    <input type="checkbox" name="a_evaluer" value="1" <?= !empty($module['a_evaluer']) ? 'checked' : '' ?>>
                    <span>
                        <strong>📝 Générer un quiz</strong>
                        <small>L'IA crée les questions à partir du document et de la vidéo. Tu les reliras avant publication.</small>
                    </span>
                </label>

                <p style="font-size:0.82rem; color:#777; margin-top:16px;">« <?= $hasAnyContent ? 'Remplacer' : 'Valider' ?> et uniformiser » : l'IA lit le document et construit la belle page « Guide » (au lieu de l'afficher brut).</p>
                <div style="display:flex; gap:10px; margin-top:6px; flex-wrap:wrap;">
                    <button type="submit" name="uniformize" value="0" class="btn" style="background:#e9ecef; color:#333;"><?= $hasAnyContent ? 'Remplacer' : 'Valider' ?></button>
                    <button type="submit" name="uniformize" value="1" class="btn btn-create"><?= $hasAnyContent ? 'Remplacer et uniformiser' : 'Valider et uniformiser' ?></button>
                </div>
            </form>

            <!-- VOIE 2 — CRÉER UN GUIDE : autre point de départ (page blanche), donc HORS du
                 formulaire d'import. On garde la même carte, mais séparée nettement. -->
            <div style="border-top:2px dashed #cfe0d4; margin:20px 0 0; padding-top:18px; text-align:center;">
                <div style="display:inline-block; background:#f4f7f6; color:#8a968f; font-weight:800; font-size:.78rem; letter-spacing:.08em; padding:3px 12px; border-radius:999px; margin-bottom:10px;">OU</div>
                <h3 style="margin:0 0 4px; color:#2d5a37;">✍️ Créer un guide</h3>
                <p style="color:#666; margin:0 0 12px; font-size:.9rem;">Sans aucun fichier : tu écris la formation directement dans l'éditeur.</p>
                <button type="button" class="btn btn-create" onclick="document.getElementById('createGuideModal').style.display='flex';">✍️ Créer un guide</button>
            </div>
            </div>
        </div>
        <!-- Modale : créer un guide de zéro (avec rappel du choix d'évaluation) -->
        <div id="createGuideModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">✍️</div>
                <div class="fc-modal-title">Créer un guide ?</div>
                <div class="fc-modal-text">Un guide vierge sera créé et tu arrives <strong>directement dans l'éditeur</strong>. À la <strong>validation</strong> : si tu as coché « Générer un quiz », l'IA le construit à partir de ce que tu as écrit et tu le relis ; sinon elle corrige l'orthographe et traduit dans l'autre langue, puis publie.</div>
                <form method="POST" action="module_save.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_blank_guide">
                    <input type="hidden" name="id" value="<?= (int) $module['id'] ?>">
                    <?php if (!empty($module['is_locked'])): ?>
                        <input type="password" name="admin_password" placeholder="Mot de passe de verrouillage" required style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px; margin-bottom:10px;">
                    <?php endif; ?>
                    <label class="chk" style="display:flex; align-items:center; gap:10px; justify-content:center; background:#f4f7f6; border-radius:10px; padding:12px; margin:0 0 12px; font-weight:700; color:#244230;">
                        <input type="checkbox" name="a_evaluer" value="1"> 📝 Générer un quiz <small style="font-weight:400; color:#777;">(à partir de ce que tu auras écrit)</small>
                    </label>
                    <div class="fc-modal-actions">
                        <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="document.getElementById('createGuideModal').style.display='none';">Annuler</button>
                        <button type="submit" class="btn btn-create">✍️ Créer le guide</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Modale : le remplacement va effacer le quiz existant -->
        <div id="quizLossModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">🗑️</div>
                <div class="fc-modal-title">L'ancien quiz va être supprimé</div>
                <div class="fc-modal-text">
                    Cette formation a un quiz de <strong><?= (int) $existingQuizNb ?> question<?= $existingQuizNb > 1 ? 's' : '' ?></strong>.
                    Comme vous remplacez le contenu et que <strong>« Générer un quiz » n'est pas coché</strong>,
                    ce quiz sera <strong>définitivement effacé</strong> : la formation ne sera plus évaluée,
                    et vos corrections manuelles seront perdues.<br><br>
                    Pour le remplacer par un quiz portant sur le nouveau contenu, cochez « Générer un quiz ».
                </div>
                <div class="fc-modal-actions" style="flex-direction:column; gap:8px;">
                    <button type="button" class="btn btn-create" style="width:100%; box-sizing:border-box;" onclick="qlKeepQuiz()">📝 Cocher « Générer un quiz » et continuer</button>
                    <button type="button" class="btn" style="width:100%; background:#fdecec; color:#b3261e;" onclick="qlDropQuiz()">Continuer sans quiz (l'ancien sera perdu)</button>
                    <button type="button" class="btn" style="width:100%; background:#e9ecef; color:#333;" onclick="document.getElementById('quizLossModal').style.display='none';">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Modale : que faire du fichier déjà présent ? -->
        <div id="fileMenuModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">📎</div>
                <div class="fc-modal-title" id="fmTitle">Fichier</div>
                <div class="fc-modal-text">Que voulez-vous faire de ce fichier&nbsp;?</div>
                <div class="fc-modal-actions" style="flex-direction:column; gap:8px;">
                    <a id="fmDownload" class="btn btn-create" href="#" download style="width:100%; box-sizing:border-box;">⤓ Télécharger</a>
                    <button type="button" class="btn" style="width:100%; background:#fdecec; color:#b3261e;" onclick="fmDelete()">🗑️ Supprimer</button>
                    <button type="button" class="btn" style="width:100%; background:#e9ecef; color:#333;" onclick="document.getElementById('fileMenuModal').style.display='none';">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Modale : la vidéo déposée n'a pas de son -->
        <div id="noAudioModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">🔇</div>
                <div class="fc-modal-title">Cette vidéo n'a pas de son</div>
                <div class="fc-modal-text">
                    Aucune piste audio détectée. Sans son, <strong>aucun sous-titre ne pourra être généré</strong>,
                    et le quiz ne s'appuiera que sur le document écrit.<br><br>
                    Voulez-vous quand même poursuivre avec cette vidéo&nbsp;?
                </div>
                <div class="fc-modal-actions">
                    <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="naCancel()">Choisir une autre vidéo</button>
                    <button type="button" class="btn btn-create" onclick="document.getElementById('noAudioModal').style.display='none';">Oui, poursuivre</button>
                </div>
            </div>
        </div>

        <!-- Modale : format ou poids refuse (le fichier n'est JAMAIS envoye) -->
        <div id="fcRejectModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">🚫</div>
                <div class="fc-modal-title" id="fcRejectTitle">Format non accepté</div>
                <div class="fc-modal-text" id="fcRejectText"></div>
                <div class="fc-modal-actions">
                    <button type="button" class="btn btn-create" onclick="document.getElementById('fcRejectModal').style.display='none';">J'ai compris</button>
                </div>
            </div>
        </div>

        <!-- Modale : aucun fichier -->
        <div id="fileErrorModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">📎</div>
                <div class="fc-modal-title">Fichier manquant</div>
                <div class="fc-modal-text">Il faut au moins <strong>1 fichier</strong> (PDF ou vidéo) pour enregistrer du contenu.</div>
                <div class="fc-modal-actions">
                    <button type="button" class="btn btn-create" onclick="document.getElementById('fileErrorModal').style.display='none';">J'ai compris</button>
                </div>
            </div>
        </div>
        <!-- Modale : un seul fichier sur deux -->
        <div id="fileWarnModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">⚠️</div>
                <div class="fc-modal-title">Un seul fichier sur deux</div>
                <div class="fc-modal-text">Vous n'avez ajouté qu'<strong>un seul fichier sur les deux</strong>. Votre contenu ne portera que sur celui-ci.<br>Voulez-vous continuer ?</div>
                <div class="fc-modal-actions">
                    <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="document.getElementById('fileWarnModal').style.display='none';">Annuler</button>
                    <button type="button" class="btn btn-create" onclick="fcConfirmContent();">Oui, continuer</button>
                </div>
            </div>
        </div>
        <!-- Modale : confirmation « Valider et uniformiser » (avec rappel du choix quiz) -->
        <div id="uniConfirmModal" class="fc-modal">
            <div class="fc-modal-box">
                <div class="fc-modal-icon">🪄</div>
                <div class="fc-modal-title">Uniformiser le contenu ?</div>
                <div class="fc-modal-text">L'IA va lire le document et construire la belle page « Guide ». Vérifie ton choix ci-dessous.</div>
                <label class="chk" style="display:flex; align-items:center; gap:10px; justify-content:center; background:#f4f7f6; border-radius:10px; padding:12px; margin:0 0 8px; font-weight:700; color:#244230;">
                    <input type="checkbox" id="uniAEval"> 📝 Ce contenu est à évaluer <small style="font-weight:400; color:#777;">(un quiz sera généré)</small>
                </label>
                <div id="uniOneFileNote" style="display:none; color:#8a5a00; font-size:.85rem; margin-bottom:8px;">⚠️ Un seul fichier sur deux — le contenu ne portera que sur celui-ci.</div>
                <div class="fc-modal-actions">
                    <button type="button" class="btn" style="background:#e9ecef; color:#333;" onclick="document.getElementById('uniConfirmModal').style.display='none';">Annuler</button>
                    <button type="button" class="btn btn-create" onclick="uniConfirm();">✅ Confirmer</button>
                </div>
            </div>
        </div>
        <style>
        .fc-modal { position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; padding:20px; }
        .fc-modal-box { background:#fff; border-radius:16px; padding:28px; max-width:440px; width:100%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.35); animation:fcIn .25s ease; }
        @keyframes fcIn { from { opacity:0; transform:scale(.92) translateY(10px); } to { opacity:1; transform:none; } }
        .fc-modal-icon { font-size:3rem; margin-bottom:8px; }
        .fc-modal-title { font-size:1.35rem; font-weight:800; color:#2d5a37; margin-bottom:8px; }
        .fc-modal-text { color:#555; line-height:1.55; margin-bottom:22px; }
        .fc-modal-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
        </style>
        <script>
        (function () {
            var zones = document.querySelectorAll('#contentForm .drop-zone');
            for (var i = 0; i < zones.length; i++) {
                (function (dz) {
                    var input = dz.querySelector('.dz-input');
                    var label = dz.querySelector('.dz-file');
                    input.addEventListener('change', function () {
                        if (input.files && input.files.length) {
                            // REFUS IMMEDIAT du mauvais format / du fichier trop lourd :
                            // on n'envoie rien au serveur, on explique, on vide le champ.
                            var f = input.files[0];
                            var exts = (dz.getAttribute('data-ext') || '').split(',');
                            var maxMo = parseInt(dz.getAttribute('data-max') || '0', 10);
                            var what = dz.getAttribute('data-what') || 'fichier';
                            var ext = (f.name.split('.').pop() || '').toLowerCase();
                            var okExt = exts.indexOf(ext) !== -1;
                            var okSize = !maxMo || f.size <= maxMo * 1024 * 1024;
                            if (!okExt || !okSize) {
                                input.value = '';
                                label.hidden = true;
                                dz.classList.remove('has-file');
                                fcReject(what, ext, exts, maxMo, okExt);
                                return;
                            }
                            label.textContent = '✓ ' + f.name;
                            label.hidden = false;
                            dz.classList.add('has-file');
                            if (dz.id === 'dz_video') { naCheck(f); }   // vidéo muette ?
                        } else {
                            label.hidden = true;
                            dz.classList.remove('has-file');
                        }
                    });
                    ['dragenter', 'dragover'].forEach(function (ev) {
                        dz.addEventListener(ev, function () { dz.classList.add('over'); });
                    });
                    ['dragleave', 'drop'].forEach(function (ev) {
                        dz.addEventListener(ev, function () { dz.classList.remove('over'); });
                    });
                })(zones[i]);
            }
        })();
        // Fichier déjà présent : on propose de le télécharger OU de le supprimer (avec
        // confirmation). Supprimer coche la case cachée correspondante puis enregistre.
        var fmField = '';
        function fileMenu(title, url, field) {
            fmField = field;
            document.getElementById('fmTitle').textContent = title;
            document.getElementById('fmDownload').href = url;
            document.getElementById('fileMenuModal').style.display = 'flex';
        }
        function fmDelete() {
            if (!confirm('Supprimer ce fichier définitivement ? Cette action est irréversible.')) { return; }
            var box = document.getElementById('rm_' + fmField);
            if (box) { box.checked = true; }
            document.getElementById('fileMenuModal').style.display = 'none';
            document.getElementById('contentForm').submit();
        }

        // La vidéo a-t-elle du son ? On la lit dans le navigateur (rien n'est envoyé au serveur)
        // et on interroge les indicateurs de piste audio. Ils diffèrent selon les navigateurs ;
        // si AUCUN ne répond, on se tait plutôt que d'alerter à tort.
        function naCheck(file) {
            var v = document.createElement('video');
            v.preload = 'metadata';
            v.muted = true;
            v.src = URL.createObjectURL(file);
            v.onloadeddata = function () {
                var known = false, hasAudio = false;
                if (typeof v.mozHasAudio === 'boolean') { known = true; hasAudio = v.mozHasAudio; }
                else if (v.audioTracks && typeof v.audioTracks.length === 'number') { known = true; hasAudio = v.audioTracks.length > 0; }
                else if (typeof v.webkitAudioDecodedByteCount === 'number') {
                    // Chrome ne remplit ce compteur qu'après un début de lecture.
                    v.play().then(function () {
                        setTimeout(function () {
                            v.pause();
                            if (v.webkitAudioDecodedByteCount === 0) { naWarn(); }
                            URL.revokeObjectURL(v.src);
                        }, 400);
                    }).catch(function () { URL.revokeObjectURL(v.src); });
                    return;
                }
                if (known && !hasAudio) { naWarn(); }
                URL.revokeObjectURL(v.src);
            };
            v.onerror = function () { URL.revokeObjectURL(v.src); };
        }
        function naWarn() { document.getElementById('noAudioModal').style.display = 'flex'; }
        function naCancel() {
            var dz = document.getElementById('dz_video');
            var input = dz.querySelector('.dz-input');
            var label = dz.querySelector('.dz-file');
            input.value = '';
            label.hidden = true;
            dz.classList.remove('has-file');
            document.getElementById('noAudioModal').style.display = 'none';
        }

        // Explique POURQUOI le fichier est refuse, et ce qu'il faut faire.
        function fcReject(what, ext, exts, maxMo, okExt) {
            var t = document.getElementById('fcRejectTitle');
            var m = document.getElementById('fcRejectText');
            if (!okExt) {
                t.textContent = 'Format non accepté';
                var msg = "Le fichier <strong>." + (ext || '?') + "</strong> n'est pas accepté pour le " + what
                        + ". Formats acceptés : <strong>" + exts.map(function (e) { return '.' + e; }).join(', ') + "</strong>.";
                if (['ppt', 'pptx', 'doc', 'docx', 'odt', 'odp'].indexOf(ext) !== -1) {
                    msg += '<br><br>Dans PowerPoint ou Word : <strong>Fichier → Enregistrer sous (ou Exporter) → PDF</strong>, puis redéposez le fichier ici.';
                }
                m.innerHTML = msg;
            } else {
                t.textContent = 'Fichier trop lourd';
                m.innerHTML = 'Ce ' + what + ' dépasse la taille maximale de <strong>'
                            + (maxMo >= 1024 ? (maxMo / 1024) + ' Go' : maxMo + ' Mo') + '</strong>.';
            }
            document.getElementById('fcRejectModal').style.display = 'flex';
        }
        function dzPresent(id) {
            var dz = document.getElementById(id);
            if (!dz) { return false; }
            var input = dz.querySelector('.dz-input');
            if (input && input.files && input.files.length) { return true; }
            if (dz.getAttribute('data-has-existing') === '1') {
                var rm = document.querySelector('input[name="' + dz.getAttribute('data-remove') + '"]');
                return !(rm && rm.checked);
            }
            return false;
        }
        var fcPendingUniformize = '0';
        // Le remplacement efface le quiz existant : on ne le laisse pas filer en silence.
        var QUIZ_NB = <?= (int) $existingQuizNb ?>;
        function qlKeepQuiz() {
            var fe = document.querySelector('#contentForm input[name="a_evaluer"]');
            if (fe) { fe.checked = true; }
            document.getElementById('quizLossModal').style.display = 'none';
            var _f = document.getElementById('contentForm');
            if (window.feeUpload) { window.feeUpload(_f); } else { _f.requestSubmit(qlSubmitter); }
        }
        function qlDropQuiz() {
            document.getElementById('quizLossModal').style.display = 'none';
            qlConfirmed = true;
            var _f2 = document.getElementById('contentForm');
            if (window.feeUpload) { window.feeUpload(_f2); } else { _f2.requestSubmit(qlSubmitter); }
        }
        var qlConfirmed = false;
        var qlSubmitter = null;

        function validateContent(e) {
            var n = (dzPresent('dz_pdf') ? 1 : 0) + (dzPresent('dz_video') ? 1 : 0);
            if (e && e.submitter && e.submitter.name === 'uniformize') {
                fcPendingUniformize = e.submitter.value;
            }
            if (n === 0) {
                document.getElementById('fileErrorModal').style.display = 'flex';
                return false;
            }
            var aEval = document.querySelector('#contentForm input[name="a_evaluer"]');
            if (QUIZ_NB > 0 && aEval && !aEval.checked && !qlConfirmed) {
                qlSubmitter = (e && e.submitter) ? e.submitter : null;
                document.getElementById('quizLossModal').style.display = 'flex';
                return false;
            }
            if (fcPendingUniformize === '1') {
                // Confirmation + rappel du choix quiz (au cas où on aurait oublié de cocher).
                var fe = document.querySelector('#contentForm input[name="a_evaluer"]');
                document.getElementById('uniAEval').checked = fe ? fe.checked : false;
                document.getElementById('uniOneFileNote').style.display = (n === 1) ? 'block' : 'none';
                document.getElementById('uniConfirmModal').style.display = 'flex';
                return false;
            }
            if (n === 1) {
                document.getElementById('fileWarnModal').style.display = 'flex';
                return false;
            }
            return true; // 2 fichiers, sans uniformisation : enregistrement direct
        }
        function uniConfirm() {
            var fe = document.querySelector('#contentForm input[name="a_evaluer"]');
            if (fe) { fe.checked = document.getElementById('uniAEval').checked; }
            document.getElementById('uniConfirmModal').style.display = 'none';
            var f = document.getElementById('contentForm');
            var h = document.createElement('input'); h.type = 'hidden'; h.name = 'uniformize'; h.value = '1'; f.appendChild(h);
            if (window.feeUpload) { window.feeUpload(f); } else { f.submit(); }
        }
        function toggleContentForm() {
            var w = document.getElementById('contentFormWrap');
            var c = document.getElementById('editContentCaret');
            if (!w) { return; }
            var hidden = (getComputedStyle(w).display === 'none');
            w.style.display = hidden ? 'block' : 'none';
            if (c) { c.textContent = hidden ? '▴' : '▾'; }
        }
        function fcConfirmContent() {
            document.getElementById('fileWarnModal').style.display = 'none';
            var f = document.getElementById('contentForm');
            var h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'uniformize';
            h.value = fcPendingUniformize;
            f.appendChild(h);
            if (window.feeUpload) { window.feeUpload(f); } else { f.submit(); }
        }
        </script>
        <?php endif; ?>

        <?= moduleFormScript() ?>
    <?php endif; ?>

    <?php if (!$isContainer && !empty($module['video_path'])): ?>
        <script src="/video-upload-lock.js" defer></script>
    <?php endif; ?>

    <?php if (!$isContainer && !empty($module['pdf_path'])): ?>
        <?php if (!empty($module['uniformized'])): ?>
        <script>
        (function () {
            var box = document.getElementById('uniPdf'); if (!box) { return; }
            var url = box.getAttribute('data-src');
            function load(s) { return new Promise(function (res, rej) { var sc = document.createElement('script'); sc.src = s; sc.onload = res; sc.onerror = rej; document.head.appendChild(sc); }); }
            load('https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/legacy/build/pdf.min.js').then(function () {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/legacy/build/pdf.worker.min.js';
                return window.pdfjsLib.getDocument(url).promise;
            }).then(function (pdf) {
                box.innerHTML = '';
                var chain = Promise.resolve();
                for (var i = 1; i <= pdf.numPages; i++) {
                    (function (p) {
                        chain = chain.then(function () {
                            return pdf.getPage(p).then(function (page) {
                                var avail = (box.clientWidth || 800) - 32;
                                var base = page.getViewport({ scale: 1 });
                                var dpr = Math.min(window.devicePixelRatio || 1, 2);
                                var vp = page.getViewport({ scale: (avail / base.width) * dpr });
                                var c = document.createElement('canvas');
                                c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
                                c.style.width = '100%'; c.style.height = 'auto'; c.style.display = 'block'; c.style.margin = '0 auto 12px'; c.style.borderRadius = '8px'; c.style.boxShadow = '0 2px 8px rgba(0,0,0,0.12)';
                                box.appendChild(c);
                                return page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
                            });
                        });
                    })(i);
                }
                return chain;
            }).catch(function () { box.innerHTML = '<div style="text-align:center"><a href="' + url + '">' + <?= json_encode(t('Ouvrir le document', 'Document openen')) ?> + '</a></div>'; });
        })();
        </script>
        <?php else: ?>
        <script src="/pdf-viewer.js" defer></script>
        <?php endif; ?>
    <?php endif; ?>
<script>
// Téléchargement vidéo : on PRÉPARE la fusion (intro + vidéo + outro) côté serveur — ça peut
// prendre des minutes la 1re fois (ré-encodage), donc la fée s'affiche — PUIS on télécharge.
// Sans intro/outro, la préparation est instantanée et on télécharge la vidéo seule.
function famiVideoDownload(btn) {
    var vid = btn.getAttribute('data-vid');
    if (!vid || vid === '0') { return; }
    if (window.feeIndef) { window.feeIndef(); }
    btn.disabled = true;
    fetch('video_download.php?id=' + encodeURIComponent(vid) + '&prepare=1', { credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
        .then(function (res) {
            if (window.feeHide) { window.feeHide(); }
            btn.disabled = false;
            if (res && res.ok === false) { alert(res.error || 'Téléchargement impossible.'); return; }
            // Fichier prêt (fusionné ou vidéo seule) : le navigateur lance le téléchargement.
            window.location = 'video_download.php?id=' + encodeURIComponent(vid);
        })
        .catch(function () {
            if (window.feeHide) { window.feeHide(); }
            btn.disabled = false;
            window.location = 'video_download.php?id=' + encodeURIComponent(vid); // repli : on tente le direct
        });
}
</script>
</body>
</html>
