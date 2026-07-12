<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/contrib_settings.php';
require_once 'includes/storage_stats.php';

$actorRole = (string) ($_SESSION['role'] ?? '');
$isAdminActor = ($actorRole === 'admin');
$reqAction = $_POST['action'] ?? '';

// L'admin a tous les droits. Un contributeur autorisé (profil coché) ne peut faire QUE
// « create » ou « content » — et seulement dans une zone autorisée, vérifié par action ci-dessous.
if (!$isAdminActor) {
    if (!in_array($reqAction, ['create', 'content'], true) || !contribRoleAllowed($db, $actorRole)) {
        header('Location: index.php');
        exit();
    }
}

ensureModulesTable($db);

// Nettoie la liste des profils soumis (uniquement des clés valides)
function sanitizeModuleRoles($input)
{
    global $db;
    if (!is_array($input)) {
        return '';
    }
    $valid = array_keys(moduleProfiles($db));
    $kept = array_values(array_intersect($valid, $input));
    return implode(',', $kept); // vide = tous
}

// Sécurise la cible de redirection (pas d'open redirect)
function safeReturn($value, $default = 'index.php')
{
    $value = (string) $value;
    foreach (['index.php', 'parametres.php', 'module.php'] as $allowed) {
        if (strpos($value, $allowed) === 0) {
            return $value;
        }
    }
    return $default;
}

// Chemin absolu d'un fichier de module (volume Railway, ou ancien uploads/).
function moduleFileAbsPath($rel)
{
    $rel = (string) $rel;
    if ($rel === '') { return ''; }
    if (strpos($rel, 'uploads/') === 0) { return __DIR__ . '/' . $rel; }
    $base = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
    return rtrim($base, '/') . '/' . $rel;
}

// Gère l'upload d'une image d'icône -> renvoie le chemin relatif, ou null
function handleModuleIconUpload()
{
    if (empty($_FILES['icon_image']) || ($_FILES['icon_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['icon_image'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) {
        return null; // 2 Mo max
    }
    $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
    $map = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    ];
    if (isset($map[$mime])) {
        $ext = $map[$mime];
    } else {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
            return null;
        }
        if ($ext === 'jpeg') { $ext = 'jpg'; }
    }
    $dir = __DIR__ . '/uploads/modules/icons';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = 'icon_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return 'uploads/modules/icons/' . $name;
}

// Upload générique d'un fichier de module (pdf, vidéo) -> chemin relatif ou null
// Slug lisible à partir du nom du module (pour des noms de fichiers clairs).
function moduleFileSlug($nom)
{
    $s = (string) $nom;
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t !== false && $t !== '') { $s = $t; }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return $s !== '' ? substr($s, 0, 40) : 'fichier';
}

// Efface un fichier du volume en toute sécurité (dans FAMI_STORAGE_BASE).
function volumeUnlink($key)
{
    $key = (string) $key;
    if ($key === '') { return; }
    $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/uploads');
    $abs = realpath($base . '/' . $key);
    $baseReal = realpath($base);
    if ($abs !== false && $baseReal !== false && strpos($abs, $baseReal) === 0 && is_file($abs)) { @unlink($abs); }
}

function handleModuleFileUpload($field, array $allowedMap, $maxSize, $subdir, $namePrefix = '')
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > $maxSize) {
        return null;
    }
    $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : '';
    if (isset($allowedMap[$mime])) {
        $ext = $allowedMap[$mime];
    } else {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array_values($allowedMap), true)) {
            return null;
        }
    }
    // Stockage sur le volume persistant (Railway) via FAMI_STORAGE_BASE ; fallback local.
    $storeBase = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
    $dir = $storeBase . '/modules/' . $subdir;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $prefix = ($namePrefix !== '') ? $namePrefix : $subdir;
    $name = $prefix . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    // Clé relative (servie par media.php) — plus de préfixe « uploads/ ».
    return 'modules/' . $subdir . '/' . $name;
}

// Lance la compression vidéo 720p (ffmpeg) EN TÂCHE DE FOND : l'utilisateur n'attend pas.
// Le worker video_transcode.php ré-encode la source brute puis met à jour l'état du module.
function spawnVideoTranscode($rawKey, $moduleId)
{
    $rawKey = (string) $rawKey;
    $moduleId = (int) $moduleId;
    if ($rawKey === '' || $moduleId <= 0) {
        return;
    }
    // Sous Windows (dév local), on ne lance pas : le worker tourne sur le serveur Linux (Railway/OVH).
    if (stripos(PHP_OS, 'WIN') === 0 || !function_exists('exec')) {
        return;
    }
    $worker = __DIR__ . '/video_transcode.php';
    $cmd = 'nohup php ' . escapeshellarg($worker) . ' ' . escapeshellarg($rawKey) . ' ' . $moduleId . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

$redirectTo = safeReturn($_POST['return'] ?? '', 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isContainer = !empty($_POST['is_container']) ? 1 : 0;
        $icon = trim((string) ($_POST['icon'] ?? ''));
        $roles = sanitizeModuleRoles($_POST['roles'] ?? []);
        $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int) $_POST['parent_id'] : null;

        // Contributeur non-admin : uniquement dans une zone autorisée (jamais à la racine).
        if (!$isAdminActor && !contribCanCreateIn($db, $parentId, $actorRole)) {
            $_SESSION['module_flash'] = "❌ Vous n'avez pas le droit de créer un module ici.";
            header('Location: ' . ($parentId ? 'module.php?id=' . (int) $parentId : 'index.php'));
            exit();
        }

        if ($nom === '') {
            $_SESSION['module_flash'] = "❌ Le nom du module est obligatoire.";
        } else {
            $iconImage = handleModuleIconUpload();
            $nl = translateModuleToNl($nom, $description);
            // Nouveau module placé EN DERNIER parmi ses frères (sort_order = max + 1),
            // sinon il hérite de 0 et remonte tout en haut de la liste.
            if ($parentId === null) {
                $nextSort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id IS NULL")->fetchColumn();
            } else {
                $ss = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id = ?");
                $ss->execute([$parentId]);
                $nextSort = (int) $ss->fetchColumn();
            }
            $stmt = $db->prepare(
                "INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, icon_image, nom_nl, description_nl, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                mb_substr($nom, 0, 150),
                mb_substr($description, 0, 500),
                $isContainer,
                $parentId,
                mb_substr($icon, 0, 16),
                $roles,
                $iconImage,
                $nl['nom'] !== '' ? $nl['nom'] : null,
                $nl['desc'] !== '' ? $nl['desc'] : null,
                $nextSort,
            ]);
            $_SESSION['module_flash'] = "✅ Module « " . $nom . " » créé.";

            // Contributeur : le module reste EN ATTENTE (caché) jusqu'à validation admin.
            if (!$isAdminActor) {
                require_once __DIR__ . '/includes/events.php';
                eventsEnsureTables($db);
                $newId = (int) $db->lastInsertId();
                $uid = ((int) ($_SESSION['user_id'] ?? 0)) ?: null;
                try { $db->prepare("UPDATE modules SET is_active = 0, content_status = 'pending' WHERE id = ?")->execute([$newId]); } catch (Exception $e) {}
                try { $db->prepare("UPDATE modules SET contenu_by = ? WHERE id = ?")->execute([$uid, $newId]); } catch (Exception $e) {}
                logEvent($db, 'content_submitted', (int) ($_SESSION['user_id'] ?? 0), $newId, 'Nouveau module proposé : ' . $nom);
                $_SESSION['module_flash'] = "✅ Module « " . $nom . " » créé — en attente de validation par un admin.";
            }
        }

        if ($parentId) {
            $redirectTo = 'module.php?id=' . $parentId;
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isContainer = !empty($_POST['is_container']) ? 1 : 0;
        $icon = trim((string) ($_POST['icon'] ?? ''));
        $roles = sanitizeModuleRoles($_POST['roles'] ?? []);

        if ($id > 0 && $nom !== '') {
            $existing = getModuleById($db, $id);
            if ($existing && !empty($existing['is_locked'])) {
                $_SESSION['module_flash'] = "❌ Module verrouillé : déverrouillez-le d'abord pour le modifier.";
            } else {
                $iconImage = $existing['icon_image'] ?? null;
                if (!empty($_POST['remove_icon_image'])) { $iconImage = null; }
                $uploaded = handleModuleIconUpload();
                if ($uploaded !== null) { $iconImage = $uploaded; }

                $nl = translateModuleToNl($nom, $description);
                $stmt = $db->prepare(
                    "UPDATE modules SET nom = ?, description = ?, is_container = ?, icon = ?, roles = ?, icon_image = ?, nom_nl = ?, description_nl = ? WHERE id = ?"
                );
                $stmt->execute([
                    mb_substr($nom, 0, 150),
                    mb_substr($description, 0, 500),
                    $isContainer,
                    mb_substr($icon, 0, 16),
                    $roles,
                    $iconImage,
                    $nl['nom'] !== '' ? $nl['nom'] : null,
                    $nl['desc'] !== '' ? $nl['desc'] : null,
                    $id,
                ]);
                $_SESSION['module_flash'] = "✅ Module « " . $nom . " » modifié.";
            }
        } else {
            $_SESSION['module_flash'] = "❌ Modification impossible (nom obligatoire).";
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $module = getModuleById($db, $id);
            if ($module && !empty($module['is_locked'])) {
                $_SESSION['module_flash'] = "❌ Module verrouillé : déverrouillez-le d'abord pour changer son statut.";
            } else {
                $db->prepare("UPDATE modules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $_SESSION['module_flash'] = "✅ Statut du module mis à jour.";
            }
        }
    } elseif ($action === 'content') {
        $id = (int) ($_POST['id'] ?? 0);
        $module = $id > 0 ? getModuleById($db, $id) : null;
        // Contributeur non-admin : uniquement dans une zone autorisée.
        if (!$isAdminActor && (!$module || !contribCanAddContent($db, $module, $actorRole))) {
            $_SESSION['module_flash'] = "❌ Vous n'avez pas le droit d'ajouter du contenu ici.";
            header('Location: index.php');
            exit();
        }
        if ($module && !empty($module['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage requis, contenu inchangé.";
            $redirectTo = 'module.php?id=' . $id;
        } elseif ($module) {
            $pdfPath     = $module['pdf_path'];
            $videoPath   = $module['video_path'];
            $videoStatus = $module['video_status'] ?? null;
            $videoSrc    = $module['video_src_path'] ?? null;

            // Nom de fichier lisible basé sur le module.
            $fSlug = moduleFileSlug($module['nom'] ?? 'contenu');

            // Suppression demandée : on efface aussi le fichier du volume.
            if (!empty($_POST['remove_pdf']))   { volumeUnlink($pdfPath); $pdfPath = null; }
            if (!empty($_POST['remove_video'])) { volumeUnlink($videoPath); volumeUnlink($videoSrc); $videoPath = null; $videoStatus = null; $videoSrc = null; }

            // PDF : limite alignée sur Claude (30 Mo) pour l'uniformisation par l'IA.
            $newPdf = handleModuleFileUpload('pdf_file', ['application/pdf' => 'pdf'], 30 * 1024 * 1024, 'pdf', $fSlug . '-guide');
            if ($newPdf !== null) {
                // Remplacement : on efface l'ancien PDF (guide) + ses images extraites.
                foreach ([$pdfPath, $module['pdf_path'] ?? null] as $oldp) { if ($oldp && $oldp !== $newPdf) { volumeUnlink($oldp); } }
                try {
                    $og = $db->prepare("SELECT pdf_path, contenu_images FROM modules WHERE parent_id = ? AND content_kind = 'ecrit' LIMIT 1");
                    $og->execute([(int) $id]);
                    if ($or = $og->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($or['pdf_path']) && $or['pdf_path'] !== $newPdf) { volumeUnlink($or['pdf_path']); }
                        $oimg = json_decode((string) ($or['contenu_images'] ?? '[]'), true);
                        if (is_array($oimg)) { foreach ($oimg as $ik) { volumeUnlink($ik); } }
                    }
                } catch (Exception $e) {}
                $pdfPath = $newPdf;
            }

            // Vidéo : on range la source brute (jusqu'à 1 Go), puis on lance la compression
            // 720p faststart EN TÂCHE DE FOND. Le teamcoach n'attend pas.
            $startTranscode = false;
            $newVideoRaw = handleModuleFileUpload('video_file', [
                'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'video/quicktime' => 'mov',
                'video/x-msvideo' => 'avi', 'video/x-matroska' => 'mkv', 'video/3gpp' => '3gp', 'video/x-m4v' => 'm4v',
            ], 1024 * 1024 * 1024, 'video_raw', $fSlug . '-video');
            if ($newVideoRaw !== null) {
                // Remplacement : on efface l'ancienne vidéo (720p) + l'ancienne source.
                try {
                    $ov = $db->prepare("SELECT video_path, video_src_path FROM modules WHERE parent_id = ? AND content_kind = 'video' LIMIT 1");
                    $ov->execute([(int) $id]);
                    if ($vr = $ov->fetch(PDO::FETCH_ASSOC)) { volumeUnlink($vr['video_path'] ?? ''); volumeUnlink($vr['video_src_path'] ?? ''); }
                } catch (Exception $e) {}
                volumeUnlink($videoPath); volumeUnlink($videoSrc);
                $videoSrc = $newVideoRaw;
                $videoPath = null; // le transcodage fournira la nouvelle version 720p
                $videoStatus = 'processing';
                $startTranscode = true;
            }

            // Au moins 1 contenu : PDF, vidéo déjà prête, OU vidéo en cours de préparation.
            $hasVideo = (!empty($videoPath) || $videoStatus === 'processing');
            if (empty($pdfPath) && !$hasVideo) {
                $_SESSION['module_flash'] = "❌ Il faut au moins 1 fichier (PDF ou vidéo) : contenu inchangé.";
                $redirectTo = 'module.php?id=' . $id;
            } else {
                $uniformized = (($_POST['uniformize'] ?? '0') === '1') ? 1 : 0;
                $aEvaluer = !empty($_POST['a_evaluer']) ? 1 : 0;
                $contenuIa = $module['contenu_ia'] ?? null;
                $flashMsg = "";

                // S'assurer que la colonne du contenu généré par l'IA existe.
                try {
                    if (!$db->query("SHOW COLUMNS FROM modules LIKE 'contenu_ia'")->fetch()) {
                        $db->exec("ALTER TABLE modules ADD COLUMN contenu_ia MEDIUMTEXT NULL");
                    }
                    if (!$db->query("SHOW COLUMNS FROM modules LIKE 'contenu_by'")->fetch()) {
                        $db->exec("ALTER TABLE modules ADD COLUMN contenu_by INT NULL");
                    }
                    if (!$db->query("SHOW COLUMNS FROM modules LIKE 'contenu_images'")->fetch()) {
                        $db->exec("ALTER TABLE modules ADD COLUMN contenu_images MEDIUMTEXT NULL");
                    }
                    if (!$db->query("SHOW COLUMNS FROM modules LIKE 'quiz_json'")->fetch()) {
                        $db->exec("ALTER TABLE modules ADD COLUMN quiz_json MEDIUMTEXT NULL");
                    }
                } catch (Exception $e) { /* migration non bloquante */ }

                // Mémorise l'auteur du contenu (pour le droit de téléchargement).
                $contenuBy = $module['contenu_by'] ?? null;
                if (empty($contenuBy)) { $contenuBy = ((int) ($_SESSION['user_id'] ?? 0)) ?: null; }
                $contenuImages = $module['contenu_images'] ?? null;
                $quizJson = $module['quiz_json'] ?? null;

                // « Valider et uniformiser » : l'IA lit le PDF et réécrit le contenu.
                if ($uniformized === 1 && $pdfPath !== null && $pdfPath !== '') {
                    require_once __DIR__ . '/includes/ia_settings.php';
                    require_once __DIR__ . '/includes/ai_uniformise.php';
                    $res = aiUniformisePdf($db, moduleFileAbsPath($pdfPath), $pdfPath);
                    if ($res['ok']) {
                        $contenuIa = $res['text'];
                        $contenuImages = !empty($res['images']) ? json_encode($res['images']) : null;
                        $flashMsg = "✅ Contenu uniformisé par l'IA (≈ " . number_format($res['cost_eur'], 3) . " €). Vérifie le rendu ci-dessous.";
                        require_once __DIR__ . '/includes/ia_usage.php';
                        iaLogUsage($db, (int) ($_SESSION['user_id'] ?? 0), 'uniformise', $res['model'], $res['in'], $res['out'], $res['cost_eur'], $id);
                    } else {
                        $uniformized = 0;
                        $flashMsg = "⚠️ Uniformisation IA échouée : " . $res['error'] . " — le PDF est enregistré tel quel.";
                    }
                }

                // Quiz : si le contenu est « à évaluer » et qu'on a le texte uniformisé, on génère le QCM.
                if ($aEvaluer && $uniformized === 1 && $contenuIa) {
                    require_once __DIR__ . '/includes/ia_settings.php';
                    require_once __DIR__ . '/includes/ai_uniformise.php';
                    $qz = aiGenerateQuiz($db, (string) $contenuIa);
                    if ($qz['ok']) {
                        $quizJson = json_encode($qz['quiz']);
                        require_once __DIR__ . '/includes/ia_usage.php';
                        iaLogUsage($db, (int) ($_SESSION['user_id'] ?? 0), 'quiz', (function_exists('iaSelectedModel') ? iaSelectedModel($db) : ''), 0, 0, $qz['cost_eur'], $id);
                        $flashMsg .= " 📝 Quiz généré (" . count($qz['quiz']['questions'] ?? []) . " questions).";
                    } else {
                        $flashMsg .= " ⚠️ Quiz NON généré : " . $qz['error'] . ".";
                    }
                }

                $hasPdf = ($pdfPath !== null && $pdfPath !== '');

                // --- Structuration systematique en sous-modules ---
                // PDF -> sous-module Le guide (contenu ecrit) ; video -> sous-module Video.
                // Le module courant devient le conteneur qui regroupe ce(s) sous-module(s).
                try {
                    if (!$db->query("SHOW COLUMNS FROM modules LIKE 'content_kind'")->fetch()) {
                        $db->exec("ALTER TABLE modules ADD COLUMN content_kind VARCHAR(16) NULL");
                    }
                } catch (Exception $e) { /* migration non bloquante */ }

                $childRoles = (string) ($module['roles'] ?? '');
                $madeGuide = false;
                $madeVideo = false;
                $vidChildId = 0;
                $guideChildId = 0;

                // Sous-module Le guide (contenu ecrit issu du document)
                if ($hasPdf) {
                    $guide = null;
                    try { $guide = $db->query("SELECT id FROM modules WHERE parent_id = " . (int) $id . " AND content_kind = 'ecrit' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
                    if ($guide) {
                        $guideChildId = (int) $guide['id'];
                        $db->prepare("UPDATE modules SET pdf_path = ?, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = ?, a_evaluer = ?, contenu_ia = ?, contenu_by = ?, contenu_images = ?, quiz_json = ? WHERE id = ?")
                           ->execute([$pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson, (int) $guide['id']]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, pdf_path, uniformized, a_evaluer, contenu_ia, contenu_by, contenu_images, quiz_json, content_kind) VALUES (?, ?, 0, ?, '📄', ?, 1, ?, ?, ?, ?, ?, ?, ?, 'ecrit')")
                           ->execute(['Le guide', 'De gids', (int) $id, $childRoles, $pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson]);
                        $guideChildId = (int) $db->lastInsertId();
                    }
                    $madeGuide = true;
                }

                // Sous-module Video (compression 720p en tache de fond)
                if ($hasVideo) {
                    $vid = null;
                    try { $vid = $db->query("SELECT id FROM modules WHERE parent_id = " . (int) $id . " AND content_kind = 'video' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
                    if ($vid) {
                        $vidChildId = (int) $vid['id'];
                        $db->prepare("UPDATE modules SET video_path = ?, video_status = ?, video_src_path = ?, pdf_path = NULL, contenu_by = COALESCE(contenu_by, ?) WHERE id = ?")
                           ->execute([$videoPath, $videoStatus, $videoSrc, $contenuBy, $vidChildId]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, video_path, video_status, video_src_path, contenu_by, content_kind) VALUES (?, ?, 0, ?, '🎬', ?, 1, ?, ?, ?, ?, 'video')")
                           ->execute(['Vidéo', 'Video', (int) $id, $childRoles, $videoPath, $videoStatus, $videoSrc, $contenuBy]);
                        $vidChildId = (int) $db->lastInsertId();
                    }
                    $madeVideo = true;
                }

                // Le module parent devient un conteneur (il ne porte plus de contenu propre).
                $db->prepare("UPDATE modules SET is_container = 1, pdf_path = NULL, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = 0, a_evaluer = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE id = ?")->execute([$id]);

                $structMsg = ($madeGuide && $madeVideo)
                    ? "✅ 2 sous-modules : « Le guide » + « Vidéo »."
                    : ("✅ Sous-module « " . ($madeGuide ? 'Le guide' : 'Vidéo') . " » ajouté.");
                if ($uniformized && $madeGuide) { $structMsg .= " Le guide a été mis en forme par l'IA."; }
                if ($startTranscode && $vidChildId) {
                    spawnVideoTranscode($videoSrc, $vidChildId);
                    $structMsg .= " La vidéo est en préparation (compression automatique).";
                }
                // Contributeur : le contenu déposé reste EN ATTENTE (caché) jusqu'à validation admin.
                if (!$isAdminActor) {
                    require_once __DIR__ . '/includes/events.php';
                    eventsEnsureTables($db);
                    $subIds = array_values(array_filter([(int) $guideChildId, (int) $vidChildId]));
                    if ($subIds) {
                        $ph = implode(',', array_fill(0, count($subIds), '?'));
                        try { $db->prepare("UPDATE modules SET is_active = 0, content_status = 'pending' WHERE id IN ($ph)")->execute($subIds); } catch (Exception $e) {}
                    }
                    logEvent($db, 'content_submitted', (int) ($_SESSION['user_id'] ?? 0), $id, 'Contenu déposé, en attente de validation.');
                    $structMsg .= " En attente de validation par un admin.";
                }
                storageRecordSample($db); // fichiers ajoutés → point d'historique (facturation au pro rata)
                $_SESSION['module_flash'] = trim($flashMsg . ' ' . $structMsg);
                $redirectTo = 'module.php?id=' . $id;
                // Étape de relecture (visuelle par défaut) : l'uploadeur relit/corrige avant publication.
                if ($madeGuide && $uniformized === 1 && $contenuIa && $guideChildId) {
                    $redirectTo = 'module_edit.php?id=' . $guideChildId;
                }
            }
        }
    } elseif ($action === 'toggle_lock') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            if (adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                $db->prepare("UPDATE modules SET is_locked = 1 - is_locked WHERE id = ?")->execute([$id]);
                $_SESSION['module_flash'] = "✅ Verrouillage du module mis à jour.";
            } else {
                $_SESSION['module_flash'] = "❌ Mot de passe de verrouillage incorrect : verrouillage inchangé.";
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $module = getModuleById($db, $id);
            if ($module) {
                if (!adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                    $_SESSION['module_flash'] = "❌ Mot de passe incorrect : suppression annulée.";
                } else {
                    // Suppression RÉCURSIVE : le module + TOUS ses descendants (sous-modules,
                    // sous-sous-modules...), même verrouillés (l'admin a confirmé via l'alerte).
                    // Évite les orphelins que laissait l'ancienne suppression (enfants directs only).
                    $toDelete = [$id];
                    $queue = [$id];
                    $guard = 0;
                    while ($queue && $guard++ < 10000) {
                        $pid = array_shift($queue);
                        $st = $db->prepare("SELECT id FROM modules WHERE parent_id = ?");
                        $st->execute([$pid]);
                        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                            $cid = (int) $cid;
                            $toDelete[] = $cid;
                            $queue[] = $cid;
                        }
                    }
                    $toDelete = array_values(array_unique($toDelete));
                    $ph = implode(',', array_fill(0, count($toDelete), '?'));

                    // Nettoyage du VOLUME : on efface les fichiers (PDF, vidéos, sources, images
                    // extraites) des modules supprimés — aucun intérêt à les garder.
                    try {
                        $fbase = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/uploads');
                        $fbaseReal = realpath($fbase);
                        $fst = $db->prepare("SELECT pdf_path, video_path, video_src_path, contenu_images FROM modules WHERE id IN ($ph)");
                        $fst->execute($toDelete);
                        foreach ($fst->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                            $keys = [];
                            foreach (['pdf_path', 'video_path', 'video_src_path'] as $col) {
                                if (!empty($fr[$col])) { $keys[] = (string) $fr[$col]; }
                            }
                            $imgs = json_decode((string) ($fr['contenu_images'] ?? '[]'), true);
                            if (is_array($imgs)) { foreach ($imgs as $ik) { if (is_string($ik) && $ik !== '') { $keys[] = $ik; } } }
                            foreach ($keys as $k) {
                                $abs = realpath($fbase . '/' . $k);
                                if ($abs !== false && $fbaseReal !== false && strpos($abs, $fbaseReal) === 0 && is_file($abs)) { @unlink($abs); }
                            }
                        }
                    } catch (Exception $e) { /* nettoyage non bloquant */ }

                    $db->prepare("DELETE FROM modules WHERE id IN ($ph)")->execute($toDelete);
                    storageRecordSample($db); // le volume a changé → point d'historique (pro rata)
                    $nbSub = count($toDelete) - 1;
                    $_SESSION['module_flash'] = "✅ Module supprimé" . ($nbSub > 0 ? " (et $nbSub sous-module" . ($nbSub > 1 ? 's' : '') . ")" : "") . ".";
                    if (!empty($module['parent_id'])) {
                        $redirectTo = 'module.php?id=' . (int) $module['parent_id'];
                    }
                }
            }
        }
    } elseif ($action === 'add_profile') {
        ensureProfilesTable($db);
        $libelle = trim((string) ($_POST['profile_label'] ?? ''));
        $cle     = trim((string) ($_POST['profile_key'] ?? ''));
        if ($cle === '') { $cle = $libelle; }
        $cle = strtolower($cle);
        $cle = preg_replace('/[^a-z0-9]+/', '_', $cle);
        $cle = trim((string) $cle, '_');
        if ($libelle === '' || $cle === '') {
            $_SESSION['module_flash'] = "❌ Nom de profil invalide.";
        } else {
            try {
                $db->prepare("INSERT INTO profils (cle, libelle, is_core) VALUES (?, ?, 0)")
                   ->execute([mb_substr($cle, 0, 50), mb_substr($libelle, 0, 100)]);
                $_SESSION['module_flash'] = "✅ Profil « " . $libelle . " » ajouté.";
            } catch (Exception $e) {
                $_SESSION['module_flash'] = "❌ Ce profil existe déjà (clé « " . $cle . " »).";
            }
        }
        $redirectTo = 'parametres.php';
    } elseif ($action === 'delete_profile') {
        ensureProfilesTable($db);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT libelle, is_locked FROM profils WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $prof = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prof) {
                $_SESSION['module_flash'] = "❌ Profil introuvable.";
            } elseif (!empty($prof['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                $_SESSION['module_flash'] = "❌ Profil verrouillé : mot de passe de verrouillage incorrect, suppression annulée.";
            } else {
                $db->prepare("DELETE FROM profils WHERE id = ?")->execute([$id]);
                $_SESSION['module_flash'] = "✅ Profil « " . $prof['libelle'] . " » supprimé.";
            }
        }
        $redirectTo = 'parametres.php';
    } elseif ($action === 'toggle_lock_profile') {
        ensureProfilesTable($db);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            if (adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                $db->prepare("UPDATE profils SET is_locked = 1 - is_locked WHERE id = ?")->execute([$id]);
                $_SESSION['module_flash'] = "✅ Verrouillage du profil mis à jour.";
            } else {
                $_SESSION['module_flash'] = "❌ Mot de passe de verrouillage incorrect : verrouillage inchangé.";
            }
        }
        $redirectTo = 'parametres.php';
    } elseif ($action === 'translate_all') {
        // Traduit en NL tous les modules encore sans traduction (ex : "Aide" créé par migration)
        $rows = $db->query("SELECT id, nom, description FROM modules WHERE (nom_nl IS NULL OR nom_nl = '')")->fetchAll(PDO::FETCH_ASSOC);
        $done = 0;
        $upd = $db->prepare("UPDATE modules SET nom_nl = ?, description_nl = ? WHERE id = ?");
        foreach ($rows as $m) {
            $nl = translateModuleToNl($m['nom'], $m['description']);
            if ($nl['nom'] !== '' || $nl['desc'] !== '') {
                $upd->execute([
                    $nl['nom'] !== '' ? $nl['nom'] : null,
                    $nl['desc'] !== '' ? $nl['desc'] : null,
                    $m['id'],
                ]);
                $done++;
            }
        }
        $_SESSION['module_flash'] = "✅ Traduction NL : " . $done . " module(s) mis à jour.";
        $redirectTo = 'parametres.php';
    } elseif ($action === 'module_move') {
        // Réordonne un module parmi ses frères (même parent)
        $id = (int) ($_POST['id'] ?? 0);
        $dir = (($_POST['dir'] ?? '') === 'up') ? 'up' : 'down';
        $m = $id > 0 ? getModuleById($db, $id) : null;
        if ($m) {
            $parent = $m['parent_id'];
            if ($parent === null) {
                $sib = $db->query("SELECT id FROM modules WHERE parent_id IS NULL ORDER BY sort_order ASC, nom ASC")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $st = $db->prepare("SELECT id FROM modules WHERE parent_id = ? ORDER BY sort_order ASC, nom ASC");
                $st->execute([(int) $parent]);
                $sib = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            $sib = array_map('intval', $sib);
            // Réindexe l'ordre 0..n selon l'affichage courant
            $upd = $db->prepare("UPDATE modules SET sort_order = ? WHERE id = ?");
            foreach ($sib as $i => $sid) { $upd->execute([$i, $sid]); }
            $pos = array_search($id, $sib, true);
            $swap = ($dir === 'up') ? $pos - 1 : $pos + 1;
            if ($pos !== false && $swap >= 0 && $swap < count($sib)) {
                $upd->execute([$swap, $sib[$pos]]);
                $upd->execute([$pos, $sib[$swap]]);
                $_SESSION['module_flash'] = "✅ Ordre mis à jour.";
            }
        }
        $redirectTo = safeReturn($_POST['return'] ?? '', 'parametres.php');
    } elseif ($action === 'module_reparent') {
        // Déplace un module dans un autre (ou à la racine)
        $id = (int) ($_POST['id'] ?? 0);
        $raw = $_POST['new_parent'] ?? '';
        $newParent = ($raw === '' || $raw === '0') ? null : (int) $raw;
        $m = $id > 0 ? getModuleById($db, $id) : null;
        if ($m && !empty($m['is_locked'])) {
            $_SESSION['module_flash'] = "❌ Module verrouillé : déverrouillez-le d'abord pour le déplacer.";
        } elseif ($m && $newParent !== $id) {
            // Empêche les cycles : le nouveau parent ne doit pas être un descendant de $id
            $ok = true;
            if ($newParent !== null) {
                $cursor = $newParent;
                $guard = 0;
                while ($cursor !== null && $guard++ < 100) {
                    if ((int) $cursor === $id) { $ok = false; break; }
                    $st = $db->prepare("SELECT parent_id FROM modules WHERE id = ?");
                    $st->execute([(int) $cursor]);
                    $p = $st->fetchColumn();
                    $cursor = ($p === false || $p === null) ? null : (int) $p;
                }
            }
            if ($ok) {
                // Placé EN DERNIER dans son nouveau parent (sort_order = max + 1).
                if ($newParent === null) {
                    $nextSort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id IS NULL")->fetchColumn();
                } else {
                    $ss = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id = ?");
                    $ss->execute([$newParent]);
                    $nextSort = (int) $ss->fetchColumn();
                }
                $db->prepare("UPDATE modules SET parent_id = ?, sort_order = ? WHERE id = ?")->execute([$newParent, $nextSort, $id]);
                if ($newParent !== null) {
                    $db->prepare("UPDATE modules SET is_container = 1 WHERE id = ?")->execute([$newParent]);
                }
                $_SESSION['module_flash'] = "✅ Module déplacé.";
            } else {
                $_SESSION['module_flash'] = "❌ Déplacement impossible : on ne peut pas mettre un module dans l'un de ses propres sous-modules.";
            }
        }
        $redirectTo = 'parametres.php';
    } elseif ($action === 'unlock_reorder') {
        // Déverrouille le mode réorganisation (flèches) avec le mot de passe unique
        if (adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['reorder_unlocked'] = 1;
            $_SESSION['module_flash'] = "🖐 Mode réorganisation activé — utilisez les flèches, puis « Terminer ».";
        } else {
            $_SESSION['module_flash'] = "❌ Mot de passe de verrouillage incorrect.";
        }
        $redirectTo = 'parametres.php#histprofil';
    } elseif ($action === 'lock_reorder') {
        unset($_SESSION['reorder_unlocked']);
        $_SESSION['module_flash'] = "✅ Réorganisation terminée.";
        $redirectTo = 'parametres.php#histprofil';
    }
}

header('Location: ' . $redirectTo);
exit();
