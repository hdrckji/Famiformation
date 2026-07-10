<?php
// ============================================================
// transcription.php — pipeline sous-titres pour l'import vidéo.
//   1) Si un .srt est fourni avec la vidéo -> on le lit (GRATUIT, exact).
//   2) Sinon -> repli Whisper (à activer en ajoutant OPENAI_API_KEY).
// Ajout NON destructif : ce fichier ne modifie aucun code existant.
// À inclure là où on traite le contenu : require_once __DIR__.'/includes/transcription.php';
// ============================================================

/**
 * Retourne les sous-titres (texte FR) d'une vidéo.
 * @param string $srtPath   chemin absolu du .srt (ou '' si aucun)
 * @param string $videoPath chemin absolu de la vidéo (pour le repli Whisper)
 * @return array ['ok'=>bool, 'source'=>'srt'|'whisper'|'none', 'text'=>string, 'error'=>string]
 */
function fami_get_transcript($srtPath, $videoPath)
{
    // Chemin 1 — un .srt a été déposé : lecture directe, aucun coût, texte exact.
    if ($srtPath !== '' && is_file($srtPath)) {
        $raw = @file_get_contents($srtPath);
        if ($raw === false) {
            return ['ok' => false, 'source' => 'srt', 'text' => '', 'error' => 'Lecture du .srt impossible'];
        }
        return ['ok' => true, 'source' => 'srt', 'text' => fami_parse_srt($raw), 'error' => ''];
    }

    // Chemin 2 — pas de .srt : on tente Whisper (repli).
    return fami_whisper_transcribe($videoPath);
}

/**
 * Transforme un contenu .srt en texte brut : retire numéros de blocs,
 * timecodes et balises, puis dédoublonne les lignes consécutives identiques
 * (fréquent avec les sous-titres auto de CapCut).
 */
function fami_parse_srt($srt)
{
    $srt = preg_replace('/^\xEF\xBB\xBF/', '', (string) $srt); // enlève le BOM éventuel
    $lines = preg_split('/\r\n|\r|\n/', $srt);
    $out = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t === '') continue;                        // ligne vide
        if (ctype_digit($t)) continue;                  // numéro de bloc (1, 2, 3...)
        if (strpos($t, '-->') !== false) continue;      // timecode 00:00:01,000 --> 00:00:03,000
        $t = preg_replace('/<[^>]+>/', '', $t);         // balises <i>, <b>, {\an8}, etc.
        $t = preg_replace('/\{[^}]*\}/', '', $t);
        $t = trim($t);
        if ($t !== '') $out[] = $t;
    }
    // dédoublonnage des répétitions consécutives
    $clean = [];
    foreach ($out as $line) {
        if (empty($clean) || end($clean) !== $line) $clean[] = $line;
    }
    return trim(implode("\n", $clean));
}

/**
 * Transcription audio via Whisper (OpenAI). PRÊT À ACTIVER.
 * -> Il te suffit d'ajouter la variable OPENAI_API_KEY dans Railway.
 *    Aucune autre modification de code nécessaire ici.
 *
 * ⚠️ Limite Whisper : fichier <= 25 Mo. Pour une grosse vidéo il faudra
 *    d'abord extraire l'audio (ffmpeg) — voir la note en bas de fonction.
 */
function fami_whisper_transcribe($videoPath)
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey && isset($_SERVER['OPENAI_API_KEY'])) {
        $apiKey = $_SERVER['OPENAI_API_KEY'];
    }

    // Pas encore branché : on renvoie une erreur claire au lieu de planter.
    if (!$apiKey) {
        return [
            'ok' => false, 'source' => 'none', 'text' => '',
            'error' => 'Whisper non configuré (OPENAI_API_KEY manquante) et aucun .srt fourni.',
        ];
    }
    if (!is_file($videoPath)) {
        return ['ok' => false, 'source' => 'none', 'text' => '', 'error' => 'Fichier vidéo introuvable'];
    }

    // Garde-fou taille (Whisper refuse > 25 Mo).
    $size = @filesize($videoPath);
    if ($size !== false && $size > 25 * 1024 * 1024) {
        return [
            'ok' => false, 'source' => 'whisper', 'text' => '',
            'error' => 'Vidéo > 25 Mo : extraire l\'audio (ffmpeg) avant Whisper, ou fournir un .srt.',
        ];
    }

    // --- Appel Whisper ---
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => [
            'file'            => new CURLFile($videoPath),
            'model'           => 'whisper-1',   // ou 'gpt-4o-mini-transcribe' (moins cher)
            'response_format' => 'text',
            'language'        => 'fr',          // force le français en entrée
        ],
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        return [
            'ok' => false, 'source' => 'whisper', 'text' => '',
            'error' => 'Whisper HTTP ' . $code . ' ' . $cErr . ' ' . substr((string) $resp, 0, 300),
        ];
    }
    return ['ok' => true, 'source' => 'whisper', 'text' => trim($resp), 'error' => ''];

    // NOTE grosses vidéos : si tu veux gérer > 25 Mo, extraire l'audio d'abord :
    //   ffmpeg -i video.mp4 -vn -ac 1 -ar 16000 -b:a 64k audio.mp3
    // puis passer audio.mp3 à Whisper. On ajoutera ça quand tu intégreras la clé.
}
