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
            'pdf'       => ['dir' => 'modules/pdf',        'label' => 'PDF',                       'listable' => true,  'type' => 'pdf'],
            'video'     => ['dir' => 'modules/video',      'label' => 'Vidéos',                    'listable' => true,  'type' => 'video'],
            'video_raw' => ['dir' => 'modules/video_raw',  'label' => 'Vidéos (sources en attente)', 'listable' => true, 'type' => 'video'],
            'images'    => ['dir' => 'modules/pdf_images', 'label' => 'Images extraites des PDF',  'listable' => true,  'type' => 'image'],
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
        $imgOwners = [];
        try {
            $rows = $db->query("SELECT id, nom, nom_nl, pdf_path, video_path, video_src_path, contenu_by, contenu_images FROM modules WHERE pdf_path IS NOT NULL OR video_path IS NOT NULL OR video_src_path IS NOT NULL OR contenu_images IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                foreach (['pdf_path', 'video_path', 'video_src_path'] as $col) {
                    $k = (string) ($r[$col] ?? '');
                    if ($k !== '') { $owners[$k] = $r; }
                }
                // Images extraites : rattachées à leur module via contenu_images (JSON de clés).
                $imgs = json_decode((string) ($r['contenu_images'] ?? ''), true);
                if (is_array($imgs)) {
                    foreach ($imgs as $ik) { if (is_string($ik) && $ik !== '') { $imgOwners[$ik] = $r; } }
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
                        'module' => ($ck === 'images') ? ($imgOwners[$rel] ?? null) : ($owners[$rel] ?? null),
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

if (!function_exists('storageDeleteOne')) {
    /** Supprime UN fichier + nettoie les references en base. Renvoie true si efface. */
    function storageDeleteOne($db, $key, $base, $baseReal)
    {
        $key = (string) $key;
        if ($key === '' || strpos($key, '..') !== false || preg_match('#^[A-Za-z0-9_./-]+$#', $key) !== 1) {
            return false;
        }
        $abs = realpath($base . '/' . $key);
        if ($abs === false || $baseReal === false || strpos($abs, $baseReal) !== 0 || !is_file($abs)) {
            return false;
        }

        // Si c'est le PDF d'un module, on supprime aussi ses images extraites.
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

        try {
            $db->prepare("UPDATE modules SET pdf_path = NULL, uniformized = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE pdf_path = ?")->execute([$key]);
            $db->prepare("UPDATE modules SET video_path = NULL, video_status = NULL WHERE video_path = ?")->execute([$key]);
            $db->prepare("UPDATE modules SET video_src_path = NULL WHERE video_src_path = ?")->execute([$key]);

            $st = $db->prepare("SELECT id, contenu_images FROM modules WHERE contenu_images LIKE ?");
            $st->execute(['%' . $key . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $arr = json_decode((string) $r['contenu_images'], true);
                if (is_array($arr) && in_array($key, $arr, true)) {
                    $arr = array_map(function ($k) use ($key) { return ($k === $key) ? '' : $k; }, $arr);
                    $db->prepare("UPDATE modules SET contenu_images = ? WHERE id = ?")->execute([json_encode($arr), (int) $r['id']]);
                }
            }
        } catch (Exception $e) { /* non bloquant */ }

        return true;
    }
}

if (!function_exists('storageHandlePost')) {
    /** Suppression d'un OU plusieurs fichiers (delete_media / delete_media_bulk), protegee par mot de passe admin. */
    function storageHandlePost($db)
    {
        $action = $_POST['action'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($action !== 'delete_media' && $action !== 'delete_media_bulk')) {
            return;
        }
        requireValidCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit(); }

        if ($action === 'delete_media_bulk') {
            $keys = is_array($_POST['media_keys'] ?? null) ? $_POST['media_keys'] : [];
        } else {
            $keys = [(string) ($_POST['media_key'] ?? '')];
        }
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), function ($k) { return $k !== ''; })));

        if (empty($keys)) {
            $_SESSION['module_flash'] = "❌ Aucun fichier selectionne.";
            header('Location: parametres.php#contenu'); exit();
        }
        if (!adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Mot de passe incorrect : aucun fichier supprime.";
            header('Location: parametres.php#contenu'); exit();
        }

        $base = storageBaseDir();
        $baseReal = realpath($base);
        $done = 0;
        foreach ($keys as $key) {
            if (storageDeleteOne($db, $key, $base, $baseReal)) { $done++; }
        }
        $fail = count($keys) - $done;
        $msg = "✅ " . $done . " fichier" . ($done > 1 ? 's' : '') . " supprime" . ($done > 1 ? 's' : '') . " du stockage.";
        if ($fail > 0) { $msg .= " (" . $fail . " introuvable" . ($fail > 1 ? 's' : '') . ")"; }
        $_SESSION['module_flash'] = $msg;
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

        // Arbre des modules -> fil d'Ariane complet pour l'emplacement (parent › enfant › …).
        $tree = [];
        try {
            foreach ($db->query("SELECT id, nom, nom_nl, parent_id FROM modules")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $tree[(int) $m['id']] = $m;
            }
        } catch (Exception $e) { /* table absente */ }
        $storageCrumb = function ($id) use ($tree) {
            $parts = []; $cur = (int) $id; $guard = 0;
            while ($cur && isset($tree[$cur]) && $guard++ < 50) {
                $parts[] = moduleNom($tree[$cur]);
                $cur = (int) ($tree[$cur]['parent_id'] ?? 0);
            }
            return implode(' › ', array_reverse($parts));
        };
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
            <div style="display:flex; gap:8px; margin:6px 0 14px; flex-wrap:wrap; align-items:center;">
                <span class="muted" style="font-weight:700;">Afficher :</span>
                <button type="button" class="btn btn-primary sa-filter" onclick="filterMedia('all', this)">Tous</button>
                <button type="button" class="btn btn-light sa-filter" onclick="filterMedia('pdf', this)">📄 PDF</button>
                <button type="button" class="btn btn-light sa-filter" onclick="filterMedia('video', this)">🎬 Vidéos</button>
                <button type="button" class="btn btn-light sa-filter" onclick="filterMedia('image', this)">🖼 Images extraites</button>
                <button type="button" id="saBulkBtn" class="btn btn-danger" style="margin-left:auto;" disabled onclick="askDeleteSelected()">🗑 Supprimer la sélection (<span id="saBulkN">0</span>)</button>
            </div>
            <div style="overflow-x:auto;">
            <table id="saFileTable">
                <thead>
                    <tr>
                        <th style="text-align:center; width:34px;"><input type="checkbox" id="saCheckAll" onclick="saToggleAll(this)" title="Tout sélectionner"></th>
                        <th style="text-align:left;">Fichier / emplacement</th>
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
                        $icon = $f['type'] === 'video' ? '🎬' : ($f['type'] === 'image' ? '🖼' : '📄');
                        $typeLabel = $f['type'] === 'video' ? 'Vidéo' : ($f['type'] === 'image' ? 'Image' : 'PDF');
                        $isRaw = ($f['cat'] === 'video_raw');
                        $url = function_exists('moduleFileUrl') ? moduleFileUrl($f['key']) : ('media.php?f=' . rawurlencode($f['key']));
                        $rowLabel = ($modName !== '' ? $modName . ' — ' : '') . basename($f['key']);
                    ?>
                    <tr data-type="<?= htmlspecialchars($f['type']) ?>"<?= $f['type'] === 'image' ? ' style="display:none;"' : '' ?>>
                        <td style="text-align:center;"><input type="checkbox" class="sa-check" value="<?= htmlspecialchars($f['key'], ENT_QUOTES) ?>" onchange="saUpdateCount()"></td>
                        <td>
                            <div style="font-weight:700; color:#244230; word-break:break-all;"><?= htmlspecialchars(basename($f['key'])) ?></div>
                            <div class="muted" style="font-size:0.8rem;">
                                <span class="sa-loc" onclick="toggleLoc(this)" style="cursor:pointer; user-select:none;" title="Cliquer pour voir le chemin complet">
                                    <?php if ($modName !== ''): ?>
                                        📍 <?= htmlspecialchars($modName) ?>
                                    <?php else: ?>
                                        <span style="color:#b06a00;">⚠ Orphelin</span>
                                    <?php endif; ?>
                                    <span class="sa-loc-caret" style="opacity:.55;">▸</span>
                                </span>
                                <?php if ($isRaw): ?> · source en attente<?php endif; ?>
                                <div class="sa-loc-full" style="display:none; margin-top:4px; padding-left:8px; border-left:2px solid #d9e3dc;">
                                    <?php if ($modName !== ''): ?>
                                        <div>📂 <?= htmlspecialchars($storageCrumb((int) $mod['id'])) ?></div>
                                    <?php else: ?>
                                        <div class="muted">Rattaché à aucun module du site.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= $icon ?> <?= htmlspecialchars($typeLabel) ?></td>
                        <td style="text-align:right; white-space:nowrap;"><?= htmlspecialchars(storageHumanSize($f['size'])) ?></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($when) ?></td>
                        <td><?= htmlspecialchars($uploader) ?></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="btn btn-light" style="padding:6px 10px;" title="Aperçu"
                                onclick="previewMedia('<?= htmlspecialchars($url, ENT_QUOTES) ?>', '<?= htmlspecialchars($f['type'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rowLabel, ENT_QUOTES) ?>')">👁</button>
                            <a class="btn btn-light" style="padding:6px 10px;" title="Télécharger" href="<?= htmlspecialchars($url) ?>" download>⬇</a>
                            <button type="button" class="btn btn-danger" style="padding:6px 10px;" title="Supprimer"
                                onclick="askDelete(['<?= htmlspecialchars($f['key'], ENT_QUOTES) ?>'])">🗑</button>
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
                <h3 style="color:#c0392b; margin:8px 0 6px;">Supprimer définitivement ?</h3>
                <p class="muted" style="margin:0 0 6px;">Cette action est <strong>irréversible</strong>. Le(s) fichier(s) seront effacés du stockage et les modules concernés perdront ce contenu.</p>
                <p id="saDelName" style="font-weight:700; color:#244230; word-break:break-all; margin:0 0 14px;"></p>
                <form method="POST" action="parametres.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_media_bulk">
                    <div id="saDelKeys"></div>
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
        function askDelete(keys) {
            var box = document.getElementById('saDelKeys');
            box.innerHTML = '';
            keys.forEach(function (k) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'media_keys[]'; inp.value = k;
                box.appendChild(inp);
            });
            document.getElementById('saDelName').textContent = (keys.length === 1)
                ? keys[0].split('/').pop()
                : (keys.length + ' fichiers sélectionnés');
            document.getElementById('deleteMediaModal').classList.add('open');
        }
        function askDeleteSelected() {
            var keys = [];
            document.querySelectorAll('#saFileTable .sa-check:checked').forEach(function (c) { keys.push(c.value); });
            if (!keys.length) { return; }
            askDelete(keys);
        }
        function closeDeleteMedia() {
            document.getElementById('deleteMediaModal').classList.remove('open');
        }
        function saUpdateCount() {
            var n = document.querySelectorAll('#saFileTable .sa-check:checked').length;
            var el = document.getElementById('saBulkN'); if (el) { el.textContent = n; }
            var btn = document.getElementById('saBulkBtn'); if (btn) { btn.disabled = (n === 0); }
        }
        function saToggleAll(cb) {
            document.querySelectorAll('#saFileTable tbody tr').forEach(function (tr) {
                if (tr.style.display === 'none') { return; } // seulement les lignes visibles (filtre courant)
                var c = tr.querySelector('.sa-check');
                if (c) { c.checked = cb.checked; }
            });
            saUpdateCount();
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
        function toggleLoc(el) {
            var full = el.parentNode.querySelector('.sa-loc-full');
            var caret = el.querySelector('.sa-loc-caret');
            if (!full) { return; }
            var open = (full.style.display === 'none' || full.style.display === '');
            full.style.display = open ? 'block' : 'none';
            if (caret) { caret.textContent = open ? '▾' : '▸'; }
        }
        function filterMedia(type, btn) {
            document.querySelectorAll('.sa-filter').forEach(function (b) { b.classList.remove('btn-primary'); b.classList.add('btn-light'); });
            if (btn) { btn.classList.remove('btn-light'); btn.classList.add('btn-primary'); }
            document.querySelectorAll('#saFileTable tbody tr').forEach(function (tr) {
                var t = tr.getAttribute('data-type');
                // « Tous » = PDF + vidéos (les images extraites restent à part, via leur propre filtre).
                var show = (type === 'all') ? (t !== 'image') : (t === type);
                tr.style.display = show ? '' : 'none';
                if (!show) { var c = tr.querySelector('.sa-check'); if (c) { c.checked = false; } } // on décoche ce qui est masqué
            });
            var all = document.getElementById('saCheckAll'); if (all) { all.checked = false; }
            saUpdateCount();
        }
        </script>
        <?php
    }
}
