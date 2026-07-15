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
//      FAMI_STT_PROVIDER = groq (défaut, le moins cher) | openai | local (à venir)
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
        if (in_array($p, ['openai', 'groq', 'local'], true)) { return $p; }
        // Défaut : Groq (même modèle Whisper, nettement moins cher qu'OpenAI).
        // Repli automatique sur OpenAI si SEULE la clé OpenAI est configurée (pas de piège).
        $hasGroq = getenv('GROQ_API_KEY') || isset($_SERVER['GROQ_API_KEY']);
        $hasOpenai = getenv('OPENAI_API_KEY') || isset($_SERVER['OPENAI_API_KEY']);
        if (!$hasGroq && $hasOpenai) { return 'openai'; }
        return 'groq';
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

        // 1) PAS DE PISTE AUDIO DU TOUT → inutile d'appeler Whisper (et de le payer).
        //    ffprobe liste les flux audio : s'il n'en trouve aucun, on s'arrête ici.
        $probe = [];
        $pc = 0;
        @exec('ffprobe -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 '
            . escapeshellarg($videoAbs) . ' 2>&1', $probe, $pc);
        if ($pc === 0 && empty(array_filter($probe))) {
            return ''; // vidéo muette : aucun coût
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

        // 2) PISTE AUDIO PRÉSENTE MAIS SILENCIEUSE (cas fréquent : une vidéo « sans son » garde
        //    souvent une piste muette). Whisper la transcrirait quand même — facturée, pour rien.
        //    volumedetect donne le volume moyen : sous -50 dB, il n'y a rien à entendre.
        $vol = [];
        $vc = 0;
        @exec('ffmpeg -i ' . escapeshellarg($out) . ' -af volumedetect -f null - 2>&1', $vol, $vc);
        foreach ($vol as $line) {
            if (preg_match('/mean_volume:\s*(-?[\d.]+) dB/', $line, $m)) {
                if ((float) $m[1] < -50.0) {
                    @unlink($out);
                    return ''; // silence : aucun appel, aucun coût
                }
                break;
            }
        }

        return $out;
    }
}

if (!function_exists('famiSttPricing')) {
    /**
     * Prix de la transcription, en DOLLARS par MINUTE d'audio.
     * Groq est ~3× moins cher qu'OpenAI pour le même modèle Whisper — d'où le
     * choix de Groq par défaut (voir famiSttProvider).
     */
    function famiSttPricing()
    {
        return [
            'openai' => 0.0060,   // whisper-1        : 0,006 $/min
            'groq'   => 0.00185,  // whisper-large-v3 : 0,111 $/h
            'local'  => 0.0,      // à venir : tourne chez nous, donc gratuit
        ];
    }
}

if (!function_exists('famiAudioMinutes')) {
    /**
     * Durée (en minutes) déduite du POIDS du mp3 produit par famiExtractAudio().
     * Ce mp3 est encodé à débit CONSTANT (48 kbps) → durée = octets × 8 / 48000.
     * Évite d'appeler ffprobe : c'est gratuit, immédiat, et exact à la seconde près.
     */
    function famiAudioMinutes($audioAbs)
    {
        $b = (int) @filesize($audioAbs);
        if ($b <= 0) { return 0.0; }
        return ($b * 8 / 48000) / 60;
    }
}

if (!function_exists('famiSttRun')) {
    /**
     * Transcrit un fichier AUDIO et renvoie un SRT (avec timecodes).
     * Point d'extension unique : pour passer à un Whisper LOCAL, il suffira
     * d'ajouter ici un cas 'local' (appel à whisper.cpp) — rien d'autre à changer.
     *
     * $db (optionnel) : si fourni, le coût de l'appel est ENREGISTRÉ dans le
     * compteur API. Whisper en était absent → les transcriptions coûtaient de
     * l'argent sans jamais apparaître dans le total « Coûts API ».
     *
     * @return array ['ok'=>bool, 'srt'=>string, 'error'=>string]
     */
    function famiSttRun($audioAbs, $lang = 'fr', $db = null)
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

        // AUTO : on laisse Whisper DÉTECTER la langue parlée. Il faut alors « verbose_json »
        // (qui renvoie la langue détectée + les segments horodatés), et on reconstruit le SRT.
        $auto = ($lang === '' || $lang === 'auto');
        $fields = [
            'file' => new CURLFile($audioAbs),
            'model' => $model,
            'response_format' => $auto ? 'verbose_json' : 'srt',
        ];
        if (!$auto) { $fields['language'] = $lang; } // langue imposée : format SRT direct

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => $fields,
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
        $detected = ($lang === 'nl') ? 'nl' : (($lang === 'fr') ? 'fr' : '');
        if ($auto) {
            // verbose_json → { language, segments:[{start,end,text}] }. On batit le SRT nous-memes.
            $j = json_decode((string) $resp, true);
            $srt = is_array($j) ? famiSegmentsToSrt($j['segments'] ?? []) : '';
            $L = is_array($j) ? strtolower((string) ($j['language'] ?? '')) : '';
            // Whisper renvoie un nom complet (« dutch », « french »…) ou un code.
            if (strpos($L, 'dutch') !== false || strpos($L, 'nederlands') !== false || $L === 'nl') { $detected = 'nl'; }
            else { $detected = 'fr'; } // par defaut le francais (langue la plus courante ici)
        } else {
            $srt = trim((string) $resp);
        }
        if ($srt === '') {
            return ['ok' => false, 'srt' => '', 'error' => 'Transcription vide.'];
        }

        // COÛT : on facture à la minute d'audio. Sans cet enregistrement, les appels
        // OpenAI/Groq n'apparaissaient nulle part et le total « Coûts API » était faux.
        if ($db instanceof PDO) {
            require_once __DIR__ . '/ia_usage.php';
            $usd = famiAudioMinutes($audioAbs) * (famiSttPricing()[$provider] ?? 0.0);
            iaLogUsage(
                $db,
                (int) ($_SESSION['user_id'] ?? 0),
                'transcription',
                $model,
                0,
                0,
                $usd * 0.92, // $ → € (le compteur est en euros)
                null,
                ($provider === 'groq') ? 'Groq (Whisper)' : 'OpenAI (Whisper)'
            );
        }

        return ['ok' => true, 'srt' => $srt, 'lang' => $detected, 'error' => ''];
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
    function famiSrtTranslate($db, $srt, $from = 'fr', $to = 'nl')
    {
        $cues = famiSrtParse($srt);
        if (empty($cues)) { return ['ok' => false, 'srt' => '', 'error' => 'SRT vide']; }
        if (!function_exists('aiTranslateStringsToNl')) { require_once __DIR__ . '/i18n_nl.php'; }
        $src = [];
        foreach ($cues as $c) { $src[] = $c['text']; }
        $tr = aiTranslateStringsToNl($db, $src, $from, $to);
        if (!$tr['ok']) { return ['ok' => false, 'srt' => '', 'error' => $tr['error']]; }
        foreach ($cues as $i => $c) {
            if (isset($tr['items'][$i])) { $cues[$i]['text'] = (string) $tr['items'][$i]; }
        }
        return ['ok' => true, 'srt' => famiSrtBuild($cues), 'error' => ''];
    }

    /** Compatibilite : FR -> NL. */
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

if (!function_exists('famiSegmentsToSrt')) {
    /** Construit un SRT a partir des segments verbose_json de Whisper. */
    function famiSegmentsToSrt(array $segments)
    {
        $fmt = function ($sec) {
            $sec = max(0.0, (float) $sec);
            $h = (int) floor($sec / 3600);
            $m = (int) floor(($sec - $h * 3600) / 60);
            $s = (int) floor($sec - $h * 3600 - $m * 60);
            $ms = (int) round(($sec - floor($sec)) * 1000);
            return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
        };
        $out = '';
        $i = 1;
        foreach ($segments as $seg) {
            $txt = trim((string) ($seg['text'] ?? ''));
            if ($txt === '') { continue; }
            $out .= $i . "\n" . $fmt($seg['start'] ?? 0) . ' --> ' . $fmt($seg['end'] ?? 0) . "\n" . $txt . "\n\n";
            $i++;
        }
        return trim($out);
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
    function famiVideoSubtitles($db, $videoAbs, $srtAbs = '', $withNl = true)
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

        // Langue de la piste SOURCE. Un .srt fourni : on ne peut pas la deviner -> francais par
        // defaut (le teamcoach qui fournit un .srt connait sa langue). Whisper, lui, la detecte.
        $srcLang = 'fr';

        // 2) Sinon : transcription automatique — Whisper DETECTE la langue parlee.
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
                    'error' => "Aucun son à transcrire (vidéo muette ou silencieuse) — rien n'a été facturé.",
                ];
            }
            $r = famiSttRun($audio, 'auto', $db); // 'auto' -> detection de la langue parlee
            @unlink($audio);
            if (!$r['ok']) {
                return [
                    'ok' => false, 'srt_fr' => '', 'srt_nl' => '', 'text' => '', 'source' => 'none',
                    'error' => $r['error'],
                ];
            }
            $srtFr = $r['srt'];                       // (variable historique : contient la piste SOURCE)
            $srcLang = ($r['lang'] ?? 'fr') === 'nl' ? 'nl' : 'fr';
            $source = 'whisper';
        }

        // La piste SOURCE va dans le bon emplacement (fr ou nl) selon la langue detectee.
        $srtSrc = $srtFr;
        $sub = ['fr' => '', 'nl' => ''];
        $sub[$srcLang] = $srtSrc;

        // 3) Traduction vers l'AUTRE langue (bilingue). Coûteux -> a l'import $withNl = false ;
        //    la 2e piste est generee a la validation finale (famiEnsureOtherSubtitles).
        $errNl = '';
        $dstLang = ($srcLang === 'nl') ? 'fr' : 'nl';
        if ($withNl) {
            $tr = famiSrtTranslate($db, $srtSrc, $srcLang, $dstLang);
            if ($tr['ok']) { $sub[$dstLang] = $tr['srt']; } else { $errNl = (string) $tr['error']; }
        }

        return [
            'ok' => true,
            'srt_fr' => $sub['fr'],
            'srt_nl' => $sub['nl'],
            'lang' => $srcLang,                       // langue de la piste source (= langue parlee)
            'text' => famiSrtToText($srtSrc),          // alimente le quiz (dans la langue parlee)
            'source' => $source,
            'error' => ($withNl && $sub[$dstLang] === '') ? 'Sous-titres traduits non générés (' . $errNl . ')' : '',
        ];
    }
}

if (!function_exists('famiPersistSubtitles')) {
    /**
     * Écrit les pistes VTT (FR + NL) sur le volume et met à jour le module.
     * Réutilisable par le worker de fond ET par le traitement SYNCHRONE d'un .srt fourni.
     * @return bool true si au moins la piste FR a été écrite.
     */
    function famiPersistSubtitles(PDO $db, $moduleId, array $subs)
    {
        if (empty($subs['ok'])) { return false; }
        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        $dir = $base . '/modules/subs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $stem = 'sub_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $frKey = '';
        $nlKey = '';
        if (trim((string) ($subs['srt_fr'] ?? '')) !== ''
            && @file_put_contents($dir . '/' . $stem . '_fr.vtt', famiSrtToVtt($subs['srt_fr'])) !== false) {
            $frKey = 'modules/subs/' . $stem . '_fr.vtt';
        }
        if (trim((string) ($subs['srt_nl'] ?? '')) !== ''
            && @file_put_contents($dir . '/' . $stem . '_nl.vtt', famiSrtToVtt($subs['srt_nl'])) !== false) {
            $nlKey = 'modules/subs/' . $stem . '_nl.vtt';
        }
        $srcLang = (($subs['lang'] ?? 'fr') === 'nl') ? 'nl' : 'fr';
        try {
            $db->prepare("UPDATE modules SET sub_fr_path = ?, sub_nl_path = ?, transcript = ?, source_lang = ?, sub_status = 'ready' WHERE id = ?")
               ->execute([
                   $frKey !== '' ? $frKey : null,
                   $nlKey !== '' ? $nlKey : null,
                   trim((string) ($subs['text'] ?? '')) !== '' ? $subs['text'] : null,
                   $srcLang,
                   (int) $moduleId,
               ]);
        } catch (Exception $e) {
            return false;
        }
        // Au moins UNE piste (la source) doit avoir ete ecrite.
        return ($frKey !== '' || $nlKey !== '');
    }
}

if (!function_exists('famiEnsureNlSubtitles')) {
    /**
     * Génère la piste de sous-titres NÉERLANDAISE d'une vidéo si elle manque.
     * Appelé à la VALIDATION FINALE (pas à l'import) : la traduction est un appel IA,
     * on ne fait donc pas attendre l'utilisateur pendant l'upload.
     * @return bool true si la piste NL existe (déjà ou nouvellement créée)
     */
    function famiEnsureNlSubtitles(PDO $db, $videoModuleId)
    {
        // Generalise : on complete la piste MANQUANTE en traduisant depuis celle qui existe,
        // dans le BON SENS. Une video parlee en neerlandais a sa piste NL d'origine et sa
        // piste FR traduite ; une video francaise, l'inverse. (Le nom historique est conserve.)
        $videoModuleId = (int) $videoModuleId;
        if ($videoModuleId <= 0) { return false; }
        try {
            $st = $db->prepare("SELECT sub_fr_path, sub_nl_path, source_lang FROM modules WHERE id = ? LIMIT 1");
            $st->execute([$videoModuleId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
        if (!$r) { return false; }

        $frKey = trim((string) ($r['sub_fr_path'] ?? ''));
        $nlKey = trim((string) ($r['sub_nl_path'] ?? ''));
        if ($frKey !== '' && $nlKey !== '') { return true; } // les deux pistes existent deja

        // La langue SOURCE = celle de la piste presente (ou source_lang par securite).
        if ($frKey !== '' && $nlKey === '') { $from = 'fr'; $to = 'nl'; $srcKey = $frKey; }
        elseif ($nlKey !== '' && $frKey === '') { $from = 'nl'; $to = 'fr'; $srcKey = $nlKey; }
        else { return false; } // aucune piste : rien a traduire

        $base = defined('FAMI_STORAGE_BASE') ? rtrim(FAMI_STORAGE_BASE, '/') : (__DIR__ . '/../uploads');
        $srcAbs = $base . '/' . $srcKey;
        if (!is_file($srcAbs)) { return false; }
        $srcVtt = @file_get_contents($srcAbs);
        if ($srcVtt === false || trim((string) $srcVtt) === '') { return false; }

        // famiSrtParse gere aussi le WebVTT (entete ignore, timecodes conserves).
        $tr = famiSrtTranslate($db, (string) $srcVtt, $from, $to);
        if (empty($tr['ok']) || trim((string) $tr['srt']) === '') { return false; }

        $dir = $base . '/modules/subs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = 'sub_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $to . '.vtt';
        if (@file_put_contents($dir . '/' . $name, famiSrtToVtt($tr['srt'])) === false) { return false; }
        $col = ($to === 'nl') ? 'sub_nl_path' : 'sub_fr_path';
        try {
            $db->prepare("UPDATE modules SET $col = ? WHERE id = ?")
               ->execute(['modules/subs/' . $name, $videoModuleId]);
        } catch (Exception $e) {
            return false;
        }
        return true;
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
    function fami_get_transcript($srtPath, $videoPath, $db = null)
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
        $r = famiSttRun($audio, 'fr', $db); // $db → le coût entre dans le compteur API
        @unlink($audio);
        return [
            'ok' => $r['ok'],
            'source' => $r['ok'] ? 'whisper' : 'none',
            'text' => $r['ok'] ? famiSrtToText($r['srt']) : '',
            'error' => $r['error'],
        ];
    }
}
