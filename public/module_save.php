<?php
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

// Réservé à l'admin
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
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
function handleModuleFileUpload($field, array $allowedMap, $maxSize, $subdir)
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
    $name = $subdir . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
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

        if ($nom === '') {
            $_SESSION['module_flash'] = "❌ Le nom du module est obligatoire.";
        } else {
            $iconImage = handleModuleIconUpload();
            $nl = translateModuleToNl($nom, $description);
            $stmt = $db->prepare(
                "INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, icon_image, nom_nl, description_nl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            ]);
            $_SESSION['module_flash'] = "✅ Module « " . $nom . " » créé.";
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
        if ($module && !empty($module['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage requis, contenu inchangé.";
            $redirectTo = 'module.php?id=' . $id;
        } elseif ($module) {
            $pdfPath     = $module['pdf_path'];
            $videoPath   = $module['video_path'];
            $videoStatus = $module['video_status'] ?? null;
            $videoSrc    = $module['video_src_path'] ?? null;

            if (!empty($_POST['remove_pdf']))   { $pdfPath = null; }
            if (!empty($_POST['remove_video'])) { $videoPath = null; $videoStatus = null; $videoSrc = null; }

            // PDF : limite alignée sur Claude (30 Mo) pour l'uniformisation par l'IA.
            $newPdf = handleModuleFileUpload('pdf_file', ['application/pdf' => 'pdf'], 30 * 1024 * 1024, 'pdf');
            if ($newPdf !== null) { $pdfPath = $newPdf; }

            // Vidéo : on range la source brute (jusqu'à 500 Mo), puis on lance la compression
            // 720p faststart EN TÂCHE DE FOND. Le teamcoach n'attend pas.
            $startTranscode = false;
            $newVideoRaw = handleModuleFileUpload('video_file', [
                'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'video/quicktime' => 'mov',
                'video/x-msvideo' => 'avi', 'video/x-matroska' => 'mkv', 'video/3gpp' => '3gp', 'video/x-m4v' => 'm4v',
            ], 500 * 1024 * 1024, 'video_raw');
            if ($newVideoRaw !== null) {
                $videoSrc = $newVideoRaw;
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
                $flashMsg = "✅ Contenu du module mis à jour.";

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
                    $res = aiUniformisePdf($db, moduleFileAbsPath($pdfPath));
                    if ($res['ok']) {
                        $contenuIa = $res['text'];
                        $imgs = aiExtractPdfImages(moduleFileAbsPath($pdfPath), $pdfPath);
                        $contenuImages = !empty($imgs) ? json_encode($imgs) : null;
                        $flashMsg = "✅ Contenu uniformisé par l'IA (≈ " . number_format($res['cost_eur'], 3) . " €). Vérifie le rendu ci-dessous.";
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
                    if ($qz['ok']) { $quizJson = json_encode($qz['quiz']); }
                }

                $hasPdf = ($pdfPath !== null && $pdfPath !== '');

                if ($hasPdf && $hasVideo) {
                    // --- PDF + vidéo : découpe en 2 sous-modules (écrit + vidéo) ---
                    try {
                        if (!$db->query("SHOW COLUMNS FROM modules LIKE 'content_kind'")->fetch()) {
                            $db->exec("ALTER TABLE modules ADD COLUMN content_kind VARCHAR(16) NULL");
                        }
                    } catch (Exception $e) { /* migration non bloquante */ }

                    $roles = (string) ($module['roles'] ?? '');

                    // Sous-module CONTENU ÉCRIT (PDF)
                    $ecrit = null;
                    try { $ecrit = $db->query("SELECT id FROM modules WHERE parent_id = " . (int) $id . " AND content_kind = 'ecrit' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
                    if ($ecrit) {
                        $db->prepare("UPDATE modules SET pdf_path = ?, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = ?, a_evaluer = ?, contenu_ia = ?, contenu_by = ?, contenu_images = ?, quiz_json = ? WHERE id = ?")
                           ->execute([$pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson, (int) $ecrit['id']]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, pdf_path, uniformized, a_evaluer, contenu_ia, contenu_by, contenu_images, quiz_json, content_kind) VALUES (?, ?, 0, ?, '📄', ?, 1, ?, ?, ?, ?, ?, ?, ?, 'ecrit')")
                           ->execute(['Contenu écrit', 'Geschreven inhoud', (int) $id, $roles, $pdfPath, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson]);
                    }

                    // Sous-module VIDÉO (pipeline de compression 720p en tâche de fond)
                    $vidChildId = 0;
                    $vid = null;
                    try { $vid = $db->query("SELECT id FROM modules WHERE parent_id = " . (int) $id . " AND content_kind = 'video' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
                    if ($vid) {
                        $vidChildId = (int) $vid['id'];
                        $db->prepare("UPDATE modules SET video_path = ?, video_status = ?, video_src_path = ?, pdf_path = NULL WHERE id = ?")
                           ->execute([$videoPath, $videoStatus, $videoSrc, $vidChildId]);
                    } else {
                        $db->prepare("INSERT INTO modules (nom, nom_nl, is_container, parent_id, icon, roles, is_active, video_path, video_status, video_src_path, content_kind) VALUES (?, ?, 0, ?, '🎬', ?, 1, ?, ?, ?, 'video')")
                           ->execute(['Vidéo', 'Video', (int) $id, $roles, $videoPath, $videoStatus, $videoSrc]);
                        $vidChildId = (int) $db->lastInsertId();
                    }

                    // Le module parent devient un conteneur (plus de contenu propre).
                    $db->prepare("UPDATE modules SET is_container = 1, pdf_path = NULL, video_path = NULL, video_status = NULL, video_src_path = NULL, uniformized = 0, contenu_ia = NULL, contenu_images = NULL, quiz_json = NULL WHERE id = ?")->execute([$id]);

                    $splitMsg = "✅ 2 sous-modules créés : « Contenu écrit » + « Vidéo ».";
                    if ($uniformized) { $splitMsg .= " Écrit uniformisé par l'IA."; }
                    if ($startTranscode && $vidChildId) {
                        spawnVideoTranscode($videoSrc, $vidChildId);
                        $splitMsg .= " Vidéo en cours de préparation (compression automatique).";
                    }
                    $_SESSION['module_flash'] = $splitMsg;
                    $redirectTo = 'module.php?id=' . $id;
                } else {
                    // Un seul fichier : contenu IA + pipeline vidéo (compression 720p en tâche de fond).
                    $db->prepare("UPDATE modules SET pdf_path = ?, video_path = ?, video_status = ?, video_src_path = ?, uniformized = ?, a_evaluer = ?, contenu_ia = ?, contenu_by = ?, contenu_images = ?, quiz_json = ? WHERE id = ?")
                       ->execute([$pdfPath, $videoPath, $videoStatus, $videoSrc, $uniformized, $aEvaluer, $contenuIa, $contenuBy, $contenuImages, $quizJson, $id]);

                    if ($startTranscode) {
                        spawnVideoTranscode($videoSrc, $id);
                        $flashMsg .= " La vidéo est en cours de préparation (compression automatique) — elle apparaîtra dans une minute ou deux.";
                    }
                    $_SESSION['module_flash'] = $flashMsg;
                    $redirectTo = 'module.php?id=' . $id;
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
                if (!empty($module['is_locked'])) {
                    $_SESSION['module_flash'] = "❌ Module verrouillé : déverrouillez-le d'abord pour le supprimer.";
                } else {
                    // Supprime aussi les éventuels sous-modules
                    $db->prepare("DELETE FROM modules WHERE id = ? OR parent_id = ?")->execute([$id, $id]);
                    $_SESSION['module_flash'] = "✅ Module supprimé.";
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
                $db->prepare("UPDATE modules SET parent_id = ? WHERE id = ?")->execute([$newParent, $id]);
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
