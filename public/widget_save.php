<?php
// Réglages du widget d'accueil (activer/désactiver, accès). Réservé à l'admin.
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/widget.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}
ensureWidgetTables($db);

function widgetSafeReturn($value, $default = 'parametres.php')
{
    $value = (string) $value;
    return (strpos($value, 'parametres.php') === 0) ? $value : $default;
}

$redirectTo = widgetSafeReturn($_POST['return'] ?? '', 'parametres.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_enabled') {
        widgetSet($db, 'enabled', widgetEnabled($db) ? '0' : '1');
        $_SESSION['module_flash'] = widgetEnabled($db) ? "✅ Widget activé." : "✅ Widget désactivé.";
    } elseif ($action === 'set_access') {
        $valid = array_keys(moduleProfiles($db));
        $submitted = is_array($_POST['roles'] ?? null) ? $_POST['roles'] : [];
        $roles = array_values(array_intersect($valid, $submitted));
        widgetSet($db, 'roles', implode(',', $roles));
        $_SESSION['module_flash'] = "✅ Accès au widget mis à jour.";
    } elseif ($action === 'add_phrase') {
        $texte = trim((string) ($_POST['texte'] ?? ''));
        $cat = (($_POST['categorie'] ?? 'info') === 'blague') ? 'blague' : 'info';
        if ($texte !== '') {
            $db->prepare("INSERT INTO widget_phrases (texte, categorie) VALUES (?, ?)")->execute([mb_substr($texte, 0, 500), $cat]);
            $_SESSION['module_flash'] = "✅ Phrase ajoutée.";
        } else {
            $_SESSION['module_flash'] = "❌ La phrase ne peut pas être vide.";
        }
    } elseif ($action === 'edit_phrase') {
        $id = (int) ($_POST['id'] ?? 0);
        $texte = trim((string) ($_POST['texte'] ?? ''));
        $cat = (($_POST['categorie'] ?? 'info') === 'blague') ? 'blague' : 'info';
        if ($id > 0 && $texte !== '') {
            $db->prepare("UPDATE widget_phrases SET texte = ?, categorie = ? WHERE id = ?")->execute([mb_substr($texte, 0, 500), $cat, $id]);
            $_SESSION['module_flash'] = "✅ Phrase modifiée.";
        }
    } elseif ($action === 'delete_phrase') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM widget_phrases WHERE id = ?")->execute([$id]);
            $_SESSION['module_flash'] = "✅ Phrase supprimée.";
        }
    } elseif ($action === 'toggle_phrase') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE widget_phrases SET actif = 1 - actif WHERE id = ?")->execute([$id]);
            $_SESSION['module_flash'] = "✅ Affichage de la phrase mis à jour.";
        }
    }
}

header('Location: ' . $redirectTo);
exit();
