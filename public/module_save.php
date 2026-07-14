<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/contrib_settings.php';
require_once 'includes/storage_stats.php';
require_once 'includes/i18n_nl.php'; // synchronisation automatique FR -> NL

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

/**
 * Extension du fichier réellement envoyé sur ce champ ('' si aucun).
 * Sert à EXPLIQUER un refus : sans ça, un .pptx déposé disparaissait en silence.
 */
function famiUploadedExt($field)
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    return strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
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
            // Nouveau module placé EN DERNIER parmi ses frères (sort_order = max + 1),
            // sinon il hérite de 0 et remonte tout en haut de la liste.
            if ($parentId === null) {
                $nextSort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id IS NULL")->fetchColumn();
            } else {
                $ss = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE parent_id = ?");
                $ss->execute([$parentId]);
                $nextSort = (int) $ss->fetchColumn();
            }
            // Bilingue : on enregistre le FR. Le NL (titre + description) est généré
            // AUTOMATIQUEMENT par Claude en tâche de fond juste après (spawnNlSync).
            $stmt = $db->prepare(
                "INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, icon_image, nom_nl, description_nl, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)"
            );
            $stmt->execute([
                mb_substr($nom, 0, 150),
                mb_substr($description, 0, 500),
                $isContainer,
                $parentId,
                mb_substr($icon, 0, 16),
                $roles,
                $iconImage,
                $nextSort,
            ]);
            $newId = (int) $db->lastInsertId();
            $_SESSION['module_flash'] = "✅ Module « " . $nom . " » créé. 🌐 Version néerlandaise en cours.";

            // Contributeur : le module reste EN ATTENTE (caché) jusqu'à validation admin.
            if (!$isAdminActor) {
                require_once __DIR__ . '/includes/events.php';
                eventsEnsureTables($db);
                $uid = ((int) ($_SESSION['user_id'] ?? 0)) ?: null;
                try { $db->prepare("UPDATE modules SET is_active = 0, content_status = 'pending' WHERE id = ?")->execute([$newId]); } catch (Exception $e) {}
                try { $db->prepare("UPDATE modules SET contenu_by = ? WHERE id = ?")->execute([$uid, $newId]); } catch (Exception $e) {}
                logEvent($db, 'content_submitted', (int) ($_SESSION['user_id'] ?? 0), $newId, 'Nouveau module proposé : ' . $nom);
                $_SESSION['module_flash'] = "✅ Module « " . $nom . " » créé — en attente de validation par un admin.";
            }
            if (function_exists('nlSyncModule')) { @set_time_limit(0); nlSyncModule($db, $newId, true); } // titre/description → NL en direct
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

                // Bilingue : on met à jour le FR. Le NL (titre + description) est régénéré
                // AUTOMATIQUEMENT par Claude en tâche de fond (spawnNlSync) si le FR a changé.
                $stmt = $db->prepare(
                    "UPDATE modules SET nom = ?, description = ?, is_container = ?, icon = ?, roles = ?, icon_image = ? WHERE id = ?"
                );
                $stmt->execute([
                    mb_substr($nom, 0, 150),
                    mb_substr($description, 0, 500),
                    $isContainer,
                    mb_substr($icon, 0, 16),
                    $roles,
                    $iconImage,
                    $id,
                ]);
                if (function_exists('nlSyncModule')) { @set_time_limit(0); nlSyncModule($db, (int) $id, true); } // titre/description → NL en direct
                $_SESSION['module_flash'] = "✅ Module « " . $nom . " » modifié. 🌐 Version néerlandaise mise à jour.";
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
            // REFUS EXPLIQUÉS : un fichier au mauvais format était simplement ignoré, sans un mot.
            // L'utilisateur croyait avoir déposé son cours et se retrouvait avec un module vide.
            $rejectMsg = '';
            $extPdf = famiUploadedExt('pdf_file');
            if ($extPdf !== '' && $extPdf !== 'pdf') {
                $rejectMsg .= in_array($extPdf, ['ppt', 'pptx', 'doc', 'docx', 'odt', 'odp'], true)
                    ? "❌ Le document doit être un PDF. Dans PowerPoint / Word : Fichier → Enregistrer sous (ou Exporter) → choisis « PDF », puis redépose le fichier. "
                    : "❌ Format de document refusé (." . htmlspecialchars($extPdf) . ") : seul le PDF est accepté. ";
            }
            $extVid = famiUploadedExt('video_file');
            if ($extVid !== '' && !in_array($extVid, ['mp4', 'mov'], true)) {
                $rejectMsg .= "❌ Format vidéo refusé (." . htmlspecialchars($extVid) . ") : seuls le MP4 et le MOV sont acceptés. ";
            }

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
            // FORMATS ACCEPTÉS : mp4 et mov, point. Ce sont les deux seuls que produisent
            // réellement les téléphones et les caméras, et les seuls dont on garantit la
            // lecture après compression. Tout le reste est refusé AVEC une explication.
            $newVideoRaw = handleModuleFileUpload('video_file', [
                'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
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

            // Sous-titres FACULTATIFS : un .srt déjà en possession de l'utilisateur.
            // S'il n'y en a pas, le worker transcrit la vidéo tout seul (Whisper) →
            // personne n'a besoin de fabriquer un fichier de sous-titres.
            $newSrt = handleModuleFileUpload('srt_file', [
                'text/plain' => 'srt', 'application/x-subrip' => 'srt', 'text/vtt' => 'vtt',
            ], 2 * 1024 * 1024, 'subs_src', $fSlug . '-srt');
            if ($newSrt !== null) {
                $srtSrc = $newSrt;
            } elseif (!empty($_POST['remove_srt'])) {
                $srtSrc = null;
            } else {
                $srtSrc = false; // false = ne pas toucher à l'existant
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
                $guideLang = 'fr'; // langue du document (détectée par l'IA)
                if ($uniformized === 1 && $pdfPath !== null && $pdfPath !== '') {
                    require_once __DIR__ . '/includes/ia_settings.php';
                    require_once __DIR__ . '/includes/ai_uniformise.php';
                    $res = aiUniformisePdf($db, moduleFileAbsPath($pdfPath), $pdfPath);
                    if ($res['ok']) {
                        $contenuIa = $res['text'];
                        $contenuImages = !empty($res['images']) ? json_encode($res['images']) : null;
                        // LANGUE DU DOCUMENT : le guide est rédigé (et se relira) dans CETTE langue.
                        // La traduction vers l'autre langue se fera à la validation finale.
                        $guideLang = (($res['lang'] ?? 'fr') === 'nl') ? 'nl' : 'fr';
                        $flashMsg = "✅ Contenu extrait et mis en forme par l'IA en " . ($guideLang === 'nl' ? 'néerlandais' : 'français')
                            . " (≈ " . number_format($res['cost_eur'], 3) . " €). Vérifie le rendu ci-dessous.";
                        require_once __DIR__ . '/includes/ia_usage.php';
                        iaLogUsage($db, (int) ($_SESSION['user_id'] ?? 0), 'uniformise', $res['model'], $res['in'], $res['out'], $res['cost_eur'], $id);
                    } else {
                        $uniformized = 0;
                        $flashMsg = "⚠️ Uniformisation IA échouée : " . $res['error'] . " — le PDF est enregistré tel quel.";
                        if (function_exists('logSiteError')) {
                            require_once __DIR__ . '/includes/events.php';
                            logSiteError($db, (int) $id, (int) ($_SESSION['user_id'] ?? 0), 'guide', (string) $res['error']);
                        }
                    }
                }

                // Quiz : généré PLUS BAS, une seule fois, APRÈS la création des sous-modules et
                // le traitement des sous-titres — pour qu'il porte sur le guide + la vidéo si un
                // transcript est disponible. Il est TOUJOURS généré si « à évaluer » (jamais
                // dépendant d'une tâche de fond, sinon il ne serait jamais créé).
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
                        $db->prepare("UPDATE modules SET pdf_path = ?, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = ?, a_evaluer = ?, contenu_ia = ?, contenu_by = ?, contenu_images = ?, quiz_json = ?, source_lang = ? WHERE id = ?")
                           ->execute([$pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson, $guideLang, (int) $guide['id']]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, pdf_path, uniformized, a_evaluer, contenu_ia, contenu_by, contenu_images, quiz_json, source_lang, content_kind) VALUES (?, ?, 0, ?, '📄', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'ecrit')")
                           ->execute(['Guide', 'Gids', (int) $id, $childRoles, $pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson, $guideLang]);
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
                        // a_evaluer aussi sur la vidéo : permet de générer le quiz depuis la
                        // transcription même s'il n'y a PAS de guide (module vidéo seule).
                        // REMPLACEMENT de la vidéo (ou nouveau .srt) : les anciens sous-titres ne
                        // correspondent plus → on les efface du stockage, sinon ils restent
                        // orphelins sur le volume (et on les paie).
                        if ($newVideoRaw !== null || $srtSrc !== false) {
                            try {
                                $os = $db->prepare("SELECT sub_fr_path, sub_nl_path, sub_src_path FROM modules WHERE id = ? LIMIT 1");
                                $os->execute([$vidChildId]);
                                if ($orow = $os->fetch(PDO::FETCH_ASSOC)) {
                                    foreach (['sub_fr_path', 'sub_nl_path', 'sub_src_path'] as $sc) {
                                        if (!empty($orow[$sc])) { volumeUnlink((string) $orow[$sc]); }
                                    }
                                }
                                $db->prepare("UPDATE modules SET sub_fr_path = NULL, sub_nl_path = NULL, transcript = NULL, sub_status = NULL WHERE id = ?")
                                   ->execute([$vidChildId]);
                            } catch (Exception $e) { /* colonnes absentes : sans gravité */ }
                        }
                        $db->prepare("UPDATE modules SET video_path = ?, video_status = ?, video_src_path = ?, pdf_path = NULL, a_evaluer = ?, contenu_by = COALESCE(contenu_by, ?) WHERE id = ?")
                           ->execute([$videoPath, $videoStatus, $videoSrc, $aEvaluer, $contenuBy, $vidChildId]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, video_path, video_status, video_src_path, a_evaluer, contenu_by, content_kind) VALUES (?, ?, 0, ?, '🎬', ?, 1, ?, ?, ?, ?, ?, 'video')")
                           ->execute(['Vidéo', 'Video', (int) $id, $childRoles, $videoPath, $videoStatus, $videoSrc, $aEvaluer, $contenuBy]);
                        $vidChildId = (int) $db->lastInsertId();
                    }
                    // Sous-titres fournis : on les attache au sous-module vidéo AVANT que le
                    // worker ne tourne — il les préférera à la transcription automatique.
                    if ($vidChildId && $srtSrc !== false) {
                        try {
                            $db->prepare("UPDATE modules SET sub_src_path = ? WHERE id = ?")
                               ->execute([$srtSrc !== null ? $srtSrc : null, $vidChildId]);
                        } catch (Exception $e) {
                            // colonne pas encore créée : sans gravité, la transcription auto prendra le relais
                        }
                        // .srt FOURNI : on génère les pistes VTT (FR + NL) TOUT DE SUITE, sans
                        // dépendre du worker de fond → les sous-titres s'affichent immédiatement.
                        if ($srtSrc) {
                            try {
                                require_once __DIR__ . '/includes/transcription.php';
                                $srtAbs = function_exists('moduleFileAbsPath') ? moduleFileAbsPath($srtSrc) : '';
                                if ($srtAbs && is_file($srtAbs) && function_exists('famiVideoSubtitles') && function_exists('famiPersistSubtitles')) {
                                    // withNl = false : à l'import on ne fait QUE la piste FR (gratuite,
                                    // instantanée). La piste NL (appel IA) est générée à la validation
                                    // finale — inutile de faire attendre l'utilisateur maintenant.
                                    $subs = famiVideoSubtitles($db, '', $srtAbs, false);
                                    if (!empty($subs['ok'])) {
                                        famiPersistSubtitles($db, $vidChildId, $subs);
                                    }
                                }
                            } catch (Exception $e) { /* le worker prendra le relais */ }
                        }
                    }

                    // AUCUN .srt fourni → TRANSCRIPTION AUTOMATIQUE (ffmpeg + Whisper) TOUT DE SUITE.
                    // Avant, on comptait sur le worker de fond : s'il ne partait pas (cas fréquent),
                    // la vidéo restait à vie sans sous-titres et sans transcription pour le quiz.
                    // Whisper est rapide (quelques secondes pour une vidéo de 5 min), on l'assume ici.
                    if ($vidChildId && empty($srtSrc)) {
                        try {
                            require_once __DIR__ . '/includes/transcription.php';
                            $vKey = $videoSrc ? $videoSrc : $videoPath;
                            $vAbs = ($vKey && function_exists('moduleFileAbsPath')) ? moduleFileAbsPath($vKey) : '';
                            if ($vAbs && is_file($vAbs) && function_exists('famiSttReady') && famiSttReady()) {
                                @set_time_limit(0);
                                $subs = famiVideoSubtitles($db, $vAbs, '', false); // FR seul ; le NL vient à la validation finale
                                if (!empty($subs['ok']) && function_exists('famiPersistSubtitles')) {
                                    famiPersistSubtitles($db, $vidChildId, $subs);
                                    $flashMsg .= " 💬 Sous-titres générés automatiquement (Whisper).";
                                } else {
                                    $flashMsg .= " ⚠️ Sous-titres non générés : " . ($subs['error'] ?? 'erreur') . ".";
                                    if (function_exists('logSiteError')) {
                                        logSiteError($db, (int) $vidChildId, (int) ($_SESSION['user_id'] ?? 0), 'subtitles', (string) ($subs['error'] ?? ''));
                                    }
                                }
                            } elseif (function_exists('famiSttReady') && !famiSttReady()) {
                                $flashMsg .= " ⚠️ Sous-titres non générés : aucune clé de transcription (Groq/OpenAI) configurée.";
                            }
                        } catch (Exception $e) {
                            $flashMsg .= " ⚠️ Sous-titres non générés : " . $e->getMessage();
                        }
                    }
                    $madeVideo = true;
                }

                // Le module parent devient un conteneur (il ne porte plus de contenu propre).
                $db->prepare("UPDATE modules SET is_container = 1, pdf_path = NULL, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = 0, a_evaluer = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE id = ?")->execute([$id]);

                $structMsg = ($madeGuide && $madeVideo)
                    ? "✅ 2 sous-modules : « Guide » + « Vidéo »."
                    : ("✅ Sous-module « " . ($madeGuide ? 'Guide' : 'Vidéo') . " » ajouté.");
                if ($uniformized && $madeGuide) { $structMsg .= " Le guide a été mis en forme par l'IA."; }
                if ($startTranscode && $vidChildId) {
                    spawnVideoTranscode($videoSrc, $vidChildId);
                    $structMsg .= " La vidéo est en préparation (compression automatique).";
                }
                require_once __DIR__ . '/includes/events.php';
                eventsEnsureTables($db);
                $ajout = ($madeGuide && $madeVideo) ? 'guide + vidéo' : ($madeGuide ? 'guide' : 'vidéo');
                $modNom = (string) ($module['nom'] ?? 'Module');
                // Le contenu vient d'être déposé : il n'est PAS encore validé (relecture à faire).
                // On le CACHE donc pour tout le monde, même pour un admin — personne ne doit voir
                // une formation non relue. Il sera PUBLIÉ à la validation finale de la relecture
                // (famiFinalValidation), c.-à-d. après le quiz s'il y en a un.
                // DOUTES DE L'IA sur le guide : on prévient dans les notifications (admins + auteur).
                if ($guideChildId && function_exists('famiCountDoubts') && function_exists('logAiDoubts')) {
                    $nbD = famiCountDoubts($contenuIa ?? '');
                    if ($nbD > 0) {
                        logAiDoubts($db, (int) $guideChildId, (int) ($_SESSION['user_id'] ?? 0), $nbD, 'guide');
                        $flashMsg .= " ⚠️ " . $nbD . " point" . ($nbD > 1 ? 's' : '') . " douteux signalé" . ($nbD > 1 ? 's' : '') . " par l'IA.";
                    }
                }

                // Statut : 'pending' = « à contrôler par un admin » (file de modération). Un ADMIN
                // n'a personne au-dessus de lui : son contenu est en 'draft' — caché lui aussi,
                // mais il n'encombre PAS la file « à contrôler » ni les notifications.
                $subStatus = $isAdminActor ? 'draft' : 'pending';
                $subIds = array_values(array_filter([(int) $guideChildId, (int) $vidChildId]));
                if ($subIds) {
                    $ph = implode(',', array_fill(0, count($subIds), '?'));
                    try { $db->prepare("UPDATE modules SET is_active = 0, content_status = ? WHERE id IN ($ph)")->execute(array_merge([$subStatus], $subIds)); } catch (Exception $e) {}
                }
                if (!$isAdminActor) {
                    logEvent($db, 'content_submitted', (int) ($_SESSION['user_id'] ?? 0), $id, 'Contenu proposé (' . $ajout . ') : ' . $modNom);
                    $structMsg .= " En attente de validation par un admin.";
                } else {
                    $structMsg .= " ⚠️ Non visible tant que tu n'as pas terminé la relecture.";
                }
                storageRecordSample($db); // fichiers ajoutés → point d'historique (facturation au pro rata)

                // QUIZ : PAS généré ici. Il n'est utile qu'APRÈS ta relecture du guide — le
                // produire maintenant allongeait l'import de plusieurs minutes pour rien.
                // Il est généré à la VALIDATION DU GUIDE (module_review), sur guide + vidéo.
                if ($aEvaluer) {
                    $flashMsg .= " 📝 Le quiz sera généré quand tu valideras la relecture du guide.";
                }

                // BILINGUE : la traduction NL (guide + quiz) ne se fait PAS ici non plus, mais à
                // la VALIDATION FINALE de la relecture. On ne traduit ici que le titre/description
                // du module : 1 appel très court.
                @set_time_limit(0);
                nlSyncModule($db, (int) $id, true); // module parent : titre/description seulement

                $_SESSION['module_flash'] = trim(($rejectMsg ?? '') . ' ' . $flashMsg . ' ' . $structMsg);
                $redirectTo = 'module.php?id=' . $id;
                // Étape de relecture (visuelle par défaut) : l'uploadeur relit/corrige avant publication.
                if ($madeGuide && $uniformized === 1 && $contenuIa && $guideChildId) {
                    $redirectTo = 'module_edit.php?id=' . $guideChildId;
                }
            }
        }
    } elseif ($action === 'create_blank_guide') {
        // Rédiger une formation DE ZÉRO (au lieu d'importer un PDF) : on crée un guide
        // avec une structure de départ minimale, puis on ouvre l'éditeur visuel.
        $id = (int) ($_POST['id'] ?? 0);
        $module = $id > 0 ? getModuleById($db, $id) : null;
        if (!$isAdminActor && (!$module || !contribCanAddContent($db, $module, $actorRole))) {
            $_SESSION['module_flash'] = "❌ Vous n'avez pas le droit de créer un guide ici.";
            header('Location: index.php');
            exit();
        }
        if ($module && !empty($module['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage requis.";
            $redirectTo = 'module.php?id=' . $id;
        } elseif ($module) {
            $aEvaluer = !empty($_POST['a_evaluer']) ? 1 : 0;
            $contenuBy = ((int) ($_SESSION['user_id'] ?? 0)) ?: null;
            $childRoles = (string) ($module['roles'] ?? '');
            $starter = json_encode(['blocks' => [
                ['type' => 'hero', 'title' => 'Titre de la formation', 'subtitle' => ''],
                ['type' => 'section', 'title' => 'Introduction'],
                ['type' => 'text', 'text' => 'Rédigez votre contenu ici. Ajoutez des sections, des listes, des encadrés…'],
            ]], JSON_UNESCAPED_UNICODE);

            $guideChildId = 0;
            $guide = null;
            try { $guide = $db->query("SELECT id, contenu_ia FROM modules WHERE parent_id = " . (int) $id . " AND content_kind = 'ecrit' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
            if ($guide) {
                $guideChildId = (int) $guide['id'];
                if (trim((string) ($guide['contenu_ia'] ?? '')) === '') {
                    $db->prepare("UPDATE modules SET uniformized = 1, a_evaluer = ?, contenu_ia = ?, contenu_by = COALESCE(contenu_by, ?) WHERE id = ?")
                       ->execute([$aEvaluer, $starter, $contenuBy, $guideChildId]);
                } else {
                    // Contenu déjà présent : on n'écrase pas, on met juste à jour le choix d'évaluation.
                    $db->prepare("UPDATE modules SET a_evaluer = ? WHERE id = ?")->execute([$aEvaluer, $guideChildId]);
                }
            } else {
                $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, uniformized, a_evaluer, contenu_ia, contenu_by, content_kind) VALUES (?, ?, 0, ?, '📄', ?, 1, 1, ?, ?, ?, 'ecrit')")
                   ->execute(['Guide', 'Gids', (int) $id, $childRoles, $aEvaluer, $starter, $contenuBy]);
                $guideChildId = (int) $db->lastInsertId();
            }
            // Le module courant devient le conteneur qui regroupe le guide.
            $db->prepare("UPDATE modules SET is_container = 1, pdf_path = NULL, uniformized = 0, a_evaluer = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE id = ?")->execute([$id]);
            // Pas encore relu → caché pour tout le monde jusqu'à la validation finale.
            // 'draft' pour un admin (caché mais hors file de modération), 'pending' sinon.
            try {
                $db->prepare("UPDATE modules SET is_active = 0, content_status = ? WHERE id = ?")
                   ->execute([($isAdminActor ? 'draft' : 'pending'), $guideChildId]);
            } catch (Exception $e) {}
            $_SESSION['module_flash'] = "✍️ Guide créé (non visible tant qu'il n'est pas validé) — rédige ta formation puis clique sur « Valider ».";
            header('Location: module_edit.php?id=' . $guideChildId);
            exit();
        }
        $redirectTo = 'module.php?id=' . $id;
    } elseif ($action === 'nl_sync') {
        // Secours MANUEL de la traduction néerlandaise.
        // La synchro se fait normalement toute seule en tâche de fond après chaque
        // enregistrement. Ce bouton sert si le fond n'a pas pu s'exécuter, ou pour
        // forcer une retraduction après avoir corrigé le texte français.
        // Ici on traduit de façon SYNCHRONE (l'admin a cliqué exprès et accepte d'attendre).
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            @set_time_limit(600);
            $res = nlSyncModule($db, $id, true);
            if ($res['ok']) {
                $quoi = !empty($res['done']) ? implode(', ', array_unique($res['done'])) : 'rien à traduire';
                $_SESSION['module_flash'] = "🌐 Version néerlandaise mise à jour (" . $quoi . ").";
            } else {
                $_SESSION['module_flash'] = "⚠️ Traduction NL incomplète : " . $res['error'] . " — le français reste affiché.";
            }
            $redirectTo = 'module.php?id=' . $id;
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

                    // Nettoyage du STOCKAGE : supprimer un module supprime TOUT ce qui lui est lié
                    // (PDF, vidéo + source, SOUS-TITRES .vtt FR/NL et .srt, images du PDF, images
                    // ajoutées dans l'éditeur, icône). Rien ne doit rester à traîner : on le paie.
                    famiPurgeModulesStorage($db, $toDelete);

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
