<?php
// ============================================================
// video_download.php — téléchargement de la vidéo d'une formation.
//   ?id=<module vidéo>            → sert le fichier (fusionné si dispo, sinon vidéo seule)
//   ?id=<module vidéo>&prepare=1  → fabrique la fusion si besoin, répond en JSON (sans servir)
//
//   Contrôles (les mêmes que le bouton) : l'admin doit avoir AUTORISÉ le téléchargement
//   vidéo ET l'apprenant doit avoir VALIDÉ le quiz. L'admin passe toujours.
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/pdf_access.php';
require_once 'includes/quiz_pass.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$module = $id > 0 ? getModuleById($db, $id) : null;
$prepare = !empty($_GET['prepare']);

function dlFail($prepare, $code, $msg)
{
    if ($prepare) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        http_response_code($code);
        echo $msg;
    }
    exit();
}

if (!$module || (($module['content_kind'] ?? '') !== 'video' && empty($module['video_path']) && empty($module['video_src_path']))) {
    dlFail($prepare, 404, 'Vidéo introuvable.');
}

$isAdmin = ((($_SESSION['role'] ?? '') === 'admin') && (!function_exists('isApercuActif') || !isApercuActif()));
$role = function_exists('currentDisplayRole') ? currentDisplayRole() : (string) ($_SESSION['role'] ?? '');
$parentId = !empty($module['parent_id']) ? (int) $module['parent_id'] : $id;

// 1) Droit d'accès au module.
if (!$isAdmin && function_exists('userCanSeeModule') && !userCanSeeModule($module, $role)) {
    dlFail($prepare, 403, 'Accès refusé.');
}
// 2) L'admin a-t-il autorisé le téléchargement vidéo ?
if (!videoCanDownload($db, $role, $isAdmin)) {
    dlFail($prepare, 403, 'Le téléchargement de la vidéo n\'est pas autorisé.');
}
// 3) L'apprenant a-t-il validé le quiz ? (l'admin passe toujours)
if (!$isAdmin && !quizUserPassed($db, $parentId, (int) ($_SESSION['user_id'] ?? 0))) {
    dlFail($prepare, 403, 'Terminez et validez le quiz pour télécharger la vidéo.');
}

$lang = function_exists('currentLang') ? currentLang() : 'fr';

// La vidéo seule (repli) : la 720p, sinon la source.
$plainKey = trim((string) ($module['video_path'] ?? ''));
if ($plainKey === '') { $plainKey = trim((string) ($module['video_src_path'] ?? '')); }

// ── MODE PRÉPARATION : on fabrique (au besoin) la fusion, puis on répond en JSON.
if ($prepare) {
    require_once 'includes/video_merge.php';
    $merged = videoMergePrepare($db, $id, $lang); // '' si pas d'intro/outro ou échec → vidéo seule
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'merged' => $merged !== '']);
    exit();
}

// ── MODE SERVICE : on envoie le fichier. Fusion à jour si elle existe, sinon vidéo seule.
require_once 'includes/video_merge.php';
$vm = getModuleById($db, $id);
$key = '';
$merged = trim((string) ($vm['merged_path'] ?? ''));
if ($merged !== '' && is_file(videoMergeAbs($merged))) {
    $key = $merged;
} else {
    $key = $plainKey; // repli : vidéo seule
}
if ($key === '') { dlFail(false, 404, 'Vidéo indisponible.'); }

$abs = videoMergeAbs($key);
if (!is_file($abs)) { dlFail(false, 404, 'Fichier introuvable.'); }

// Nom de fichier lisible pour l'utilisateur.
$nom = function_exists('moduleNom') ? moduleNom($module) : (string) ($module['nom'] ?? 'formation');
$slug = function_exists('moduleFileSlug') ? moduleFileSlug($nom) : preg_replace('/[^a-z0-9]+/i', '-', $nom);
$dlName = ($slug !== '' ? $slug : 'formation') . '.mp4';

// Envoi en flux (une vidéo peut peser lourd : on ne charge pas tout en mémoire).
$size = (int) @filesize($abs);
header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
if ($size > 0) { header('Content-Length: ' . $size); }
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) { ob_end_clean(); }
$fp = @fopen($abs, 'rb');
if ($fp) {
    while (!feof($fp)) { echo fread($fp, 262144); @flush(); }
    fclose($fp);
}
exit();
