<?php
// ============================================================
// video_transcode.php — worker CLI lancé en tâche de fond.
// Compresse une vidéo brute déposée en MP4 720p (H.264 + AAC) « faststart »
// pour un démarrage instantané et une lecture fluide, puis met à jour le module.
// Usage :  php video_transcode.php <cleSourceBrute> <idModule>
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/storage_stats.php';

$rawKey = isset($argv[1]) ? (string) $argv[1] : '';
$moduleId = isset($argv[2]) ? (int) $argv[2] : 0;
if ($rawKey === '' || $moduleId <= 0) {
    exit(1);
}

$base = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
$rawFull = $base . '/' . $rawKey;

$markFailed = function () use ($db, $moduleId) {
    try {
        $db->prepare("UPDATE modules SET video_status = 'failed' WHERE id = ?")->execute([$moduleId]);
    } catch (Exception $e) {
        // rien
    }
};

if (!is_file($rawFull)) {
    $markFailed();
    exit(1);
}

// Dossier de sortie (vidéos prêtes) sur le volume.
$outDir = $base . '/modules/video';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}
$outName = 'video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.mp4';
$outFull = $outDir . '/' . $outName;
$outKey = 'modules/video/' . $outName;

// Ré-encodage : 720p max (sans upscale), débit plafonné pour les connexions moyennes,
// pixel format compatible navigateurs, index déplacé en tête (+faststart).
$filter = "scale=-2:min(720\\,ih)";
$cmd = 'ffmpeg -y -i ' . escapeshellarg($rawFull)
    . ' -vf ' . escapeshellarg($filter)
    . ' -c:v libx264 -preset fast -crf 23 -maxrate 2000k -bufsize 4000k -pix_fmt yuv420p'
    . ' -c:a aac -b:a 128k -movflags +faststart '
    . escapeshellarg($outFull) . ' 2>&1';

$output = [];
$code = 0;
@exec($cmd, $output, $code);

if ($code === 0 && is_file($outFull) && filesize($outFull) > 0) {
    try {
        $db->prepare("UPDATE modules SET video_path = ?, video_status = 'ready', video_src_path = NULL WHERE id = ?")
           ->execute([$outKey, $moduleId]);
        @unlink($rawFull); // on jette la vidéo brute (économie de stockage)
        // Le volume vient de changer (brute supprimée, version compressée ajoutée) :
        // on enregistre un point d'historique pour la facturation au pro rata.
        storageRecordSample($db);
    } catch (Exception $e) {
        // si l'update échoue, on garde tout pour un éventuel retry
    }
    exit(0);
}

@unlink($outFull);
$markFailed();
exit(1);
