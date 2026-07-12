<?php
// ============================================================
// storage_admin.php — vue admin du stockage objet (volume monté).
//   Le volume Railway (FAMI_STORAGE_BASE) est monté DANS le conteneur : le PHP
//   lit donc directement les fichiers (taille, date) sans CLI ni mot de passe.
//   Fournit : espace total, liste des PDF/vidéos (module, uploadeur, taille,
//   date), et suppression protégée par le mot de passe admin.
// ============================================================

if (!function_exists('storageBaseDir')) {
    function storageBaseDir()
    {
        $base = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/../uploads');
        return rtrim((string) $base, '/');
    }
}

if (!function_exists('storageHumanSize')) {
    function storageHumanSize($bytes)
    {
        $bytes = (float) $bytes;
        $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
        $dec = ($i === 0) ? 0 : ($bytes >= 100 ? 0 : ($bytes >= 10 ? 1 : 2));
        return number_format($bytes, $dec, ',', ' ') . ' ' . $u[$i];
    }
}

if (!function_exists('storageWalkFiles')) {
    /** [cheminAbsolu => taille] pour tous les fichiers sous $dir (récursif). */
    function storageWalkFiles($dir)
    {
        $out = [];
        if (!is_dir($dir)) { return $out; }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) { $out[$f->getPathname()] = $f->getSize(); }
            }
        } catch (Exception $e) { /* dossier illisible -> ignoré */ }
        return $out;
    }
}

if (!function_exists('storageCategories')) {
    function storageCategories()
    {
        // listable = affiché dans la liste des fichiers ; sinon seulement compté dans le total.
        return [
            'pdf'       => ['dir' => 'modules/pdf',        'label' => 'Documents (Le guide)',     'listable' => true,  'type' => 'pdf'],
            'video'     => ['dir' => 'modules/video',      'label' => 'Vidéos',                    'listable' => true,  'type' => 'video'],
            'video_raw' => ['dir' => 'modules/video_raw',  'label' => 'Vidéos (sources en attente)', 'listable' => true, 'type' => 'video'],
            'images'    => ['dir' => 'modules/pdf_images', 'label' => 'Images extraites des PDF',  'listable' => false, 'type' => 'image'],
        ];
    }
}

if (!function_exists('storageScan')) {
    /** Analyse le volume : total, répartition par catégorie, et liste des fichiers listables. */
    function storageScan($db)
    {
        $base = storageBaseDir();

        // Carte clé_relative -> module propriétaire (pour nom + uploadeur).
        $owners = [];
        try {
            $rows = $db->query("SELECT id, nom, nom_nl, pdf_path, video_path, video_src_path, contenu_by FROM modules WHERE pdf_path IS NOT NULL OR video_path IS NOT NULL OR video_src_path IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                foreach (['pdf_path', 'video_path', 'video_src_path'] as $col) {
                    $k = (string) ($r[$col] ?? '');
                    if ($k !== '') { $owners[$k] = $r; }
                }
            }
        } catch (Exception $e) { /* table absente -> pas de propriétaires */ }

        $total = 0; $count = 0; $byCat = []; $files = [];
        foreach (storageCategories() as $ck => $c) {
            $abs = $base . '/' . $c['dir'];
            $cBytes = 0; $cCount = 0;
            foreach (storageWalkFiles($abs) as $p => $sz) {
                $cBytes += $sz; $cCount++;
                if (!empty($c['listable'])) {
                    $rel = str_replace('\\', '/', substr($p, strlen($base) + 1));
                    $files[] = [
                        'key'    => $rel,
                        'size'   => $sz,
                        'mtime'  => @filemtime($p),
                        'type'   => $c['type'],
                        'cat'    => $ck,
                        'module' => $owners[$rel] ?? null,
                    ];
                }
            }
            $total += $cBytes; $count += $cCount;
            $byCat[$ck] = ['label' => $c['label'], 'bytes' => $cBytes, 'count' => $cCount];
        }
        usort($files, function ($a, $b) { return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0); });
        return ['base' => $base, 'total' => $total, 'count' => $count, 'byCat' => $byCat, 'files' => $files];
    }
}

if (!function_exists('storageUserNames')) {
    /** [id => "Prénom Nom"] pour une liste d'identifiants uploadeurs. */
    function storageUserNames($db, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return []; }
        $out = [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, prenom, nom FROM utilisateurs WHERE id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $out[(int) $u['id']] = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
            }
        } catch (Exception $e) { /* table absente */ }
        return $out;
    }
}

if (!function_exists('storageHandlePost')) {
    /** Traite la suppression d'un fichier (POST action=delete_media), protégée par le mot de passe admin. */
    function storageHandlePost($db)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'delete_media') {
            return;
        }
        requireValidCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }

        $key = (string) ($_POST['media_key'] ?? '');
        $pw  = (string) ($_POST['admin_password'] ?? '');

        if ($key === '' || strpos($key, '..') !== false || preg_match('#^[A-Za-z0-9_./-]+$#', $key) !== 1) {
            $_SESSION['module_flash'] = "❌ Clé de fichier invalide : suppression annulée.";
            header('Location: parametres.php#contenu'); exit();
        }
        if (!adminPasswordOk($db, $pw)) {
            $_SESSION['module_flash'] = "❌ Mot de passe incorrect : fichier conservé.";
            header('Location: parametres.php#contenu'); exit();
        }

        $base = storageBaseDir();
        $baseReal = realpath($base);
        $abs = realpath($base . '/' . $key);
        if ($abs === false || $baseReal === false || strpos($abs, $baseReal) !== 0 || !is_file($abs)) {
            $_SESSION['module_flash'] = "❌ Fichier introuvable : rien supprimé.";
            header('Location: parametres.php#contenu'); exit();
        }

        // Si c'est le PDF d'un module, on supprime aussi ses images extraites (sinon elles restent orphelines).
        try {
            $st = $db->prepare("SELECT contenu_images FROM modules WHERE pdf_path = ? LIMIT 1");
            $st->execute([$key]);
            $owner = $st->fetch(PDO::FETCH_ASSOC);
            if ($owner) {
                $imgs = json_decode((string) ($owner['contenu_images'] ?? '[]'), true);
                if (is_array($imgs)) {
                    foreach ($imgs as $imgKey) {
                        $ia = realpath($base . '/' . (string) $imgKey);
                        if ($ia !== false && strpos($ia, $baseReal) === 0 && is_file($ia)) { @unlink($ia); }
                    }
                }
            }
        } catch (Exception $e) { /* non bloquant */ }

        @unlink($abs);

        // On nettoie les références en base pour ne pas pointer vers un fichier disparu.
        try {
            $db->prepare("UPDATE modules SET pdf_path = NULL, uniformized = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE pdf_path = ?")->execute([$key]);
            $db->prepare("UPDATE modules SET video_path = NULL, video_status = NULL WHERE video_path = ?")->execute([$key]);
            $db->prepare("UPDATE modules SET video_src_path = NULL WHERE video_src_path = ?")->execute([$key]);
        } catch (Exception $e) { /* non bloquant */ }

        $_SESSION['module_flash'] = "✅ Fichier supprimé du stockage (" . htmlspecialchars(basename($key)) . ").";
        header('Location: parametres.php#contenu'); exit();
    }
}

if (!function_exists('renderStorageTab')) {
    /** Contenu de l'onglet « Contenu » (admin) : espace occupé + liste + suppression. */
    function renderStorageTab($db)
    {
        $scan = storageScan($db);
        $ids = [];
        foreach ($scan['files'] as $f) {
            if ($f['module'] && !empty($f['module']['contenu_by'])) { $ids[] = (int) $f['module']['contenu_by']; }
        }
        $names = storageUserNames($db, $ids);
        ?>
        <div class="card">
            <h2 style="margin-top:0; color:#2d5a37;">💾 Stockage — espace occupé</h2>
            <p class="muted" style="margin-top:-6px;">Lecture directe du volume monté dans l'app — aucun identifiant nécessaire.</p>

            <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:stretch; margin:14px 0 4px;">
                <div style="flex:1; min-width:220px; background:#eef7f0; border:1px solid #cfe3d5; border-radius:14px; padding:20px 22px;">
                    <div style="font-size:0.8rem; letter-spacing:.08em; text-transform:uppercase; color:#5a6b60; font-weight:700;">Total occupé</div>
                    <div style="font-size:2.2rem; font-weight:800; color:#1E4D2B; line-height:1.1; margin-top:4px;"><?= htmlspecialchars(storageHumanSize($scan['total'])) ?></div>
                    <div class="muted" style="margin-top:2px;"><?= (int) $scan['count'] ?> fichier<?= $scan['count'] > 1 ? 's' : '' ?> au total</div>
                </div>
                <div style="flex:2; min-width:260px; display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px;">
                    <?php foreach ($scan['byCat'] as $cat): ?>
                        <div style="background:#fff; border:1px solid #e1e8e3; border-radius:12px; padding:12px 14px;">
                            <div style="font-size:0.82rem; color:#5a6b60; font-weight:700;"><?= htmlspecialchars($cat['label']) ?></div>
                            <div style="font-weight:800; color:#2d5a37; font-size:1.1rem;"><?= htmlspecialchars(storageHumanSize($cat['bytes'])) ?></div>
                            <div class="muted" style="font-size:0.8rem;"><?= (int) $cat['count'] ?> fichier<?= $cat['count'] > 1 ? 's' : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:18px;">
            <h2 style="margin-top:0; color:#2d5a37;">📂 Mes fichiers (PDF &amp; vidéos)</h2>
            <?php if (empty($scan['files'])): ?>
                <p class="muted">Aucun fichier pour l'instant.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left;">Fichier / module</th>
                        <th style="text-align:left;">Type</th>
                        <th style="text-align:right;">Taille</th>
                        <th style="text-align:left;">Ajouté le</th>
                        <th style="text-align:left;">Par</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scan['files'] as $f): ?>
                    <?php
                        $mod = $f['module'];
                        $modName = $mod ? moduleNom($mod) : '';
                        $uploaderId = $mod ? (int) ($mod['contenu_by'] ?? 0) : 0;
                        $uploader = $uploaderId && isset($names[$uploaderId]) && $names[$uploaderId] !== '' ? $names[$uploaderId] : '—';
                        $when = $f['mtime'] ? date('d/m/Y H:i', (int) $f['mtime']) : '—';
                        $icon = $f['type'] === 'video' ? '🎬' : '📄';
                        $isRaw = ($f['cat'] === 'video_raw');
                        $url = function_exists('moduleFileUrl') ? moduleFileUrl($f['key']) : ('media.php?f=' . rawurlencode($f['key']));
                        $rowLabel = ($modName !== '' ? $modName . ' — ' : '') . basename($f['key']);
                    ?>
                    <tr>
                        <td>
                            <?php if ($modName !== ''): ?>
                                <strong style="color:#2d5a37;"><?= htmlspecialchars($modName) ?></strong>
                            <?php else: ?>
                                <span style="color:#b06a00; font-weight:700;">⚠ Orphelin</span>
                            <?php endif; ?>
                            <?php if ($isRaw): ?><span class="muted" style="font-size:0.78rem;"> · source en attente</span><?php endif; ?>
                            <div class="muted" style="font-size:0.76rem; word-break:break-all;"><?= htmlspecialchars(basename($f['key'])) ?></div>
                        </td>
                        <td><?= $icon ?> <?= $f['type'] === 'video' ? 'Vidéo' : 'Document' ?></td>
                        <td style="text-align:right; white-space:nowrap;"><?= htmlspecialchars(storageHumanSize($f['size'])) ?></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($when) ?></td>
                        <td><?= htmlspecialchars($uploader) ?></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="btn btn-light" style="padding:6px 10px;" title="Aperçu"
                                onclick="previewMedia('<?= htmlspecialchars($url, ENT_QUOTES) ?>', '<?= htmlspecialchars($f['type'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rowLabel, ENT_QUOTES) ?>')">👁</button>
                            <a class="btn btn-light" style="padding:6px 10px;" title="Télécharger" href="<?= htmlspecialchars($url) ?>" download>⬇</a>
                            <button type="button" class="btn btn-danger" style="padding:6px 10px;" title="Supprimer"
                                onclick="askDeleteMedia('<?= htmlspecialchars($f['key'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rowLabel, ENT_QUOTES) ?>')">🗑</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Modale de suppression (forte confirmation + mot de passe admin) -->
        <div id="deleteMediaModal" class="sa-modal">
            <div class="sa-modal-box">
                <div style="font-size:2.6rem;">🗑️</div>
                <h3 style="color:#c0392b; margin:8px 0 6px;">Supprimer définitivement ce fichier ?</h3>
                <p class="muted" style="margin:0 0 6px;">Cette action est <strong>irréversible</strong>. Le fichier sera effacé du stockage et le module concerné perdra ce contenu.</p>
                <p id="saDelName" style="font-weight:700; color:#244230; word-break:break-all; margin:0 0 14px;"></p>
                <form method="POST" action="parametres.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="media_key" id="saDelKey" value="">
                    <label style="display:block; font-weight:700; color:#244230; margin:0 0 4px; text-align:left;">Mot de passe administrateur</label>
                    <input type="password" name="admin_password" required autocomplete="off" placeholder="Mot de passe de verrouillage"
                        style="width:100%; box-sizing:border-box; padding:10px; border:1px solid #ccc; border-radius:8px;">
                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
                        <button type="button" class="btn btn-light" onclick="closeDeleteMedia()">Annuler</button>
                        <button type="submit" class="btn btn-danger">Oui, supprimer définitivement</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Modale d'aperçu (PDF / vidéo) -->
        <div id="previewMediaModal" class="sa-modal">
            <div class="sa-modal-box" style="max-width:860px; text-align:left;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px;">
                    <strong id="saPrevTitle" style="color:#2d5a37; word-break:break-all;"></strong>
                    <button type="button" class="btn btn-light" onclick="closePreviewMedia()">✕ Fermer</button>
                </div>
                <div id="saPrevBody" style="min-height:200px;"></div>
            </div>
        </div>

        <style>
        .sa-modal { position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; padding:20px; }
        .sa-modal.open { display:flex; }
        .sa-modal-box { background:#fff; border-radius:16px; padding:28px; max-width:440px; width:100%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.35); max-height:92vh; overflow:auto; }
        .btn-danger { background:#c94a42; color:#fff; }
        </style>
        <script>
        function askDeleteMedia(key, label) {
            document.getElementById('saDelKey').value = key;
            document.getElementById('saDelName').textContent = label;
            document.getElementById('deleteMediaModal').classList.add('open');
        }
        function closeDeleteMedia() {
            document.getElementById('deleteMediaModal').classList.remove('open');
        }
        function previewMedia(url, type, label) {
            document.getElementById('saPrevTitle').textContent = label;
            var body = document.getElementById('saPrevBody');
            if (type === 'video') {
                body.innerHTML = '<video controls playsinline style="width:100%; max-height:72vh; border-radius:10px; background:#000;" src="' + url + '"></video>';
            } else if (type === 'image') {
                body.innerHTML = '<img src="' + url + '" alt="" style="max-width:100%; max-height:72vh; display:block; margin:0 auto; border-radius:10px;">';
            } else {
                body.innerHTML = '<iframe src="' + url + '" style="width:100%; height:72vh; border:none; border-radius:10px; background:#f4f7f6;"></iframe>';
            }
            document.getElementById('previewMediaModal').classList.add('open');
        }
        function closePreviewMedia() {
            document.getElementById('previewMediaModal').classList.remove('open');
            document.getElementById('saPrevBody').innerHTML = '';
        }
        </script>
        <?php
    }
}
