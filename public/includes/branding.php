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

if (!function_exists('brandingClipKey')) {
    /**
     * INTRO / OUTRO : de courtes vidéos jouées AVANT et APRÈS chaque formation vidéo.
     *
     *   On ne colle RIEN bout à bout : ré-encoder chaque vidéo coûterait cher en CPU et
     *   obligerait à retraiter TOUTES les formations le jour où l'intro change. Le lecteur
     *   enchaîne simplement les fichiers (liste de lecture). Changer l'intro change donc
     *   toutes les vidéos instantanément, sans rien retraiter.
     */
    function brandingClipKey($what, $lang)
    {
        $what = ($what === 'outro') ? 'outro' : 'intro';
        return 'video_' . $what . ((strtolower((string) $lang) === 'nl') ? '_nl' : '');
    }

    /** L'intro (ou l'outro) est-elle activée ? */
    function brandingClipsOn($db)
    {
        return !function_exists('widgetGet') || widgetGet($db, 'video_clips_on', '1') === '1';
    }

    /** Clé (volume) de l'intro/outro d'une langue. '' si aucune. */
    function brandingClipFor($db, $what, $lang)
    {
        if (!function_exists('widgetGet')) { return ''; }
        return trim((string) widgetGet($db, brandingClipKey($what, $lang), ''));
    }

    /** URL de l'intro/outro à jouer dans la langue courante (repli sur le français). */
    function brandingClipUrl($db, $what)
    {
        if (!brandingClipsOn($db)) { return ''; }
        $lang = function_exists('currentLang') ? currentLang() : 'fr';
        $key = brandingClipFor($db, $what, $lang);
        if ($key === '' && $lang !== 'fr') { $key = brandingClipFor($db, $what, 'fr'); }
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
            header('Location: parametres.php#createur');
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

        // ── INTRO / OUTRO (vidéos courtes) ────────────────────────────────────────
        if (($_POST['kind'] ?? '') === 'clip') {
            $what = (($_POST['what'] ?? 'intro') === 'outro') ? 'outro' : 'intro';
            $setting = brandingClipKey($what, $lang);
            $wlbl = ($what === 'outro') ? 'Outro' : 'Intro';

            if (!empty($_POST['remove_clip'])) {
                brandingUnlinkKey(brandingClipFor($db, $what, $lang));
                widgetSet($db, $setting, '');
                $back('🎬 ' . $wlbl . ' ' . $lbl . ' retirée.');
            }

            $cf = $_FILES['clip'] ?? null;
            if (!$cf || ($cf['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $back('❌ Aucune vidéo envoyée.');
            }
            if ($cf['error'] !== UPLOAD_ERR_OK || $cf['size'] <= 0 || $cf['size'] > 100 * 1024 * 1024) {
                $back('❌ Vidéo refusée (100 Mo maximum — une intro doit rester courte).');
            }
            $cmap = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
            $cmime = function_exists('mime_content_type') ? @mime_content_type($cf['tmp_name']) : '';
            $cext = $cmap[$cmime] ?? '';
            if ($cext === '') {
                $back('❌ Format refusé : MP4 ou MOV uniquement.');
            }

            $cbase = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
            $cdir = $cbase . '/divers/branding';
            if (!is_dir($cdir)) { @mkdir($cdir, 0775, true); }
            $cname = $what . '-' . $lang . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $cext;
            if (!move_uploaded_file($cf['tmp_name'], $cdir . '/' . $cname)) {
                $back("❌ Impossible d'enregistrer la vidéo.");
            }
            require_once __DIR__ . '/compress.php';
            famiCompressVideoFile($cdir . '/' . $cname);
            brandingUnlinkKey(brandingClipFor($db, $what, $lang));
            widgetSet($db, $setting, 'divers/branding/' . $cname);
            $back('🎬 ' . $wlbl . ' ' . $lbl . ' enregistrée — elle sera jouée sur toutes les formations vidéo.');
        }

        // ── Interrupteur des intros/outros
        if (!empty($_POST['toggle_clips'])) {
            widgetSet($db, 'video_clips_on', !empty($_POST['video_clips_on']) ? '1' : '0');
            $back(!empty($_POST['video_clips_on'])
                ? '🎬 Intro / outro activées.'
                : '🎬 Intro / outro désactivées (les vidéos sont conservées).');
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

        require_once __DIR__ . '/compress.php';
        famiCompressImageFile($dir . '/' . $name, 1920);
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
            $servedNow = (function_exists('currentLang') ? currentLang() : 'fr') === $lang;
            ?>
            <div style="flex:1; min-width:280px;">
                <div style="font-weight:800; color:#244230; margin-bottom:8px;">
                    <?= $flag ?> <?= htmlspecialchars($label) ?>
                    <?php if ($servedNow): ?>
                        <span style="font-size:.7rem; font-weight:800; background:#2d5a37; color:#fff; border-radius:999px; padding:2px 8px; margin-left:6px;">AFFICHÉE ACTUELLEMENT</span>
                    <?php endif; ?>
                </div>

                <?php if ($url !== ''): ?>
                    <div style="position:relative; border-radius:12px; overflow:hidden; border:1px solid #d9e3dc; background:#111 url('<?= htmlspecialchars($url) ?>') center/cover no-repeat; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center;">
                        <div style="height:100%; aspect-ratio:9/16; background:#0c1a11; display:flex; align-items:center; justify-content:center; color:#9fb8a6; font-size:.72rem; font-weight:700;">vidéo 9:16</div>
                    </div>
                    <div class="muted" style="font-size:.78rem; margin-top:6px; word-break:break-all;">📁 <?= htmlspecialchars(basename($key)) ?></div>
                <?php else: ?>
                    <div style="border:2px dashed #cfdad3; border-radius:12px; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center; color:#8a968f; font-style:italic; font-size:.85rem; text-align:center; padding:10px;">
                        Aucune image<?= $lang === 'nl' ? '<br><small>(la version française sera utilisée)</small>' : '' ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="parametres.php#createur" enctype="multipart/form-data" style="margin-top:10px;" id="bdForm-<?= $lang ?>">
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
                    <form method="POST" action="parametres.php#createur" style="margin-top:8px;" onsubmit="return confirm('Retirer cette image ?');">
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

        <?php brandingClipsCard($db); ?>
        <?php
    }
}

if (!function_exists('brandingClipsCard')) {
    /** Carte « Intro / Outro » — une vidéo avant, une après, par langue. */
    function brandingClipsCard($db)
    {
        require_once __DIR__ . '/ui_switch.php';
        $on = brandingClipsOn($db);

        $clip = function ($what, $lang, $flag) use ($db) {
            $key = brandingClipFor($db, $what, $lang);
            $url = ($key !== '' && function_exists('moduleFileUrl')) ? moduleFileUrl($key) : '';
            $id = $what . '-' . $lang;
            ?>
            <div style="flex:1; min-width:260px;">
                <div style="font-weight:800; color:#244230; margin-bottom:8px;"><?= $flag ?> <?= $what === 'outro' ? 'Outro' : 'Intro' ?></div>

                <?php if ($url !== ''): ?>
                    <video src="<?= htmlspecialchars($url) ?>" controls preload="metadata"
                           style="width:100%; aspect-ratio:16/9; background:#0c1a11; border-radius:12px; border:1px solid #d9e3dc;"></video>
                    <div style="color:#7a8a80; font-size:.78rem; margin-top:6px; word-break:break-all;">📁 <?= htmlspecialchars(basename($key)) ?></div>
                <?php else: ?>
                    <div style="border:2px dashed #cfdad3; border-radius:12px; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center; color:#8a968f; font-style:italic; font-size:.85rem; text-align:center; padding:10px;">
                        Aucune vidéo<?= $lang === 'nl' ? '<br><small>(la version française sera utilisée)</small>' : '' ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="parametres.php#createur" enctype="multipart/form-data" style="margin-top:10px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_branding">
                    <input type="hidden" name="kind" value="clip">
                    <input type="hidden" name="what" value="<?= htmlspecialchars($what) ?>">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
                    <label class="bd-drop">
                        <input type="file" name="clip" accept="video/mp4,video/quicktime,.mp4,.mov" required
                               onchange="var n=this.closest('.bd-drop').querySelector('.bd-name'); if(this.files.length){n.textContent='✓ '+this.files[0].name; n.hidden=false;}">
                        <span class="bd-ico">🎬</span>
                        <span class="bd-txt">
                            <strong><?= $url !== '' ? 'Remplacer' : 'Déposer une vidéo' ?></strong>
                            <small>MP4 ou MOV · 100 Mo max · quelques secondes suffisent</small>
                        </span>
                        <span class="bd-name" hidden></span>
                    </label>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px; width:100%;">
                        <?= $url !== '' ? 'Remplacer' : 'Enregistrer' ?>
                    </button>
                </form>

                <?php if ($url !== ''): ?>
                    <form method="POST" action="parametres.php#createur" style="margin-top:8px;" onsubmit="return confirm('Retirer cette vidéo ?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_branding">
                        <input type="hidden" name="kind" value="clip">
                        <input type="hidden" name="what" value="<?= htmlspecialchars($what) ?>">
                        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
                        <input type="hidden" name="remove_clip" value="1">
                        <button type="submit" class="btn" style="background:#fdecec; color:#b3261e;">🗑️ Retirer</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php
        };
        ?>
        <div class="pref-block">
            <div class="pref-head">
                <h3 style="color:#244230;">🎬 Créateur — intro &amp; outro des vidéos</h3>
                <form method="POST" action="parametres.php#createur">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_branding">
                    <input type="hidden" name="toggle_clips" value="1">
                    <label class="fsw">
                        <input type="checkbox" name="video_clips_on" value="1" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="fsw-track"></span>
                    </label>
                </form>
            </div>
            <div class="pref-body<?= $on ? '' : ' pref-off' ?>">
                <p class="muted">Une courte vidéo jouée <strong>avant</strong> chaque formation (logo, message d'accueil) et une autre <strong>après</strong> (rappel, coordonnées). Le lecteur les enchaîne automatiquement.</p>
                <p class="muted" style="font-size:.84rem;">💡 <strong>Rien n'est ré-encodé</strong> : les vidéos ne sont pas collées bout à bout, le lecteur les joue à la suite. Changer l'intro change <strong>toutes</strong> les formations aussitôt, sans retraiter quoi que ce soit. Une par langue (repli sur le français si la néerlandaise manque). Gardez-les courtes : elles seront vues à chaque formation.</p>

                <div style="margin-top:16px;">
                    <div style="font-weight:800; color:#244230; margin-bottom:10px;">🇫🇷 Version française</div>
                    <div style="display:flex; gap:20px; flex-wrap:wrap;">
                        <?php $clip('intro', 'fr', '▶'); ?>
                        <?php $clip('outro', 'fr', '🏁'); ?>
                    </div>
                </div>
                <div style="margin-top:20px; padding-top:18px; border-top:1px dashed #dfe6e0;">
                    <div style="font-weight:800; color:#244230; margin-bottom:10px;">🇳🇱 Version néerlandaise</div>
                    <div style="display:flex; gap:20px; flex-wrap:wrap;">
                        <?php $clip('intro', 'nl', '▶'); ?>
                        <?php $clip('outro', 'nl', '🏁'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
