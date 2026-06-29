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
    if (!is_array($input)) {
        return '';
    }
    $valid = array_keys(moduleProfiles());
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
            $stmt = $db->prepare(
                "INSERT INTO modules (nom, description, is_container, parent_id, icon, roles) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                mb_substr($nom, 0, 150),
                mb_substr($description, 0, 500),
                $isContainer,
                $parentId,
                mb_substr($icon, 0, 16),
                $roles,
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
            $stmt = $db->prepare(
                "UPDATE modules SET nom = ?, description = ?, is_container = ?, icon = ?, roles = ? WHERE id = ?"
            );
            $stmt->execute([
                mb_substr($nom, 0, 150),
                mb_substr($description, 0, 500),
                $isContainer,
                mb_substr($icon, 0, 16),
                $roles,
                $id,
            ]);
            $_SESSION['module_flash'] = "✅ Module « " . $nom . " » modifié.";
        } else {
            $_SESSION['module_flash'] = "❌ Modification impossible (nom obligatoire).";
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE modules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            $_SESSION['module_flash'] = "✅ Statut du module mis à jour.";
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $module = getModuleById($db, $id);
            // Supprime aussi les éventuels sous-modules
            $db->prepare("DELETE FROM modules WHERE id = ? OR parent_id = ?")->execute([$id, $id]);
            $_SESSION['module_flash'] = "✅ Module supprimé.";
            if ($module && !empty($module['parent_id'])) {
                $redirectTo = 'module.php?id=' . (int) $module['parent_id'];
            }
        }
    }
}

header('Location: ' . $redirectTo);
exit();
