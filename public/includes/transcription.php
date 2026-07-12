<?php
// ============================================================
// transcription.php — sous-titres & transcription des vidéos.
//
// OBJECTIF (Point 1) : rendre la vidéo BILINGUE et exploitable pour le QUIZ.
// Avant, l'audio et les sous-titres étaient « gravés » dans l'image → aucune
// donnée exploitable. Ici on produit :
//   - un SRT français (fourni ou transcrit automatiquement),
//   - un SRT néerlandais (traduit, timecodes conservés),
//   - deux pistes WebVTT affichées dans le lecteur (FR / NL),
//   - un TRANSCRIPT texte qui alimente la génération du quiz.
//
// DÉCISIONS PRISES EN AUTONOMIE (à débattre au retour) :
//
// 1) API plutôt qu'installation. Le site tourne sur Railway : une transcription
//    LOCALE (whisper.cpp) demanderait un binaire lourd + du CPU. On passe donc par
//    une API. MAIS tout est derrière une ABSTRACTION (famiSttProvider / famiSttRun)
//    et une variable d'env → basculer vers un Whisper LOCAL plus tard ne demandera
//    que d'ajouter un cas dans famiSttRun(). Rien d'autre à toucher.
//      FAMI_STT_PROVIDER = openai (défaut) | groq | local (à venir)
//      OPENAI_API_KEY / GROQ_API_KEY
//    Anthropic ne fait pas de speech-to-text : utiliser Whisper ici est normal,
//    le reste du projet reste sur Claude.
//
// 2) On EXTRAIT L'AUDIO avec ffmpeg avant d'envoyer à Whisper.
//    C'est LE point qui rendait l'ancien code inutilisable : Whisper refuse les
//    fichiers > 25 Mo, or nos vidéos vont jusqu'à 1 Go. Un mp3 mono 16 kHz 48 kbps
//    pèse ~3 Mo pour 10 min → on passe largement sous la limite (~70 min de vidéo),
//    et on n'envoie pas l'image (moins cher, plus rapide). ffmpeg est déjà installé
//    dans l'image Docker (il sert à la compression 720p).
//
// 3) On demande le format SRT (pas du texte brut) : sans timecodes, impossible
//    d'afficher des sous-titres. Les navigateurs ne lisent que le WebVTT → on
//    convertit. Le SRT reste la source (traduction, transcript).
//
// 4) Priorité au .srt fourni par l'utilisateur : c'est GRATUIT et exact. Whisper
//    n'est qu'un repli automatique — l'utilisateur peu à l'aise en informatique
//    n'a donc RIEN à faire.
//
// 5) TTS néerlandais (voix synthétique) : volontairement PAS implémenté. Pas de
//    solution gratuite correcte, et des sous-titres NL suffisent à rendre la vidéo
//    bilingue. On ne bloque pas le projet là-dessus.
// ============================================================

if (!function_exists('famiSttProvider')) {
    /** Fournisseur de transcription. Abstraction : on pourra ajouter 'local' plus tard. */
    function famiSttProvider()
    {
        $p = getenv('FAMI_STT_PROVIDER');
        if (!$p && isset($_SERVER['FAMI_STT_PROVIDER'])) {
            $p = $_SERVER['FAMI_STT_PROVIDER'];
        }
        $p = strtolower(trim((string) $p));
        return in_array($p, ['openai', 'groq', 'local'], true) ? $p : 'openai';
    }
}

if (!function_exists('famiSttKey')) {
    /** Clé du fournisseur courant (null si non configuré). */
    function famiSttKey()
    {
        $var = (famiSttProvider() === 'groq') ? 'GROQ_API_KEY' : 'OPENAI_API_KEY';
        $k = getenv($var);
        if (!$k && isset($_SERVER[$var])) {
            $k = $_SERVER[$var];
        }
        $k = trim((string) $k);
        return $k !== '' ? $k : null;
    }
}

if (!function_exists('famiSttReady')) {
    /** La transcription automatique est-elle utilisable ? (sert aussi à l'onglet Outils) */
    function famiSttReady()
    {
        return famiSttKey() !== null;
    }
}

if (!function_exists('famiExtractAudio')) {
    /**
     * Extrait la piste audio d'une vidéo en mp3 mono 16 kHz (léger).
     * C'est ce qui permet de rester sous la limite des 25 Mo de Whisper,
     * même pour une vidéo de 1 Go.
     * @return string chemin absolu du mp3, ou '' en cas d'échec.
     */
    function famiExtractAudio($videoAbs)
    {
        if (!is_file($videoAbs) || !function_exists('exec')) {
            return '';
        }
        $out = sys_get_temp_dir() . '/fami_audio_' . bin2hex(random_bytes(6)) . '.mp3';
        // -vn : pas d'image · -ac 1 : mono · -ar 16000 : 16 kHz (ce que Whisper attend)
        // -b:a 48k : ~3 Mo pour 10 min → ~70 min de vidéo sous la limite des 25 Mo.
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($videoAbs)
            . ' -vn -ac 1 -ar 16000 -b:a 48k ' . escapeshellarg($out) . ' 2>&1';
        $o = [];
        $code = 0;
        @exec($cmd, $o, $code);
        if ($code !== 0 || !is_file($out) || filesize($out) <= 0) {
            @unlink($out);
            return '';
        }
        return $out;
    }
}

if (!function_exists('famiSttRun')) {
    /**
     * Transcrit un fichier AUDIO et renvoie un SRT (avec timecodes).
     * Point d'extension unique : pour passer à un Whisper LOCAL, il suffira
     * d'ajouter ici un cas 'local' (appel à whisper.cpp) — rien d'autre à changer.
     *
     * @return array ['ok'=>bool, 'srt'=>string, 'error'=>string]
     */
    function famiSttRun($audioAbs, $lang = 'fr')
    {
        $provider = famiSttProvider();

        if ($provider === 'local') {
            // Réservé : quand le site tournera en local, brancher whisper.cpp ici
            // (bien plus rentable). L'appelant n'aura RIEN à changer.
            return ['ok' => false, 'srt' => '', 'error' => 'Transcription locale pas encore branchée (prévu pour l\'hébergement local).'];
        }

        $key = famiSttKey();
        if ($key === null) {
            return ['ok' => false, 'srt' => '', 'error' => 'Transcription non configurée (clé API absente).'];
        }
        if (!is_file($audioAbs)) {
            return ['ok' => false, 'srt' => '', 'error' => 'Fichier audio introuvable.'];
        }
        $size = (int) @filesize($audioAbs);
        if ($size > 25 * 1024 * 1024) {
            return ['ok' => false, 'srt' => '', 'error' => 'Audio > 25 Mo : vidéo trop longue pour une seule passe (fournir un .srt).'];
        }

        // Groq expose la même API que OpenAI (compatible), d'où le partage du code.
        $url = ($provider === 'groq')
            ? 'https://api.groq.com/openai/v1/audio/transcriptions'
            : 'https://api.openai.com/v1/audio/transcriptions';
        $model = ($provider === 'groq') ? 'whisper-large-v3' : 'whisper-1';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($audioAbs),
                'model' => $model,
                'response_format' => 'srt', // ← timecodes indispensables aux sous-titres
                'language' => $lang,
            ],
            CURLOPT_TIMEOUT => 900,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            return [
                'ok' => false, 'srt' => '',
                'error' => 'Transcription HTTP ' . $code . ' ' . $cErr . ' ' . substr((string) $resp, 0, 200),
            ];
        }
        $srt = trim((string) $resp);
        if ($srt === '') {
            return ['ok' => false, 'srt' => '', 'error' => 'Transcription vide.'];
        }
        return ['ok' => true, 'srt' => $srt, 'error' => ''];
    }
}

if (!function_exists('famiSrtParse')) {
    /**
     * Découpe un SRT en séquences : [['time' => '00:00:01,000 --> 00:00:03,000', 'text' => "..."], ...]
     * Sert à traduire le TEXTE sans jamais toucher aux TIMECODES.
     */
    function famiSrtParse($srt)
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', (string) $srt); // BOM éventuel
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        $cues = [];
        foreach (preg_split("/\n\s*\n/", trim($srt)) as $block) {
            $lines = preg_split("/\n/", trim($block));
            $time = '';
            $text = [];
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l === '' || ctype_digit($l)) {
                    continue; // numéro de séquence
                }
                if (strpos($l, '-->') !== false) {
                    $time = $l;
                    continue;
                }
                $text[] = $l;
            }
            if ($time !== '' && !empty($text)) {
                $cues[] = ['time' => $time, 'text' => implode("\n", $text)];
            }
        }
        return $cues;
    }
}

if (!function_exists('famiSrtBuild')) {
    /** Reconstruit un SRT à partir de séquences (timecodes inchangés). */
    function famiSrtBuild(array $cues)
    {
        $out = [];
        $i = 1;
        foreach ($cues as $c) {
            $out[] = $i++;
            $out[] = $c['time'];
            $out[] = $c['text'];
            $out[] = '';
        }
        return trim(implode("\n", $out)) . "\n";
    }
}

if (!function_exists('famiSrtToVtt')) {
    /**
     * SRT → WebVTT. Indispensable : la balise <track> des navigateurs ne lit
     * QUE le WebVTT (le .srt n'est pas supporté).
     */
    function famiSrtToVtt($srt)
    {
        $vtt = "WEBVTT\n\n";
        foreach (famiSrtParse($srt) as $c) {
            // WebVTT veut un point décimal (SRT utilise la virgule).
            $time = str_replace(',', '.', $c['time']);
            $vtt .= $time . "\n" . $c['text'] . "\n\n";
        }
        return $vtt;
    }
}

if (!function_exists('famiSrtToText')) {
    /**
     * SRT → texte brut (sans numéros ni timecodes), dédoublonné.
     * C'est CE texte qui alimente la génération du quiz depuis la vidéo.
     */
    function famiSrtToText($srt)
    {
        $lines = [];
        foreach (famiSrtParse($srt) as $c) {
            foreach (preg_split("/\n/", $c['text']) as $l) {
                $l = preg_replace('/<[^>]+>/', '', $l);   // <i>, <b>...
                $l = preg_replace('/\{[^}]*\}/', '', $l); // {\an8}...
                $l = trim($l);
                if ($l === '') {
                    continue;
                }
                // Les sous-titres auto répètent souvent la ligne précédente (CapCut...).
                if (empty($lines) || end($lines) !== $l) {
                    $lines[] = $l;
                }
            }
        }
        return trim(implode(' ', $lines));
    }
}

if (!function_exists('famiSrtTranslateToNl')) {
    /**
     * Traduit un SRT FR → NL en conservant EXACTEMENT les timecodes.
     * Réutilise le traducteur en lot de i18n_nl.php (extraction → traduction →
     * réinjection), donc aucun risque de décalage des séquences.
     */
    function famiSrtTranslateToNl($db, $srt)
    {
        $cues = famiSrtParse($srt);
        if (empty($cues)) {
            return ['ok' => false, 'srt' => '', 'error' => 'SRT vide'];
        }
        if (!function_exists('aiTranslateStringsToNl')) {
            require_once __DIR__ . '/i18n_nl.php';
        }
        $src = [];
        foreach ($cues as $c) {
            $src[] = $c['text'];
        }
        $tr = aiTranslateStringsToNl($db, $src);
        if (!$tr['ok']) {
            return ['ok' => false, 'srt' => '', 'error' => $tr['error']];
        }
        foreach ($cues as $i => $c) {
            if (isset($tr['items'][$i])) {
                $cues[$i]['text'] = (string) $tr['items'][$i];
            }
        }
        return ['ok' => true, 'srt' => famiSrtBuild($cues), 'error' => ''];
    }
}

if (!function_exists('famiVideoSubtitles')) {
    /**
     * PIPELINE COMPLET pour une vidéo.
     *   1. .srt fourni par l'utilisateur → on le lit (gratuit, exact) ;
     *   2. sinon → extraction audio (ffmpeg) + transcription automatique (Whisper).
     * Puis : traduction NL (timecodes conservés) et texte pour le quiz.
     *
     * @return array ['ok','srt_fr','srt_nl','text','source','error']
     */
    function famiVideoSubtitles($db, $videoAbs, $srtAbs = '')
    {
        $srtFr = '';
        $source = '';

        // 1) SRT fourni : gratuit et exact → priorité absolue.
        if ($srtAbs !== '' && is_file($srtAbs)) {
            $raw = @file_get_contents($srtAbs);
            if ($raw !== false && trim((string) $raw) !== '') {
                $srtFr = (string) $raw;
                $source = 'srt';
            }
        }

        // 2) Sinon : transcription automatique (l'utilisateur n'a RIEN à faire).
        if ($srtFr === '') {
            if (!famiSttReady()) {
                return [
                    'ok' => false, 'srt_fr' => '', 'srt_nl' => '', 'text' => '', 'source' => 'none',
                    'error' => 'Aucun .srt fourni et transcription automatique non configurée (clé API absente).',
                ];
            }
            $audio = famiExtractAudio($videoAbs);
            if ($audio === '') {
                return [
                    'ok' => false, 'srt_fr' => '', 'srt_nl' => '', 'text' => '', 'source' => 'none',
                    'error' => 'Extraction audio impossible (ffmpeg).',
                ];
            }
            $r = famiSttRun($audio, 'fr');
            @unlink($audio); // on ne garde pas l'audio temporaire
            if (!$r['ok']) {
                return [
                    'ok' => false, 'srt_fr' => '', 'srt_nl' => '', 'text' => '', 'source' => 'none',
                    'error' => $r['error'],
                ];
            }
            $srtFr = $r['srt'];
            $source = 'whisper';
        }

        // 3) Version néerlandaise (sous-titres bilingues). Non bloquant : si la
        //    traduction échoue, on garde au moins le FR.
        $srtNl = '';
        $tr = famiSrtTranslateToNl($db, $srtFr);
        if ($tr['ok']) {
            $srtNl = $tr['srt'];
        }

        return [
            'ok' => true,
            'srt_fr' => $srtFr,
            'srt_nl' => $srtNl,
            'text' => famiSrtToText($srtFr), // alimente le quiz
            'source' => $source,
            'error' => $srtNl === '' ? 'Sous-titres NL non générés (' . $tr['error'] . ')' : '',
        ];
    }
}

// --- Compatibilité : ancienne API, conservée pour ne rien casser. ---
if (!function_exists('fami_parse_srt')) {
    function fami_parse_srt($srt)
    {
        return famiSrtToText($srt);
    }
}
if (!function_exists('fami_get_transcript')) {
    function fami_get_transcript($srtPath, $videoPath)
    {
        if ($srtPath !== '' && is_file($srtPath)) {
            $raw = @file_get_contents($srtPath);
            if ($raw !== false) {
                return ['ok' => true, 'source' => 'srt', 'text' => famiSrtToText($raw), 'error' => ''];
            }
        }
        $audio = famiExtractAudio($videoPath);
        if ($audio === '') {
            return ['ok' => false, 'source' => 'none', 'text' => '', 'error' => 'Extraction audio impossible'];
        }
        $r = famiSttRun($audio, 'fr');
        @unlink($audio);
        return [
            'ok' => $r['ok'],
            'source' => $r['ok'] ? 'whisper' : 'none',
            'text' => $r['ok'] ? famiSrtToText($r['srt']) : '',
            'error' => $r['error'],
        ];
    }
}
