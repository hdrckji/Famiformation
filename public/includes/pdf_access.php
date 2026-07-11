<?php
// ============================================================
// pdf_access.php — réglage d'accès aux PDF (voir / télécharger),
// activable/désactivable et filtrable par profil (préférences admin).
// Utile car l'egress (bande passante) est payant chez certains hébergeurs
// (Railway) et gratuit chez d'autres (OVH). Les admins ont toujours accès.
// Additif : autonome (stockage via widgetGet/widgetSet).
// ============================================================

if (!function_exists('pdfViewEnabled')) {
    function pdfViewEnabled($db)     { return !function_exists('widgetGet') || widgetGet($db, 'pdf_view_enabled', '1') === '1'; }
    function pdfDownloadEnabled($db) { return !function_exists('widgetGet') || widgetGet($db, 'pdf_download_enabled', '1') === '1'; }
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
}

if (!function_exists('pdfAccessHandlePost')) {
    function pdfAccessHandlePost($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'set_pdf_access') { return; }
        requireValidCSRF();
        widgetSet($db, 'pdf_view_enabled', !empty($_POST['pdf_view_enabled']) ? '1' : '0');
        widgetSet($db, 'pdf_download_enabled', !empty($_POST['pdf_download_enabled']) ? '1' : '0');
        $valid = function_exists('moduleProfiles') ? array_keys(moduleProfiles($db)) : [];
        $vr = array_values(array_intersect($valid, (array) ($_POST['pdf_view_roles'] ?? [])));
        $dr = array_values(array_intersect($valid, (array) ($_POST['pdf_download_roles'] ?? [])));
        widgetSet($db, 'pdf_view_roles', implode(',', $vr));
        widgetSet($db, 'pdf_download_roles', implode(',', $dr));
        $_SESSION['module_flash'] = '📄 Accès aux PDF enregistré.';
        header('Location: parametres.php#prefs');
        exit();
    }
}

if (!function_exists('pdfAccessCard')) {
    function pdfAccessCard($db)
    {
        $profiles = function_exists('moduleProfiles') ? moduleProfiles($db) : [];
        $vEnabled = pdfViewEnabled($db);
        $dEnabled = pdfDownloadEnabled($db);
        $vRoles = pdfViewRoles($db);
        $dRoles = pdfDownloadRoles($db);

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
        <div style="border-top:1px solid #eee; padding-top:14px; margin-top:16px;">
            <h3 style="margin:0 0 6px; color:#244230;">📄 Accès aux fichiers PDF</h3>
            <p class="muted">Voir ou télécharger le PDF d'origine consomme de la <strong>bande passante</strong> (payante chez certains hébergeurs, gratuite chez d'autres). Active/désactive chaque option et choisis les profils autorisés. <strong>Les admins ont toujours accès.</strong> (La lecture du texte uniformisé reste gratuite pour tous.)</p>

            <form method="POST" action="parametres.php#prefs">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_pdf_access">

                <label style="display:flex; align-items:center; gap:10px; font-weight:700; color:#244230; margin-top:10px;">
                    <input type="checkbox" name="pdf_view_enabled" value="1" <?= $vEnabled ? 'checked' : '' ?>> 👁 Autoriser la <span style="color:#2d5a37;">vue</span> du PDF dans le site
                </label>
                <div class="muted" style="margin-left:26px; font-size:.82rem;">Profils autorisés (aucun coché = tout le monde) :</div>
                <?php $rolesBox('pdf_view_roles', $vRoles); ?>

                <label style="display:flex; align-items:center; gap:10px; font-weight:700; color:#244230; margin-top:16px;">
                    <input type="checkbox" name="pdf_download_enabled" value="1" <?= $dEnabled ? 'checked' : '' ?>> ⤓ Autoriser le <span style="color:#2d5a37;">téléchargement</span> du PDF
                </label>
                <div class="muted" style="margin-left:26px; font-size:.82rem;">Profils autorisés (aucun coché = tout le monde) :</div>
                <?php $rolesBox('pdf_download_roles', $dRoles); ?>

                <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
            </form>
        </div>
        <?php
    }
}
