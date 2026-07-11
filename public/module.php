<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/rendezvous.php';

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
        .badge-eval { display:inline-block; background:#2d5a37; color:#fff; font-size:0.78rem; font-weight:700; padding:4px 12px; border-radius:20px; margin-top:8px; }
        .tile .badge-eval { position:absolute; top:12px; right:12px; margin:0; }
        .content-card { background: rgba(255,255,255,0.96); border-radius: 18px; padding: 32px; width: 90%; max-width: 900px; margin: 30px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
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
        .topbar { width:100%; box-sizing:border-box; display:flex; align-items:center; justify-content:space-between; padding:16px; gap:12px; }
        .topbar .back-link { margin:0; align-self:auto; }
        .uni-actions { display:flex; gap:10px; }
        .uni-ico { width:46px; height:46px; display:inline-flex; align-items:center; justify-content:center; font-size:1.25rem; background:rgba(255,255,255,0.92); color:#2d5a37; border:none; border-radius:14px; cursor:pointer; text-decoration:none; box-shadow:0 4px 14px rgba(0,0,0,.14); transition:transform .12s ease, background .15s; }
        .uni-ico:hover { background:#2d5a37; color:#fff; transform:translateY(-2px); }
    </style>
</head>
<body>
    <?= apercuBanner($db) ?>
    <?php
        require_once __DIR__ . '/includes/pdf_access.php';
        $uniHasPdf = !empty($module['pdf_path']);
        $uniHasContent = (!empty($module['uniformized']) && !empty($module['contenu_ia']) && $uniHasPdf);
        $uniRole = function_exists('currentDisplayRole') ? currentDisplayRole() : ($_SESSION['role'] ?? '');
        $uniPdfUrl = $uniHasPdf ? moduleFileUrl($module['pdf_path']) : '';
        $canViewPdf = $uniHasContent && pdfCanView($db, $uniRole, !empty($isAdmin));
        $canDlPdf = $uniHasPdf && pdfCanDownload($db, $uniRole, !empty($isAdmin));
    ?>
    <div class="topbar">
        <a href="<?= !empty($module['parent_id']) ? 'module.php?id=' . (int) $module['parent_id'] : 'index.php' ?>" class="back-link">⬅ Retour</a>
        <?php if ($canViewPdf || $canDlPdf): ?>
            <div class="uni-actions">
                <?php if ($canViewPdf): ?><button type="button" id="uniEye" class="uni-ico" title="Voir le PDF original" onclick="window.uniTogglePdf && window.uniTogglePdf()">👁</button><?php endif; ?>
                <?php if ($canDlPdf): ?><a class="uni-ico" href="<?= htmlspecialchars($uniPdfUrl) ?>" download title="Télécharger le PDF original">⤓</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (empty($uniHasContent)): ?>
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
                <a href="<?= htmlspecialchars($childHref) ?>" class="tile"<?= $childExternal ? ' target="_blank" rel="noopener"' : '' ?>>
                    <?php if (!empty($child['a_evaluer'])): ?><span class="badge-eval">📝</span><?php endif; ?>
                    <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                    <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars(moduleDesc($child)) ?></div>
                </a>
                <?php else: ?>
                <div class="tile inactive" title="Module inactif — réactive-le dans Gestion des modules" style="cursor:not-allowed;">
                    <span class="badge-eval" style="background:#999;">Inactif</span>
                    <div class="tile-icon"><?= moduleIconHtml($child, '3rem') ?></div>
                    <div class="tile-title"><?= htmlspecialchars(moduleNom($child)) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars(moduleDesc($child)) ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($children)): ?>
                <div class="content-card" style="text-align:center;">Aucun sous-module pour l'instant.</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php $isUni = !empty($module['uniformized']); ?>
        <?php $vStatus = (string) ($module['video_status'] ?? ''); ?>
        <?php if ($vStatus === 'processing'): ?>
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
                <?php renderUniformContent($module['contenu_ia'], $uniPdfUrl, $canViewPdf, (array) json_decode((string) ($module['contenu_images'] ?? '[]'), true)); ?>
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
        <?php if (empty($module['video_path']) && empty($module['pdf_path']) && $vStatus === ''): ?>
            <div class="content-card" style="text-align:center; color:#666;"><?= t("Ce module n'a pas encore de contenu.", 'Deze module heeft nog geen inhoud.') ?></div>
        <?php endif; ?>
        <?php if (!$isContainer && !empty($module['a_evaluer']) && !empty($module['quiz_json'])): ?>
            <?php require_once __DIR__ . '/includes/quiz_view.php'; ?>
            <?php renderQuizForm(json_decode((string) $module['quiz_json'], true), (int) $module['id']); ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="admin-actions">
            <button type="button" class="btn btn-create" onclick="document.getElementById('editModal').style.display='flex';">✏️ Modifier ce module</button>
            <?php if ($isContainer): ?>
                <button type="button" class="btn btn-create" onclick="document.getElementById('createModal').style.display='flex';">➕ Ajouter un sous-module</button>
            <?php elseif (empty($module['is_booking'])): ?>
                <button type="button" class="btn btn-create" onclick="document.getElementById('contentModal').style.display='flex';">📎 Ajouter du contenu</button>
            <?php endif; ?>
        </div>
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
        <?php endif; ?>

        <?php if (!$isContainer && empty($module['is_booking'])): ?>
        <!-- Modale : gérer le contenu (PDF / vidéo) -->
        <div id="contentModal" class="modal-backdrop">
            <div class="modal-card" style="max-width:620px;">
                <h3>Contenu du module</h3>
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

                    <!-- Grand bloc PDF -->
                    <div class="drop-zone" id="dz_pdf" data-has-existing="<?= !empty($module['pdf_path']) ? '1' : '0' ?>" data-remove="remove_pdf">
                        <input type="file" name="pdf_file" accept="application/pdf" class="dz-input">
                        <div class="dz-icon">📄</div>
                        <div class="dz-title">PDF</div>
                        <div class="dz-hint">Glissez votre PDF ici ou cliquez pour parcourir</div>
                        <div class="dz-file" hidden></div>
                    </div>
                    <?php if (!empty($module['pdf_path'])): ?>
                        <div class="dz-existing">
                            <a href="<?= htmlspecialchars(moduleFileUrl($module['pdf_path'])) ?>" download>⬇ Télécharger le PDF actuel</a>
                            <label class="chk" style="display:inline-flex; margin-left:12px;"><input type="checkbox" name="remove_pdf" value="1"> Supprimer</label>
                        </div>
                    <?php endif; ?>

                    <!-- Grand bloc Vidéo -->
                    <div class="drop-zone" id="dz_video" data-has-existing="<?= !empty($module['video_path']) ? '1' : '0' ?>" data-remove="remove_video">
                        <input type="file" name="video_file" accept="video/*" class="dz-input">
                        <div class="dz-icon">🎬</div>
                        <div class="dz-title">Vidéo</div>
                        <div class="dz-hint">Glissez votre vidéo ici ou cliquez pour parcourir<br><small style="color:#8a968f;">N'importe quel format · jusqu'à 1 Go · elle sera optimisée automatiquement (720p) pour une lecture fluide</small></div>
                        <div class="dz-file" hidden></div>
                    </div>
                    <?php if (!empty($module['video_path'])): ?>
                        <div class="dz-existing">
                            <a href="<?= htmlspecialchars(moduleFileUrl($module['video_path'])) ?>" download>⬇ Télécharger la vidéo actuelle</a>
                            <label class="chk" style="display:inline-flex; margin-left:12px;"><input type="checkbox" name="remove_video" value="1"> Supprimer</label>
                        </div>
                    <?php endif; ?>

                    <label class="chk" style="margin-top:18px; padding:12px 14px; background:#f4f7f6; border-radius:10px;">
                        <input type="checkbox" name="a_evaluer" value="1" <?= !empty($module['a_evaluer']) ? 'checked' : '' ?>>
                        📝 Ce contenu est à évaluer <small style="font-weight:400; color:#777;">(un quiz de 75 questions sera généré pour l'évaluer)</small>
                    </label>

                    <p style="font-size:0.82rem; color:#777; margin-top:14px;">« Valider et uniformiser » affiche le PDF dans une mise en page intégrée au site (au lieu du lecteur PDF brut).</p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-cancel" onclick="document.getElementById('contentModal').style.display='none';">Annuler</button>
                        <button type="submit" name="uniformize" value="0" class="btn" style="background:#e9ecef; color:#333;">Valider</button>
                        <button type="submit" name="uniformize" value="1" class="btn btn-create">Valider et uniformiser</button>
                    </div>
                </form>
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
                            label.textContent = '✓ ' + input.files[0].name;
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
            if (n === 1) {
                document.getElementById('fileWarnModal').style.display = 'flex';
                return false;
            }
            return true; // 2 fichiers : enregistrement direct
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
            }).catch(function () { box.innerHTML = '<div style="text-align:center"><a href="' + url + '">Ouvrir le document</a></div>'; });
        })();
        </script>
        <?php else: ?>
        <script src="/pdf-viewer.js" defer></script>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
