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
// Additif : autonome (image sur le volume, clé mémorisée via widgetGet/widgetSet).
// ============================================================

if (!function_exists('brandingVideoBackdrop')) {
    /** Clé (volume) de l'image d'habillage des vidéos, ou '' si aucune. */
    function brandingVideoBackdrop($db)
    {
        return function_exists('widgetGet') ? trim((string) widgetGet($db, 'video_backdrop', '')) : '';
    }

    /** URL prête à l'emploi de cette image, ou '' si aucune. */
    function brandingVideoBackdropUrl($db)
    {
        $key = brandingVideoBackdrop($db);
        if ($key === '') { return ''; }
        return function_exists('moduleFileUrl') ? moduleFileUrl($key) : ('media.php?f=' . rawurlencode($key));
    }
}

if (!function_exists('brandingHandlePost')) {
    function brandingHandlePost($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
        if (($_POST['action'] ?? '') !== 'set_branding') { return; }
        requireValidCSRF();

        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');

        // Suppression demandée.
        if (!empty($_POST['remove_backdrop'])) {
            $old = brandingVideoBackdrop($db);
            if ($old !== '') {
                $abs = realpath($base . '/' . $old);
                $baseReal = realpath($base);
                if ($abs !== false && $baseReal !== false && strpos($abs, $baseReal) === 0 && is_file($abs)) { @unlink($abs); }
            }
            widgetSet($db, 'video_backdrop', '');
            $_SESSION['module_flash'] = '🎨 Habillage vidéo retiré (les bandes redeviennent noires).';
            header('Location: parametres.php#prefs');
            exit();
        }

        $f = $_FILES['backdrop'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $_SESSION['module_flash'] = '❌ Aucune image envoyée.';
            header('Location: parametres.php#prefs');
            exit();
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) {
            $_SESSION['module_flash'] = '❌ Image refusée (5 Mo maximum).';
            header('Location: parametres.php#prefs');
            exit();
        }
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
        $ext = $map[$mime] ?? '';
        if ($ext === '') {
            $_SESSION['module_flash'] = '❌ Format refusé : JPEG, PNG ou WebP uniquement.';
            header('Location: parametres.php#prefs');
            exit();
        }

        $dir = $base . '/branding';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = 'video-backdrop_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            $_SESSION['module_flash'] = "❌ Impossible d'enregistrer l'image.";
            header('Location: parametres.php#prefs');
            exit();
        }

        // L'ancienne image ne sert plus à rien : on la supprime du volume (pas de fuite de stockage).
        $old = brandingVideoBackdrop($db);
        if ($old !== '') {
            $abs = realpath($base . '/' . $old);
            $baseReal = realpath($base);
            if ($abs !== false && $baseReal !== false && strpos($abs, $baseReal) === 0 && is_file($abs)) { @unlink($abs); }
        }

        widgetSet($db, 'video_backdrop', 'branding/' . $name);
        $_SESSION['module_flash'] = '🎨 Habillage vidéo enregistré — il remplace les bandes noires sur toutes les vidéos.';
        header('Location: parametres.php#prefs');
        exit();
    }
}

if (!function_exists('brandingCard')) {
    function brandingCard($db)
    {
        $url = brandingVideoBackdropUrl($db);
        ?>
        <div class="pref-block">
            <h3 style="margin:0 0 6px; color:#244230;">🎨 Créateur — habillage des vidéos</h3>
            <p class="muted">Une vidéo filmée au téléphone (format <strong>9:16</strong>, portrait) laisse deux grosses <strong>bandes noires</strong> sur les côtés du lecteur. Dépose une image ici : elle s'affiche <strong>derrière</strong> la vidéo et remplit ces bandes. Les vidéos en 16:9 la recouvrent entièrement, donc elle ne se voit pas — aucun risque.</p>
            <p class="muted" style="font-size:.84rem;">💡 Rien n'est ré-encodé : changer l'image change <strong>toutes</strong> les vidéos, immédiatement. Idéal : une image large (1920×1080), plutôt sombre et peu chargée, pour ne pas voler la vedette à la vidéo. JPEG, PNG ou WebP, 5 Mo maximum.</p>

            <?php if ($url !== ''): ?>
                <div style="margin:12px 0; position:relative; max-width:420px;">
                    <div style="position:relative; border-radius:12px; overflow:hidden; border:1px solid #d9e3dc; background:#111 url('<?= htmlspecialchars($url) ?>') center/cover no-repeat; aspect-ratio:16/9; display:flex; align-items:center; justify-content:center;">
                        <div style="height:100%; aspect-ratio:9/16; background:#0c1a11; display:flex; align-items:center; justify-content:center; color:#9fb8a6; font-size:.75rem; font-weight:700;">vidéo 9:16</div>
                    </div>
                    <div class="muted" style="font-size:.8rem; margin-top:6px;">Aperçu : voilà ce que verra l'apprenant avec une vidéo portrait.</div>
                </div>
            <?php else: ?>
                <div class="muted" style="margin:12px 0; font-style:italic;">Aucune image : les bandes restent noires.</div>
            <?php endif; ?>

            <form method="POST" action="parametres.php#prefs" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_branding">
                <input type="file" name="backdrop" accept="image/jpeg,image/png,image/webp" required>
                <button type="submit" class="btn btn-primary"><?= $url !== '' ? "Remplacer l'image" : "Enregistrer l'image" ?></button>
            </form>

            <?php if ($url !== ''): ?>
                <form method="POST" action="parametres.php#prefs" style="margin-top:10px;" onsubmit="return confirm('Retirer l\'habillage ? Les bandes redeviendront noires.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_branding">
                    <input type="hidden" name="remove_backdrop" value="1">
                    <button type="submit" class="btn" style="background:#fdecec; color:#b3261e;">🗑️ Retirer l'habillage</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
