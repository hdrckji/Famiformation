<?php
// ============================================================
// video_merge.php — FUSION intro + vidéo + outro pour le TÉLÉCHARGEMENT.
//
//   La LECTURE enchaîne les fichiers (playlist, zéro ré-encodage). Mais un
//   téléchargement, c'est UN fichier : on doit donc réellement coller les trois
//   vidéos avec ffmpeg. Comme elles ont souvent des résolutions/codecs différents,
//   on les normalise (720p, 30 fps) et on ré-encode — c'est lent (minutes), donc :
//     • on ne le fait qu'à la 1re demande, puis on MET EN CACHE (merged_path) ;
//     • on refait le cache seulement si l'intro, l'outro ou la vidéo a changé
//       (empreinte merged_hash) ;
//     • si ffmpeg échoue ou est absent → on servira la vidéo seule (repli).
// ============================================================

if (!function_exists('videoMergeSources')) {
    /** Les 3 sources (clés volume) pour une formation vidéo, dans l'ordre. Vidéo obligatoire. */
    function videoMergeSources(PDO $db, array $videoModule, $lang = 'fr')
    {
        require_once __DIR__ . '/branding.php';
        $main = trim((string) ($videoModule['video_path'] ?? ''));
        if ($main === '') { $main = trim((string) ($videoModule['video_src_path'] ?? '')); }
        if ($main === '') { return []; }

        $out = [];
        if (brandingClipsOn($db)) {
            $intro = brandingClipFor($db, 'intro', $lang);
            if ($intro === '' && $lang !== 'fr') { $intro = brandingClipFor($db, 'intro', 'fr'); }
            if ($intro !== '') { $out['intro'] = $intro; }
        }
        $out['main'] = $main;
        if (brandingClipsOn($db)) {
            $outro = brandingClipFor($db, 'outro', $lang);
            if ($outro === '' && $lang !== 'fr') { $outro = brandingClipFor($db, 'outro', 'fr'); }
            if ($outro !== '') { $out['outro'] = $outro; }
        }
        return $out;
    }

    /** Chemin absolu d'une clé volume. */
    function videoMergeAbs($key)
    {
        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        if (strpos((string) $key, 'uploads/') === 0) { return __DIR__ . '/../' . $key; }
        return $base . '/' . ltrim((string) $key, '/');
    }

    /**
     * Prépare (si besoin) la vidéo fusionnée et renvoie sa CLÉ volume, ou '' si on doit
     * se rabattre sur la vidéo seule (pas d'intro/outro, ffmpeg absent, échec).
     */
    function videoMergePrepare(PDO $db, $videoModuleId, $lang = 'fr')
    {
        $videoModuleId = (int) $videoModuleId;
        try {
            $st = $db->prepare("SELECT * FROM modules WHERE id = ? LIMIT 1");
            $st->execute([$videoModuleId]);
            $vm = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return ''; }
        if (!$vm) { return ''; }

        $sources = videoMergeSources($db, $vm, $lang);
        // Pas d'intro NI d'outro → rien à fusionner : la vidéo seule fera l'affaire.
        if (count($sources) < 2) { return ''; }

        // Empreinte : si une source change, le cache est périmé.
        $hash = substr(hash('sha256', $lang . '|' . implode('|', $sources)), 0, 32);
        $cached = trim((string) ($vm['merged_path'] ?? ''));
        if ($cached !== '' && (string) ($vm['merged_hash'] ?? '') === $hash
            && is_file(videoMergeAbs($cached))) {
            return $cached; // déjà fait, à jour
        }

        // Tous les fichiers présents ?
        $absList = [];
        foreach ($sources as $k => $key) {
            $abs = videoMergeAbs($key);
            if (!is_file($abs)) { return ''; }
            $absList[] = $abs;
        }
        if (!function_exists('exec')) { return ''; }

        // Sortie sur le volume (catégorie « merged », comptée dans le stockage).
        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        $dir = $base . '/merged';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = 'merged_' . $videoModuleId . '_' . $hash . '.mp4';
        $outAbs = $dir . '/' . $name;
        $outKey = 'merged/' . $name;

        // Concaténation avec NORMALISATION : chaque segment est mis à l'échelle 1280×720
        // (avec bandes si besoin), 30 fps, puis on concatène vidéo + audio. On assume que
        // chaque segment a une piste audio (les intros/outros en ont une, c'est de la voix).
        $n = count($absList);
        $inputs = '';
        $vfilters = '';
        $concat = '';
        foreach ($absList as $i => $abs) {
            $inputs .= ' -i ' . escapeshellarg($abs);
            $vfilters .= '[' . $i . ':v]scale=1280:720:force_original_aspect_ratio=decrease,'
                . 'pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=30[v' . $i . '];';
            $concat .= '[v' . $i . '][' . $i . ':a]';
        }
        $filter = $vfilters . $concat . 'concat=n=' . $n . ':v=1:a=1[v][a]';

        $tmp = $outAbs . '.tmp.mp4';
        $cmd = 'ffmpeg -y' . $inputs
            . ' -filter_complex ' . escapeshellarg($filter)
            . ' -map ' . escapeshellarg('[v]') . ' -map ' . escapeshellarg('[a]')
            . ' -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg($tmp) . ' 2>&1';

        @set_time_limit(0);
        $o = []; $code = 0;
        @exec($cmd, $o, $code);
        if ($code !== 0 || !is_file($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            if (function_exists('logSiteError')) {
                require_once __DIR__ . '/events.php';
                logSiteError($db, $videoModuleId, 0, 'video', 'Fusion intro/vidéo/outro échouée.');
            }
            return ''; // repli : vidéo seule
        }
        @rename($tmp, $outAbs);

        // On remplace l'ancien cache (autre hash) et on enregistre le nouveau.
        $old = trim((string) ($vm['merged_path'] ?? ''));
        if ($old !== '' && $old !== $outKey) {
            $oldAbs = videoMergeAbs($old);
            if (is_file($oldAbs)) { @unlink($oldAbs); }
        }
        try {
            $db->prepare("UPDATE modules SET merged_path = ?, merged_hash = ? WHERE id = ?")
               ->execute([$outKey, $hash, $videoModuleId]);
        } catch (Exception $e) {}
        return $outKey;
    }
}
