<?php
// ============================================================
// media.php — diffusion sécurisée des fichiers stockés sur le volume.
// Connexion obligatoire + contrôle d'accès par module. Streaming avec
// support des requêtes Range (indispensable pour lire/naviguer une vidéo).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/modules.php';
require_once __DIR__ . '/includes/storage_stats.php';

// 1) Connexion obligatoire.
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Accès refusé');
}

// 2) Clé de fichier : relative, sans remontée de dossier.
$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($f === '' || strpos($f, '..') !== false || strpos($f, "\0") !== false
    || preg_match('#^[A-Za-z0-9_./-]+$#', $f) !== 1) {
    http_response_code(400);
    exit('Requête invalide');
}

// 3) Résolution de la base : anciens fichiers sous public/, nouveaux sur le volume.
if (strpos($f, 'uploads/') === 0) {
    $base = __DIR__;
} else {
    $base = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
}
$real = realpath($base . '/' . $f);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real, $baseReal) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('Fichier introuvable');
}

// 4) Contrôle d'accès par module : si ce fichier appartient à un module,
//    on applique les mêmes droits que pour voir le module.
try {
    $stmt = $db->prepare("SELECT * FROM modules WHERE pdf_path = ? OR video_path = ? LIMIT 1");
    $stmt->execute([$f, $f]);
    $mod = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($mod && function_exists('userCanSeeModule')) {
        if (!userCanSeeModule($mod, (string) ($_SESSION['role'] ?? ''))) {
            http_response_code(403);
            exit('Accès refusé');
        }
    }
} catch (Exception $e) {
    // En cas d'anomalie DB, on ne bloque pas un utilisateur connecté.
}

// 5) On coupe tout buffer (thème/scroll) : on streame du binaire.
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$size = filesize($real);
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    // Pistes de sous-titres : le navigateur REFUSE un <track> qui n'est pas servi
    // en text/vtt. Le .srt est gardé pour référence (téléchargement).
    'vtt' => 'text/vtt', 'srt' => 'application/x-subrip',
];
$ctype = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('X-Content-Type-Options: nosniff');
// Fichiers à noms uniques (jamais réécrits) -> cache navigateur d'1 semaine.
// Compromis egress / sécurité : si un accès est retiré (départ, exclusion…), la copie
// déjà mise en cache expire au bout de 7 jours au plus. « private » : cache par utilisateur.
header('Cache-Control: private, max-age=604800, immutable');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT');

// 6) Support Range (lecture partielle / navigation vidéo).
$start = 0;
$end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int) $m[1]; }
    if ($m[2] !== '') { $end = (int) $m[2]; }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    if ($end >= $size) { $end = $size - 1; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

// 6bis) Compteur d'EGRESS : on ne compte QUE ce qui sort réellement du serveur.
// (Un fichier servi depuis le cache du navigateur ne passe pas ici → non compté,
//  ce qui est exact puisque le fournisseur ne le facture pas non plus.)
egressAdd($db, $end - $start + 1);

// 7) Envoi par blocs.
$fp = fopen($real, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}
fseek($fp, $start);
$remaining = $end - $start + 1;
$chunk = 8192;
while ($remaining > 0 && !feof($fp)) {
    $read = ($remaining > $chunk) ? $chunk : $remaining;
    $buf = fread($fp, $read);
    if ($buf === false) { break; }
    echo $buf;
    flush();
    $remaining -= strlen($buf);
}
fclose($fp);
exit;
