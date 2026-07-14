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
        .badge-eval { display:inline-block; background:#2d5a37; color:#fff; font-size:0.78rem; font-weight:700; padding:4px 12px; border-radius:20px; margin-top:8px; }
        .tile .badge-eval { position:absolute; top:12px; right:12px; margin:0; }
        .content-card { background: rgba(255,255,255,0.96); border-radius: 18px; padding: 32px; width: 90%; max-width: 900px; margin: 30px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        /* Module vierge : le bloc « Ajout de contenu » remonte tout en haut (après le titre). */
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
        .dz-file { margin-top:8px; font-weight:700; color:#244230; word-break:break-all; }
        .dz-existing { font-size:0.85rem; color:#555; margin:4px 0 2px; }
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
        $canDlPdf = $uniHasPdf && pdfCanDownload($db, $uniRole, !empty($isAdmin));
        // Vidéo : téléchargement réglable dans Paramètres → Préférences (désactivé par défaut).
        $uniHasVideo = !empty($module['video_path']);
        $uniVideoUrl = $uniHasVideo ? moduleFileUrl($module['video_path']) : '';
        $canDlVideo = $uniHasVideo && function_exists('videoCanDownload') && videoCanDownload($db, $uniRole, !empty($isAdmin));
    ?>
    <?php
        require_once __DIR__ . '/includes/topbar.php';
        famiTopbar($db, [
            'back'  => !empty($module['parent_id']) ? 'module.php?id=' . (int) $module['parent_id'] : 'index.php',
            'title' => moduleNom($module),
        ]);
    ?>
    <div class="topbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <?php if ($canViewPdf || $canDlPdf || $canDlVideo): ?>
                <div class="uni-actions">
                    <?php if ($canViewPdf): ?><button type="button" id="uniEye" class="uni-ico" title="<?= t('Voir le PDF original', 'Originele PDF bekijken') ?>" onclick="window.uniTogglePdf && window.uniTogglePdf()">👁</button><?php endif; ?>
                    <?php if ($canDlPdf): ?><a class="uni-ico" href="<?= htmlspecialchars($uniPdfUrl) ?>" download title="<?= t('Télécharger le PDF original', 'Originele PDF downloaden') ?>">⤓</a><?php endif; ?>
                    <?php if ($canDlVideo): ?><a class="uni-ico" href="<?= htmlspecialchars($uniVideoUrl) ?>" download title="<?= t('Télécharger la vidéo', 'De video downloaden') ?>">🎬⤓</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php
                // Cloche de notifications avec pastille rouge : visible aussi hors de l'accueil.
                require_once __DIR__ . '/includes/events.php';
                $notifCount = $isAdmin
                    ? eventsPendingCount($db)
                    : eventsUnseenCount($db, (int) ($_SESSION['user_id'] ?? 0), $actorRole);
            ?>
            <a href="events.php" class="uni-ico" title="<?= t('Notifications', 'Meldingen') ?>" style="position:relative;">🔔<?php if ($notifCount > 0): ?><span style="position:absolute; top:-4px; right:-4px; background:#c0392b; color:#fff; border-radius:999px; font-size:0.68rem; font-weight:800; min-width:18px; height:18px; display:flex; align-items:center; justify-content:center; padding:0 5px; box-shadow:0 0 0 2px #fff;"><?= (int) $notifCount ?></span><?php endif; ?></a>
            <div class="lang-switch">
                <a href="module.php?id=<?= (int) $module['id'] ?>&lang=fr" class="lang-btn<?= currentLang() === 'fr' ? ' active' : '' ?>">FR</a>
                <a href="module.php?id=<?= (int) $module['id'] ?>&lang=nl" class="lang-btn<?= currentLang() === 'nl' ? ' active' : '' ?>">NL</a>
            </div>
        </div>
    </div>
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
                <div class="tile inactive<?= $isAdmin ? ' mod-tile' : '' ?>"<?= $isAdmin ? ' data-mod-id="' . (int) $child['id'] . '"' : '' ?> title="<?= $isAdmin ? 'Module inactif — clic droit pour modifier' : t('Module inactif — réactive-le dans Gestion des modules', 'Module niet actief — heractiveer hem in Modulebeheer') ?>" style="cursor:<?= $isAdmin ? 'context-menu' : 'not-allowed' ?>;">
                    <span class="badge-eval" style="background:#999;"><?= t('Inactif', 'Niet actief') ?></span>
                    <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                    <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars(moduleDesc($child)) ?></div>
                </div>
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

        <?php if ($showContentForm): // $existPdf/$existVideo/$existVideoProc/$hasAnyContent calculés en tête de page ?>
        <div class="content-card add-content" style="max-width:900px; text-align:left; margin:26px auto;<?= $hasAnyContent ? '' : ' border:2px solid #bfe0c8; box-shadow:0 12px 34px rgba(30,90,55,.14);' ?>">
            <?php if ($hasAnyContent): ?>
                <button type="button" id="editContentBtn" onclick="toggleContentForm()" style="width:100%; border:none; background:linear-gradient(180deg,#eef7f0,#e0efe3); color:#2d5a37; font-weight:800; font-size:1.05rem; padding:16px; border-radius:12px; cursor:pointer;">✏️ Modifier le contenu <span id="editContentCaret" style="opacity:.6;">▾</span></button>
            <?php else: ?>
                <h3 style="margin-top:0; color:#2d5a37; font-size:1.35rem;">📎 Importer des fichiers</h3>
                <p style="color:#666; margin:-4px 0 12px;">Déposez votre <strong>document</strong> et/ou votre <strong>vidéo</strong>. À la validation : « Guide » pour le document, « Vidéo » pour la vidéo.</p>
            <?php endif; ?>
            <div id="contentFormWrap"<?= $hasAnyContent ? ' style="display:none; margin-top:16px;"' : '' ?>>
            <?php if ($hasAnyContent): ?><p style="color:#666; margin:0 0 12px;">Fichiers actuels ci-dessous. Déposez un nouveau fichier pour <strong>remplacer</strong>, puis choisissez « Modifier » ou « Modifier et uniformiser ».</p><?php endif; ?>
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

                <!-- Les deux blocs, côte à côte -->
                <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start;">
                    <div style="flex:1; min-width:260px;">
                        <div class="drop-zone" id="dz_pdf" data-ext="pdf" data-max="30" data-what="document" data-has-existing="<?= !empty($module['pdf_path']) ? '1' : '0' ?>" data-remove="remove_pdf">
                            <input type="file" name="pdf_file" accept="application/pdf,.pdf" class="dz-input">
                            <div class="dz-icon">📄</div>
                            <div class="dz-title">Guide</div>
                            <div class="dz-hint">Glissez votre document ici ou cliquez pour parcourir<br><small style="color:#8a968f;">PDF uniquement · jusqu'à 30 Mo · (PowerPoint / Word : Fichier → Exporter → PDF)</small></div>
                            <div class="dz-file" hidden></div>
                        </div>
                        <?php if ($existPdf): ?>
                            <div class="dz-existing">
                                📄 <a href="<?= htmlspecialchars(moduleFileUrl($existPdf)) ?>" download>Document actuel</a>
                                <?php if (!empty($module['pdf_path'])): ?><label class="chk" style="display:inline-flex; margin-left:12px;"><input type="checkbox" name="remove_pdf" value="1"> Supprimer</label><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="flex:1; min-width:260px;">
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
                            <div class="dz-existing">💬 Sous-titres fournis
                                <label class="chk" style="display:inline-flex; margin-left:12px;"><input type="checkbox" name="remove_srt" value="1"> Supprimer</label>
                            </div>
                        <?php endif; ?>
                        <?php if ($existVideo): ?>
                            <div class="dz-existing">
                                🎬 <a href="<?= htmlspecialchars(moduleFileUrl($existVideo)) ?>" download>Vidéo actuelle</a>
                                <?php if (!empty($module['video_path'])): ?><label class="chk" style="display:inline-flex; margin-left:12px;"><input type="checkbox" name="remove_video" value="1"> Supprimer</label><?php endif; ?>
                            </div>
                        <?php elseif ($existVideoProc): ?>
                            <div class="dz-existing">🎬 <span style="color:#8a5a00;">Vidéo en préparation…</span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="chk" style="margin-top:18px; padding:12px 14px; background:#f4f7f6; border-radius:10px;">
                    <input type="checkbox" name="a_evaluer" value="1" <?= !empty($module['a_evaluer']) ? 'checked' : '' ?>>
                    📝 Générer un quiz <small style="font-weight:400; color:#777;">(l'IA crée les questions à partir du document et de la vidéo)</small>
                </label>

                <p style="font-size:0.82rem; color:#777; margin-top:14px;">« <?= $hasAnyContent ? 'Modifier' : 'Valider' ?> et uniformiser » : l'IA lit le document et construit la belle page « Guide » (au lieu de l'afficher brut).</p>
                <div style="display:flex; gap:10px; margin-top:6px; flex-wrap:wrap;">
                    <button type="submit" name="uniformize" value="0" class="btn" style="background:#e9ecef; color:#333;"><?= $hasAnyContent ? 'Modifier' : 'Valider' ?></button>
                    <button type="submit" name="uniformize" value="1" class="btn btn-create"><?= $hasAnyContent ? 'Modifier et uniformiser' : 'Valider et uniformiser' ?></button>
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
        // Explique POURQUOI le fichier est refuse, et ce qu'il faut faire.
        function fcReject(what, ext, exts, maxMo, okExt) {
            var t = document.getElementById('fcRejectTitle');
            var m = document.getElementById('fcRejectText');
            if (!okExt) {
                t.textContent = 'Format non accepté';
                var msg = 'Le fichier <strong>.' + (ext || '?') + '</strong> n'est pas accepté pour le ' + what
                        + '. Formats acceptés : <strong>' + exts.map(function (e) { return '.' + e; }).join(', ') + '</strong>.';
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
        function validateContent(e) {
            var n = (dzPresent('dz_pdf') ? 1 : 0) + (dzPresent('dz_video') ? 1 : 0);
            if (e && e.submitter && e.submitter.name === 'uniformize') {
                fcPendingUniformize = e.submitter.value;
            }
            if (n === 0) {
                document.getElementById('fileErrorModal').style.display = 'flex';
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
            f.submit();
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
            f.submit();
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
</body>
</html>
