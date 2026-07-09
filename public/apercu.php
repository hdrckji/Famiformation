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

if (isset($_GET['exit'])) {
    unset($_SESSION['apercu_role']);
    header('Location: index.php');
    exit();
}

$role = trim((string) ($_GET['role'] ?? ''));
$valides = array_keys(moduleProfiles($db));
// On ne prévisualise pas les rôles à redirection spéciale (agence intérim, évaluateur)
$exclus = ['agence_interim', 'evaluateur', 'admin'];
if ($role !== '' && in_array($role, $valides, true) && !in_array($role, $exclus, true)) {
    $_SESSION['apercu_role'] = $role;
}

header('Location: index.php');
exit();
