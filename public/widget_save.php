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
    }
}

header('Location: ' . $redirectTo);
exit();
