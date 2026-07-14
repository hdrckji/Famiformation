<?php
// ============================================================
// branding.php — « Créateur » : habillage visuel du site (préférences admin).
//
//   HABILLAGE VIDÉO : une vidéo filmée au téléphone (9:16, portrait) laisse deux
//   grosses bandes noires dans un lecteur 16:9. On affiche une IMAGE derrière le
//   lecteur : la vidéo se pose dessus, et l'image remplit tout ce qu'elle ne couvre
//   pas. Aucun ré-encodage (rien à voir avec ffmpeg) : c'est de l'affichage pur, donc
//   changer l'image change TOUTES les vidéos instantanément, sans rien retraiter.
//
//   DEUX IMAGES : une FR, une NL. Le site sert celle de la langue affichée — l'image
//   peut donc porter du texte (slogan, consigne) sans être incompréhensible pour
//   l'autre moitié des collaborateurs. Si l'image NL manque, on retombe sur la FR.
//
// Additif : autonome (images sur le volume, clés mémorisées via widgetGet/widgetSet).
// ============================================================

if (!function_exists('brandingEnabled')) {
    /** L'habillage est-il activé ? (interrupteur du bloc « Créateur ») */
    function brandingEnabled($db)
    {
        return !function_exists('widgetGet') || widgetGet($db, 'video_backdrop_on', '1') === '1';
    }

    /** Nom du réglage qui mémorise l'image d'une langue. */
    function brandingBackdropKey($lang)
    {
        return (strtolower((string) $lang) === 'nl') ? 'video_backdrop_nl' : 'video_backdrop';
    }

    /** Clé (volume) de l'image d'habillage pour une langue donnée. '' si aucune. */
    function brandingBackdropFor($db, $lang)
    {
        if (!function_exists('widgetGet')) { return ''; }
        return trim((string) widgetGet($db, brandingBackdropKey($lang), ''));
    }

    /**
     * Image à AFFICHER : celle de la langue courante ; à défaut, celle du français.
     * '' si l'habillage est coupé ou si aucune image n'est définie.
     */
    function brandingVideoBackdrop($db)
    {
        if (!brandingEnabled($db)) { return ''; }
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        $key = brandingBackdropFor($db, $lang);
        if ($key === '' && $lang !== 'fr') { $key = brandingBackdropFor($db, 'fr'); } // repli
        return $key;
    }

    /** URL prête à l'emploi de cette image, ou '' si aucune. */
    function brandingVideoBackdropUrl($db)
    {
        $key = brandingVideoBackdrop($db);
        if ($key === '') { return ''; }
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode($key));
    }
}

if (!function_exists('brandingUnlinkKey')) {
    /** Efface une image d'habillage du volume (pas de fuite de stockage). */
    function brandingUnlinkKey($key)
    {
        $key = trim((string) $key);
        if ($key === '') { return; }
        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        $abs = realpath($base . '/' . $key);
        $baseReal = realpath($base);
        if ($abs !== false && $baseReal !== false && strpos($abs, $baseReal) === 0 && is_file($abs)) {
            @unlink($abs);
        }
    }
}

if (!function_exists('brandingHandlePost')) {
    function brandingHandlePost($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'set_branding') { return; }
        requireValidCSRF();

        $back = function ($msg) {
            $_SESSION['module_flash'] = $msg;
            header('Location: parametres.php#prefs');
            exit();
        };

        // Interrupteur du bloc (activer / désactiver sans supprimer les images).
        if (!empty($_POST['toggle_only'])) {
            widgetSet($db, 'video_backdrop_on', !empty($_POST['video_backdrop_on']) ? '1' : '0');
            $back(!empty($_POST['video_backdrop_on'])
                ? '🎨 Habillage vidéo activé.'
                : "🎨 Habillage vidéo désactivé (les bandes redeviennent noires ; les images sont conservées).");
        }

        $lang = (($_POST['lang'] ?? 'fr') === 'nl') ? 'nl' : 'fr';
        $lbl = ($lang === 'nl') ? 'néerlandaise' : 'française';
        $setting = brandingBackdropKey($lang);

        // Suppression de l'image d'une langue.
        if (!empty($_POST['remove_backdrop'])) {
            brandingUnlinkKey(brandingBackdropFor($db, $lang));
            widgetSet($db, $setting, '');
            $back('🎨 Image ' . $lbl . ' retirée.');
        }

        $f = $_FILES['backdrop'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $back('❌ Aucune image envoyée.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) {
            $back('❌ Image refusée (5 Mo maximum).');
        }
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
        $ext = $map[$mime] ?? '';
        if ($ext === '') {
            $back('❌ Format refusé : JPEG, PNG ou WebP uniquement.');
        }

        // Volume, catégorie « divers » (comme les icônes et les photos de profil).
        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        $dir = $base . '/divers/branding';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = 'backdrop-' . $lang . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            $back("❌ Impossible d'enregistrer l'image.");
        }

        brandingUnlinkKey(brandingBackdropFor($db, $lang)); // l'ancienne ne sert plus à rien
        widgetSet($db, $setting, 'divers/branding/' . $name);
        $back('🎨 Image ' . $lbl . ' enregistrée — elle habille les vidéos verticales.');
    }
}

if (!function_exists('brandingCard')) {
    function brandingCard($db)
    {
        require_once __DIR__ . '/ui_switch.php';
        $on = brandingEnabled($db);

        // Une zone de dépôt par langue (même rendu, même aperçu).
        $zone = function ($lang, $flag, $label) use ($db) {
            $key = brandingBackdropFor($db, $lang);
            $url = ($key !== '' && function_exists('moduleFileUrl')) ? moduleFileUrl($key) : '';
            ?>
            <div style="flex:1; min-width:280px;">
                <div style="font-weight:800; color:#244230; margin-bottom:8px;"><?= $flag ?> <?= htmlspecialchars($label) ?></div>

                <?php if ($url !== ''): ?>
                    <div style="position:relative; border-radius:12px; overflow:hidden; border:1px solid #d9e3dc; background:#111 url('<?= htmlspecialchars($url) ?>') center/cover no-repeat; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center;">
                        <div style="height:100%; aspect-ratio:9/16; background:#0c1a11; display:flex; align-items:center; justify-content:center; color:#9fb8a6; font-size:.72rem; font-weight:700;">vidéo 9:16</div>
                    </div>
                <?php else: ?>
                    <div style="border:2px dashed #cfdad3; border-radius:12px; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center; color:#8a968f; font-style:italic; font-size:.85rem; text-align:center; padding:10px;">
                        Aucune image<?= $lang === 'nl' ? '<br><small>(la version française sera utilisée)</small>' : '' ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="parametres.php#prefs" enctype="multipart/form-data" style="margin-top:10px;" id="bdForm-<?= $lang ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_branding">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">

                    <label class="bd-drop" id="bdDrop-<?= $lang ?>">
                        <input type="file" name="backdrop" accept="image/jpeg,image/png,image/webp" required
                               onchange="bdPick(this, '<?= $lang ?>')">
                        <span class="bd-ico">🖼️</span>
                        <span class="bd-txt">
                            <strong><?= $url !== '' ? "Remplacer l'image" : 'Déposer une image' ?></strong>
                            <small>Glissez-la ici ou cliquez · JPEG, PNG, WebP · 5 Mo max · idéal 1920×1080</small>
                        </span>
                        <span class="bd-name" id="bdName-<?= $lang ?>" hidden></span>
                    </label>

                    <button type="submit" class="btn btn-primary" style="margin-top:10px; width:100%;">
                        <?= $url !== '' ? "Remplacer l'image" : "Enregistrer l'image" ?>
                    </button>
                </form>

                <?php if ($url !== ''): ?>
                    <form method="POST" action="parametres.php#prefs" style="margin-top:8px;" onsubmit="return confirm('Retirer cette image ?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_branding">
                        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
                        <input type="hidden" name="remove_backdrop" value="1">
                        <button type="submit" class="btn" style="background:#fdecec; color:#b3261e;">🗑️ Retirer</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php
        };
        ?>
        <style>
        .bd-drop { position:relative; display:flex; align-items:center; gap:12px; cursor:pointer;
            border:2.5px dashed #b9cdbf; border-radius:12px; background:#f6faf7; padding:14px 16px; transition:all .15s; }
        .bd-drop:hover, .bd-drop.over { border-color:#2d5a37; background:#eef7f0; }
        .bd-drop input { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .bd-ico { font-size:1.7rem; flex:none; }
        .bd-txt strong { display:block; color:#244230; }
        .bd-txt small { display:block; color:#7a8a80; font-size:.78rem; margin-top:2px; }
        .bd-name { margin-left:auto; font-weight:800; color:#1d6a39; background:#e7f6ec; border:1px solid #b6e0c2;
                   border-radius:8px; padding:5px 9px; font-size:.78rem; max-width:45%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        </style>
        <script>
        // Aperçu immédiat du fichier choisi + glisser-déposer.
        function bdPick(input, lang) {
            var n = document.getElementById('bdName-' + lang);
            if (input.files && input.files.length) { n.textContent = '✓ ' + input.files[0].name; n.hidden = false; }
            else { n.hidden = true; }
        }
        ['fr', 'nl'].forEach(function (lg) {
            var dz = document.getElementById('bdDrop-' + lg);
            if (!dz) { return; }
            ['dragenter', 'dragover'].forEach(function (e) { dz.addEventListener(e, function () { dz.classList.add('over'); }); });
            ['dragleave', 'drop'].forEach(function (e) { dz.addEventListener(e, function () { dz.classList.remove('over'); }); });
        });
        </script>
        <div class="pref-block">
            <?php famiPrefHead('🎨 Créateur — habillage des vidéos', 'set_branding', 'video_backdrop_on', $on,
                "Coupé : les vidéos verticales retrouvent leurs bandes noires (les images restent enregistrées)."); ?>
            <div class="pref-body<?= $on ? '' : ' pref-off' ?>">
                <p class="muted">Une vidéo filmée au téléphone (format <strong>9:16</strong>) laisse deux <strong>bandes noires</strong> sur les côtés du lecteur. L'image déposée ici s'affiche <strong>derrière</strong> la vidéo et remplit ces bandes. Une vidéo 16:9 la recouvre entièrement : elle ne se voit pas, aucun risque.</p>
                <p class="muted" style="font-size:.84rem;">💡 <strong>Une image par langue</strong> : le site affiche celle de la langue en cours, l'image peut donc porter du texte. Si l'image néerlandaise manque, la française est utilisée. Idéal : 1920×1080, plutôt sombre et peu chargée. JPEG, PNG ou WebP, 5 Mo max. Rien n'est ré-encodé : changer une image change <strong>toutes</strong> les vidéos aussitôt.</p>

                <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:14px;">
                    <?php $zone('fr', '🇫🇷', 'Version française'); ?>
                    <?php $zone('nl', '🇳🇱', 'Version néerlandaise'); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
