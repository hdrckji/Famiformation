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
    $dir = __DIR__ . '/uploads/modules/' . $subdir;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $name = $subdir . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return 'uploads/modules/' . $subdir . '/' . $name;
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
            $stmt = $db->prepare(
                "INSERT INTO modules (nom, description, is_container, parent_id, icon, roles, icon_image) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                mb_substr($nom, 0, 150),
                mb_substr($description, 0, 500),
                $isContainer,
                $parentId,
                mb_substr($icon, 0, 16),
                $roles,
                $iconImage,
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
            if ($existing && !empty($existing['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage requis, modification annulée.";
            } else {
                $iconImage = $existing['icon_image'] ?? null;
                if (!empty($_POST['remove_icon_image'])) { $iconImage = null; }
                $uploaded = handleModuleIconUpload();
                if ($uploaded !== null) { $iconImage = $uploaded; }

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
                $_SESSION['module_flash'] = "✅ Module « " . $nom . " » modifié.";
            }
        } else {
            $_SESSION['module_flash'] = "❌ Modification impossible (nom obligatoire).";
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE modules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            $_SESSION['module_flash'] = "✅ Statut du module mis à jour.";
        }
    } elseif ($action === 'content') {
        $id = (int) ($_POST['id'] ?? 0);
        $module = $id > 0 ? getModuleById($db, $id) : null;
        if ($module && !empty($module['is_locked']) && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
            $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage requis, contenu inchangé.";
            $redirectTo = 'module.php?id=' . $id;
        } elseif ($module) {
            $pdfPath   = $module['pdf_path'];
            $videoPath = $module['video_path'];

            if (!empty($_POST['remove_pdf']))   { $pdfPath = null; }
            if (!empty($_POST['remove_video'])) { $videoPath = null; }

            $newPdf = handleModuleFileUpload('pdf_file', ['application/pdf' => 'pdf'], 50 * 1024 * 1024, 'pdf');
            if ($newPdf !== null) { $pdfPath = $newPdf; }

            $newVideo = handleModuleFileUpload('video_file', [
                'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'video/quicktime' => 'mov',
            ], 300 * 1024 * 1024, 'video');
            if ($newVideo !== null) { $videoPath = $newVideo; }

            $uniformized = (($_POST['uniformize'] ?? '0') === '1') ? 1 : 0;
            $aEvaluer = !empty($_POST['a_evaluer']) ? 1 : 0;

            $db->prepare("UPDATE modules SET pdf_path = ?, video_path = ?, uniformized = ?, a_evaluer = ? WHERE id = ?")
               ->execute([$pdfPath, $videoPath, $uniformized, $aEvaluer, $id]);
            $_SESSION['module_flash'] = "✅ Contenu du module mis à jour.";
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
                $locked = !empty($module['is_locked']);
                if ($locked && !adminPasswordOk($db, (string) ($_POST['admin_password'] ?? ''))) {
                    $_SESSION['module_flash'] = "❌ Module verrouillé : mot de passe de verrouillage incorrect, suppression annulée.";
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
    }
}

header('Location: ' . $redirectTo);
exit();
