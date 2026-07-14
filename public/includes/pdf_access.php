<?php
// ============================================================
// pdf_access.php — réglage d'accès aux PDF (voir / télécharger),
// activable/désactivable et filtrable par profil (préférences admin).
// Utile car l'egress (bande passante) est payant chez certains hébergeurs
// (Railway) et gratuit en hébergement local. Les admins ont toujours accès.
// Additif : autonome (stockage via widgetGet/widgetSet).
// ============================================================

if (!function_exists('filesAccessOn')) {
    /** Interrupteur MAÎTRE du bloc « Accès aux fichiers ». Coupé : plus aucun accès (sauf admin). */
    function filesAccessOn($db)
    {
        return !function_exists('widgetGet') || widgetGet($db, 'files_access_on', '1') === '1';
    }
}

if (!function_exists('pdfViewEnabled')) {
    function pdfViewEnabled($db)     { return filesAccessOn($db) && (!function_exists('widgetGet') || widgetGet($db, 'pdf_view_enabled', '1') === '1'); }
    function pdfDownloadEnabled($db) { return filesAccessOn($db) && (!function_exists('widgetGet') || widgetGet($db, 'pdf_download_enabled', '1') === '1'); }
    function pdfViewRoles($db)       { return array_values(array_filter(array_map('trim', explode(',', (string) (function_exists('widgetGet') ? widgetGet($db, 'pdf_view_roles', '') : ''))))); }
    function pdfDownloadRoles($db)   { return array_values(array_filter(array_map('trim', explode(',', (string) (function_exists('widgetGet') ? widgetGet($db, 'pdf_download_roles', '') : ''))))); }

    /** L'utilisateur (rôle donné) peut-il VOIR le PDF ? Admin = toujours. */
    function pdfCanView($db, $role, $isAdmin = false)
    {
        if ($isAdmin) { return true; }
        if (!pdfViewEnabled($db)) { return false; }
        $roles = pdfViewRoles($db);
        return empty($roles) || in_array($role, $roles, true);
    }
    /** L'utilisateur (rôle donné) peut-il TÉLÉCHARGER le PDF ? Admin = toujours. */
    function pdfCanDownload($db, $role, $isAdmin = false)
    {
        if ($isAdmin) { return true; }
        if (!pdfDownloadEnabled($db)) { return false; }
        $roles = pdfDownloadRoles($db);
        return empty($roles) || in_array($role, $roles, true);
    }

    // --- VIDÉO : même logique (le téléchargement d'une vidéo coûte BEAUCOUP de bande passante).
    // Par défaut DÉSACTIVÉ, contrairement au PDF : une vidéo pèse lourd.
    function videoDownloadEnabled($db) { return filesAccessOn($db) && function_exists('widgetGet') && widgetGet($db, 'video_download_enabled', '0') === '1'; }
    function videoDownloadRoles($db)   { return array_values(array_filter(array_map('trim', explode(',', (string) (function_exists('widgetGet') ? widgetGet($db, 'video_download_roles', '') : ''))))); }

    /** L'utilisateur (rôle donné) peut-il TÉLÉCHARGER la vidéo ? Admin = toujours. */
    function videoCanDownload($db, $role, $isAdmin = false)
    {
        if ($isAdmin) { return true; }
        if (!videoDownloadEnabled($db)) { return false; }
        $roles = videoDownloadRoles($db);
        return empty($roles) || in_array($role, $roles, true);
    }
}

if (!function_exists('pdfAccessHandlePost')) {
    function pdfAccessHandlePost($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'set_pdf_access') { return; }
        requireValidCSRF();

        // Bascule seule (interrupteur du titre) : on ne touche pas aux options du bloc.
        if (!empty($_POST['toggle_only'])) {
            widgetSet($db, 'files_access_on', !empty($_POST['files_access_on']) ? '1' : '0');
            $_SESSION['module_flash'] = !empty($_POST['files_access_on'])
                ? '📄 Accès aux fichiers activé.'
                : "📄 Accès aux fichiers coupé : plus personne (hors admin) ne peut voir ni télécharger les fichiers d'origine.";
            header('Location: parametres.php#prefs');
            exit();
        }

        widgetSet($db, 'pdf_view_enabled', !empty($_POST['pdf_view_enabled']) ? '1' : '0');
        widgetSet($db, 'pdf_download_enabled', !empty($_POST['pdf_download_enabled']) ? '1' : '0');
        $valid = function_exists('moduleProfiles') ? array_keys(moduleProfiles($db)) : [];
        $vr = array_values(array_intersect($valid, (array) ($_POST['pdf_view_roles'] ?? [])));
        $dr = array_values(array_intersect($valid, (array) ($_POST['pdf_download_roles'] ?? [])));
        widgetSet($db, 'pdf_view_roles', implode(',', $vr));
        widgetSet($db, 'pdf_download_roles', implode(',', $dr));
        // Vidéo
        widgetSet($db, 'video_download_enabled', !empty($_POST['video_download_enabled']) ? '1' : '0');
        $vdr = array_values(array_intersect($valid, (array) ($_POST['video_download_roles'] ?? [])));
        widgetSet($db, 'video_download_roles', implode(',', $vdr));
        $_SESSION['module_flash'] = '📄 Accès aux fichiers (PDF / vidéo) enregistré.';
        header('Location: parametres.php#prefs');
        exit();
    }
}

if (!function_exists('pdfAccessCard')) {
    function pdfAccessCard($db)
    {
        $mOn = filesAccessOn($db);
        $profiles = function_exists('moduleProfiles') ? moduleProfiles($db) : [];
        $vEnabled = pdfViewEnabled($db);
        $dEnabled = pdfDownloadEnabled($db);
        $vRoles = pdfViewRoles($db);
        $dRoles = pdfDownloadRoles($db);
        $vdEnabled = videoDownloadEnabled($db);
        $vdRoles = videoDownloadRoles($db);

        $rolesBox = function ($field, $selected) use ($profiles) {
            echo '<div style="display:flex; flex-wrap:wrap; gap:10px; margin:8px 0 4px 26px;">';
            foreach ($profiles as $k => $lbl) {
                echo '<label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:.88rem; color:#33473b;">'
                    . '<input type="checkbox" name="' . htmlspecialchars($field) . '[]" value="' . htmlspecialchars($k) . '" ' . (in_array($k, $selected, true) ? 'checked' : '') . '> '
                    . htmlspecialchars($lbl) . '</label>';
            }
            echo '</div>';
        };
        ?>
        <div class="pref-block">
            <?php
                require_once __DIR__ . '/ui_switch.php';
                famiPrefHead('📄 Accès aux fichiers (PDF &amp; vidéo)', 'set_pdf_access', 'files_access_on', $mOn,
                    "Coupé : personne (hors admin) ne peut voir ni télécharger les fichiers d'origine.");
            ?>
            <div class="pref-body<?= $mOn ? '' : ' pref-off' ?>">
            <p class="muted">Voir ou télécharger le fichier d'origine consomme de la <strong>bande passante</strong> (payante chez certains hébergeurs, gratuite en local). Choisis ce qui est autorisé, et pour quels profils. <strong>Les admins ont toujours accès.</strong> (La lecture du guide et de la vidéo en streaming reste libre pour tous.)</p>

            <form method="POST" action="parametres.php#prefs">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_pdf_access">

                <?php require_once __DIR__ . '/ui_switch.php'; famiSwitch('pdf_view_enabled', $vEnabled, '👁 Voir le PDF dans le site'); ?>
                <div class="muted" style="margin-left:26px; font-size:.82rem;">Profils autorisés (aucun coché = tout le monde) :</div>
                <?php $rolesBox('pdf_view_roles', $vRoles); ?>

                <div style="margin-top:18px;"><?php famiSwitch('pdf_download_enabled', $dEnabled, '⤓ Télécharger le PDF'); ?></div>
                <div class="muted" style="margin-left:26px; font-size:.82rem;">Profils autorisés (aucun coché = tout le monde) :</div>
                <?php $rolesBox('pdf_download_roles', $dRoles); ?>

                <div style="margin-top:20px; padding-top:16px; border-top:1px dashed #dfe6e0;"><?php famiSwitch('video_download_enabled', $vdEnabled, '⤓ Télécharger la vidéo 🎬'); ?></div>
                <div class="muted" style="margin-left:26px; font-size:.82rem;">⚠️ Une vidéo pèse lourd : le téléchargement consomme <strong>beaucoup</strong> de bande passante. Profils autorisés (aucun coché = tout le monde) :</div>
                <?php $rolesBox('video_download_roles', $vdRoles); ?>

                <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
            </form>
            </div>
        </div>
        <?php
    }
}
