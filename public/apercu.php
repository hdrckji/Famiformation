<?php
// Active / quitte l'aperçu d'un profil (voir le site "comme" un profil donné).
// Réservé à l'admin. Ne touche pas au rôle réel : seul l'affichage est impacté.
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

// Cible de retour sûre (uniquement des pages internes connues, pas d'open redirect)
function apercuSafeReturn($value, $default = 'index.php')
{
    $value = (string) $value;
    foreach (['parametres.php', 'index.php', 'module.php', 'formation.php'] as $allowed) {
        if (strpos($value, $allowed) === 0) {
            return $value;
        }
    }
    return $default;
}

if (isset($_GET['exit'])) {
    unset($_SESSION['apercu_role']);
    // Revient là où l'aperçu a été lancé (ex : Paramètres > Accès aux modules par profil).
    $back = isset($_SESSION['apercu_return']) ? (string) $_SESSION['apercu_return'] : 'index.php';
    unset($_SESSION['apercu_return']);
    header('Location: ' . apercuSafeReturn($back));
    exit();
}

$role = trim((string) ($_GET['role'] ?? ''));
$valides = array_keys(moduleProfiles($db));
// Tous les profils sont prévisualisables. Les rôles à page d'arrivée spéciale
// (évaluateur → evaluation.php, agence intérim → interim_horaires.php) affichent
// aussi la bannière d'aperçu pour pouvoir en sortir.
if ($role !== '' && in_array($role, $valides, true)) {
    $_SESSION['apercu_role'] = $role;
    // Mémorise la page de lancement pour y revenir en quittant l'aperçu.
    $back = $_GET['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $_SESSION['apercu_return'] = apercuSafeReturn($back, 'parametres.php#histprofil');
}

header('Location: index.php');
exit();
