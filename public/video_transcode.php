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
require_once __DIR__ . '/includes/ia_settings.php';   // modèle IA (traduction des sous-titres)
require_once __DIR__ . '/includes/i18n_nl.php';       // traducteur en lot FR -> NL
require_once __DIR__ . '/includes/transcription.php'; // sous-titres : .srt fourni ou Whisper

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
$rawBase = preg_replace('/[^A-Za-z0-9_-]/', '', pathinfo($rawKey, PATHINFO_FILENAME));
$outName = ($rawBase !== '' ? $rawBase : ('video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)))) . '.mp4';
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

    // ---------------------------------------------------------------
    // SOUS-TITRES : on enchaîne ICI, dans la même tâche de fond.
    // C'est le bon endroit : on est déjà hors requête web, ffmpeg est là, et la
    // vidéo compressée vient d'être produite. L'utilisateur n'attend rien.
    // Non bloquant : si ça échoue, la vidéo reste parfaitement lisible.
    // ---------------------------------------------------------------
    famiBuildSubtitles($db, $moduleId, $outFull, $outKey);

    exit(0);
}

@unlink($outFull);
$markFailed();
exit(1);

/**
 * Produit les sous-titres FR + NL d'une vidéo et le transcript (qui servira au quiz).
 *  - un .srt déposé par l'utilisateur est prioritaire (gratuit, exact) ;
 *  - sinon transcription automatique (audio extrait par ffmpeg → Whisper).
 * Les fichiers WebVTT sont écrits sur le volume et servis par media.php.
 */
function famiBuildSubtitles(PDO $db, $moduleId, $videoAbs, $videoKey)
{
    $base = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');

    // Le .srt éventuellement déposé avec la vidéo.
    $srtAbs = '';
    try {
        $st = $db->prepare("SELECT sub_src_path FROM modules WHERE id = ? LIMIT 1");
        $st->execute([$moduleId]);
        $k = trim((string) $st->fetchColumn());
        if ($k !== '' && is_file($base . '/' . $k)) {
            $srtAbs = $base . '/' . $k;
        }
    } catch (Exception $e) {
        // colonne absente : on continuera sans .srt
    }

    try {
        $db->prepare("UPDATE modules SET sub_status = 'processing' WHERE id = ?")->execute([$moduleId]);
    } catch (Exception $e) {
        return; // colonnes pas encore créées : on abandonne proprement
    }

    $res = famiVideoSubtitles($db, $videoAbs, $srtAbs);
    if (!$res['ok'] || trim((string) $res['srt_fr']) === '') {
        try {
            $db->prepare("UPDATE modules SET sub_status = 'failed' WHERE id = ?")->execute([$moduleId]);
        } catch (Exception $e) {
        }
        return;
    }

    // Écriture des pistes WebVTT sur le volume (seul format lu par les navigateurs).
    $dir = $base . '/modules/subs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $stem = 'sub_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    $frKey = '';
    $nlKey = '';
    if (@file_put_contents($dir . '/' . $stem . '_fr.vtt', famiSrtToVtt($res['srt_fr'])) !== false) {
        $frKey = 'modules/subs/' . $stem . '_fr.vtt';
    }
    if (trim((string) $res['srt_nl']) !== ''
        && @file_put_contents($dir . '/' . $stem . '_nl.vtt', famiSrtToVtt($res['srt_nl'])) !== false) {
        $nlKey = 'modules/subs/' . $stem . '_nl.vtt';
    }

    try {
        $db->prepare(
            "UPDATE modules SET sub_fr_path = ?, sub_nl_path = ?, transcript = ?, sub_status = 'ready' WHERE id = ?"
        )->execute([
            $frKey !== '' ? $frKey : null,
            $nlKey !== '' ? $nlKey : null,
            $res['text'] !== '' ? $res['text'] : null,
            $moduleId,
        ]);
        storageRecordSample($db); // les .vtt occupent (un peu) de place
    } catch (Exception $e) {
        // non critique
    }

    // Le contenu de la vidéo est maintenant exploitable → on enrichit le quiz.
    if (trim((string) $res['text']) !== '') {
        famiEnrichQuizWithVideo($db, $moduleId, $res['text']);
    }
}

/**
 * Régénère le quiz du GUIDE en y intégrant le contenu de la VIDÉO.
 *
 * Pourquoi ici : quand le contenu est importé, le quiz est généré depuis le PDF
 * SEUL — la vidéo n'est pas encore transcrite. Maintenant qu'on a le transcript,
 * le quiz peut porter sur les DEUX supports, comme demandé.
 *
 * Garde-fou : on ne le fait qu'UNE FOIS (drapeau quiz_from_video). Sans ça, un
 * ré-upload de vidéo écraserait un quiz que l'admin aurait corrigé à la main.
 */
function famiEnrichQuizWithVideo(PDO $db, $videoModuleId, $transcript)
{
    try {
        // Le guide est le frère du module vidéo (même parent).
        $st = $db->prepare("SELECT parent_id FROM modules WHERE id = ? LIMIT 1");
        $st->execute([$videoModuleId]);
        $parentId = (int) $st->fetchColumn();
        if ($parentId <= 0) {
            return;
        }
        $st = $db->prepare(
            "SELECT id, contenu_ia, a_evaluer, quiz_from_video FROM modules
             WHERE parent_id = ? AND content_kind = 'ecrit' LIMIT 1"
        );
        $st->execute([$parentId]);
        $guide = $st->fetch(PDO::FETCH_ASSOC);
        if (!$guide) {
            return;
        }
        if (empty($guide['a_evaluer']) || !empty($guide['quiz_from_video'])) {
            return; // pas de quiz demandé, ou déjà enrichi : on ne touche à rien
        }

        $guideText = trim((string) ($guide['contenu_ia'] ?? ''));
        $source = "CONTENU ÉCRIT (le guide) :\n" . $guideText
            . "\n\n---\n\nCONTENU DE LA VIDÉO (transcription) :\n" . trim((string) $transcript);

        if (!function_exists('aiGenerateQuiz')) {
            require_once __DIR__ . '/includes/ai_uniformise.php';
        }
        $qz = aiGenerateQuiz($db, $source);
        if (empty($qz['ok']) || empty($qz['quiz'])) {
            return; // échec : on garde le quiz existant (issu du PDF), pas de régression
        }
        $db->prepare("UPDATE modules SET quiz_json = ?, quiz_from_video = 1 WHERE id = ?")
           ->execute([json_encode($qz['quiz'], JSON_UNESCAPED_UNICODE), (int) $guide['id']]);

        // Le quiz FR a changé → sa version NL doit suivre.
        if (function_exists('nlSyncModule')) {
            nlSyncModule($db, (int) $guide['id'], true);
        }
    } catch (Exception $e) {
        // non critique : le quiz issu du PDF reste en place
    }
}
