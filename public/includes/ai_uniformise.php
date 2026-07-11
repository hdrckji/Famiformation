<?php
// ============================================================
// ai_uniformise.php — moteur IA : lit un PDF et le réécrit en contenu
// uniformisé (français, structuré), avec le modèle choisi dans les réglages IA.
// Additif : n'appelle rien de l'existant. Nécessite ANTHROPIC_API_KEY (Railway).
// ============================================================

if (!function_exists('aiModelPricing')) {
    /** Prix approximatif $ / 1M tokens [entrée, sortie] par modèle. */
    function aiModelPricing()
    {
        return [
            'claude-sonnet-5'  => [2.0, 10.0],   // tarif promo en cours
            'claude-haiku-4-5' => [1.0, 5.0],
            'claude-opus-4-8'  => [5.0, 25.0],
        ];
    }
}

if (!function_exists('aiUniformisePdf')) {
    /**
     * Envoie le PDF à Claude et récupère le contenu uniformisé (Markdown FR).
     * @param PDO    $db
     * @param string $pdfPath  chemin absolu du PDF
     * @return array ['ok'=>bool, 'text'=>string, 'error'=>string, 'model'=>string,
     *                'in'=>int, 'out'=>int, 'cost_eur'=>float]
     */
    function aiUniformisePdf($db, $pdfPath)
    {
        $fail = function ($msg) {
            return ['ok' => false, 'text' => '', 'error' => $msg, 'model' => '', 'in' => 0, 'out' => 0, 'cost_eur' => 0.0];
        };

        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey && isset($_SERVER['ANTHROPIC_API_KEY'])) {
            $apiKey = $_SERVER['ANTHROPIC_API_KEY'];
        }
        if (!$apiKey) {
            return $fail('Clé ANTHROPIC_API_KEY absente (à ajouter dans Railway).');
        }
        if (!is_file($pdfPath)) {
            return $fail('PDF introuvable : ' . $pdfPath);
        }
        $bytes = @file_get_contents($pdfPath);
        if ($bytes === false || $bytes === '') {
            return $fail('Lecture du PDF impossible.');
        }
        if (strlen($bytes) > 30 * 1024 * 1024) {
            return $fail('PDF trop volumineux (> 30 Mo). Réduis-le ou découpe-le.');
        }

        // Modèle choisi dans les réglages IA (défaut : Sonnet 5).
        $model = function_exists('iaSelectedModel') ? iaSelectedModel($db) : 'claude-sonnet-5';

        $system = "Tu reçois un document de formation interne (PDF). Ta tâche : en extraire fidèlement le contenu "
            . "et le réécrire dans un format UNIFORME, clair et lisible, en français.\n"
            . "Règles :\n"
            . "- N'ajoute AUCUNE information qui n'est pas dans le document.\n"
            . "- Structure en Markdown : titres avec ##, sous-titres avec ###, paragraphes courts, listes à puces (-).\n"
            . "- Garde toutes les informations utiles (procédures, chiffres, consignes), enlève le superflu (numéros de page, en-têtes répétés).\n"
            . "- Là où le document contient une image, photo, schéma ou capture d'écran importante, insère un marqueur [IMAGE] seul sur sa ligne, à l'endroit correspondant, dans l'ordre d'apparition. N'invente pas d'images : un marqueur = une image réellement présente.\n"
            . "- Aucun préambule ni conclusion de ta part (pas de « Voici… »). Donne DIRECTEMENT le contenu formaté.";

        $payload = [
            'model'      => $model,
            'max_tokens' => 8000,
            'system'     => $system,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]],
                    ['type' => 'text', 'text' => 'Uniformise le contenu de ce document.'],
                ],
            ]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 180,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return $fail('Connexion à l\'API échouée : ' . $cErr);
        }
        $data = json_decode($resp, true);
        if ($code !== 200 || !is_array($data)) {
            $apiMsg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : substr((string) $resp, 0, 300);
            return $fail('API HTTP ' . $code . ' : ' . $apiMsg);
        }
        if (($data['stop_reason'] ?? '') === 'refusal') {
            return $fail('L\'IA a refusé de traiter ce document (contenu sensible ?).');
        }

        // Concatène les blocs texte de la réponse.
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $text = trim($text);
        if ($text === '') {
            return $fail('Réponse vide de l\'IA.');
        }

        // Coût.
        $in  = (int) ($data['usage']['input_tokens'] ?? 0);
        $out = (int) ($data['usage']['output_tokens'] ?? 0);
        $pricing = aiModelPricing();
        $rate = $pricing[$model] ?? [3.0, 15.0];
        $costUsd = ($in / 1e6) * $rate[0] + ($out / 1e6) * $rate[1];
        $costEur = $costUsd * 0.92;

        return ['ok' => true, 'text' => $text, 'error' => '', 'model' => $model, 'in' => $in, 'out' => $out, 'cost_eur' => $costEur];
    }
}

if (!function_exists('aiMarkdownToHtml')) {
    /** Mini-rendu Markdown -> HTML (titres ##/###, listes -, paragraphes). */
    function aiMarkdownToHtml($md)
    {
        $out = [];
        $inList = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $md) as $line) {
            $t = rtrim($line);
            if ($t === '') { if ($inList) { $out[] = '</ul>'; $inList = false; } continue; }
            if (strpos($t, '### ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h4>' . htmlspecialchars(substr($t, 4)) . '</h4>'; }
            elseif (strpos($t, '## ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h3>' . htmlspecialchars(substr($t, 3)) . '</h3>'; }
            elseif (strpos($t, '# ') === 0) { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<h2>' . htmlspecialchars(substr($t, 2)) . '</h2>'; }
            elseif (strpos($t, '- ') === 0 || strpos($t, '* ') === 0) { if (!$inList) { $out[] = '<ul>'; $inList = true; } $out[] = '<li>' . htmlspecialchars(substr($t, 2)) . '</li>'; }
            else { if ($inList) { $out[] = '</ul>'; $inList = false; } $out[] = '<p>' . htmlspecialchars($t) . '</p>'; }
        }
        if ($inList) { $out[] = '</ul>'; }
        return implode("\n", $out);
    }
}

if (!function_exists('aiExtractPdfImages')) {
    /**
     * Extrait les images d'un PDF via `pdfimages` (poppler-utils) sur le volume.
     * Filtre les petites images (déco/logos). Retourne les clés relatives (servies par media.php).
     * @return string[] (vide si l'outil est absent ou pas d'images exploitables)
     */
    function aiExtractPdfImages($pdfAbsPath, $pdfRelPath)
    {
        if (!is_file($pdfAbsPath) || !function_exists('shell_exec')) {
            return [];
        }
        $bin = trim((string) @shell_exec('command -v pdfimages 2>/dev/null'));
        if ($bin === '') {
            return []; // poppler-utils pas encore dispo -> pas d'images (dégradation propre)
        }
        $storeBase = defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads');
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo((string) $pdfRelPath, PATHINFO_FILENAME));
        if ($name === '') {
            $name = substr(md5((string) $pdfRelPath), 0, 12);
        }
        $relDir = 'modules/pdf_images/' . $name;
        $absDir = rtrim($storeBase, '/') . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return [];
        }
        foreach ((array) glob($absDir . '/*') as $old) { @unlink($old); }

        @shell_exec($bin . ' -all ' . escapeshellarg($pdfAbsPath) . ' ' . escapeshellarg($absDir . '/img') . ' 2>/dev/null');

        $out = [];
        foreach ((array) glob($absDir . '/img-*') as $f) {
            $info = @getimagesize($f);
            if (!$info || $info[0] < 150 || $info[1] < 150) { @unlink($f); continue; } // ignore déco/logos
            $out[] = $relDir . '/' . basename($f);
        }
        sort($out); // ordre des pages
        return $out;
    }
}

if (!function_exists('aiGenerateQuiz')) {
    /**
     * Génère un quiz QCM à partir du contenu de formation (texte uniformisé).
     * @return array ['ok'=>bool, 'quiz'=>['questions'=>[...]]|null, 'error'=>string, 'cost_eur'=>float]
     */
    function aiGenerateQuiz($db, $contentText, $nb = 75)
    {
        $contentText = trim((string) $contentText);
        if ($contentText === '') {
            return ['ok' => false, 'quiz' => null, 'error' => 'Contenu vide', 'cost_eur' => 0.0];
        }
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey && isset($_SERVER['ANTHROPIC_API_KEY'])) { $apiKey = $_SERVER['ANTHROPIC_API_KEY']; }
        if (!$apiKey) {
            return ['ok' => false, 'quiz' => null, 'error' => 'Clé ANTHROPIC_API_KEY absente', 'cost_eur' => 0.0];
        }
        $model = function_exists('iaSelectedModel') ? iaSelectedModel($db) : 'claude-sonnet-5';

        $system = "Tu es formateur. À partir du CONTENU DE FORMATION fourni, crée un quiz d'évaluation de $nb questions à choix multiples (QCM), en français.\n"
            . "Règles STRICTES :\n"
            . "- Base-toi UNIQUEMENT sur le contenu fourni (aucune connaissance externe).\n"
            . "- Chaque question a 3 à 5 options claires et distinctes.\n"
            . "- \"type\" vaut \"single\" (une seule bonne réponse) ou \"multiple\" (plusieurs bonnes réponses) — sois exact.\n"
            . "- \"correct\" est la liste des indices 0-based des bonnes options.\n"
            . "- Si le contenu ne permet pas $nb questions de qualité, fais-en le maximum SANS inventer.\n"
            . "- Réponds UNIQUEMENT en JSON valide, sans aucun texte autour :\n"
            . '{"questions":[{"q":"...","type":"single","options":["...","..."],"correct":[0]}]}';

        $payload = [
            'model' => $model, 'max_tokens' => 16000, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => "CONTENU DE FORMATION :\n\n" . $contentText]],
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 240,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { return ['ok' => false, 'quiz' => null, 'error' => 'Connexion API : ' . $cErr, 'cost_eur' => 0.0]; }
        $data = json_decode($resp, true);
        if ($code !== 200 || !is_array($data)) {
            $msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return ['ok' => false, 'quiz' => null, 'error' => $msg, 'cost_eur' => 0.0];
        }
        if (($data['stop_reason'] ?? '') === 'refusal') { return ['ok' => false, 'quiz' => null, 'error' => 'IA a refusé', 'cost_eur' => 0.0]; }

        $text = '';
        foreach (($data['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') { $text .= $b['text']; } }
        $s = strpos($text, '{');
        $e = strrpos($text, '}');
        if ($s === false || $e === false || $e < $s) { return ['ok' => false, 'quiz' => null, 'error' => 'Réponse non-JSON', 'cost_eur' => 0.0]; }
        $q = json_decode(substr($text, $s, $e - $s + 1), true);
        if (!is_array($q) || empty($q['questions']) || !is_array($q['questions'])) {
            return ['ok' => false, 'quiz' => null, 'error' => 'JSON quiz invalide (tronqué ?)', 'cost_eur' => 0.0];
        }

        $clean = [];
        foreach ($q['questions'] as $it) {
            if (empty($it['q']) || empty($it['options']) || !is_array($it['options'])) { continue; }
            $opts = array_values(array_map('strval', $it['options']));
            if (count($opts) < 2) { continue; }
            $type = (($it['type'] ?? 'single') === 'multiple') ? 'multiple' : 'single';
            $correct = array_values(array_filter(array_map('intval', (array) ($it['correct'] ?? [])), function ($i) use ($opts) {
                return $i >= 0 && $i < count($opts);
            }));
            if (empty($correct)) { continue; }
            if ($type === 'single') { $correct = [$correct[0]]; }
            $clean[] = ['q' => (string) $it['q'], 'type' => $type, 'options' => $opts, 'correct' => $correct];
        }
        if (empty($clean)) { return ['ok' => false, 'quiz' => null, 'error' => 'Aucune question valide', 'cost_eur' => 0.0]; }

        $in = (int) ($data['usage']['input_tokens'] ?? 0);
        $out2 = (int) ($data['usage']['output_tokens'] ?? 0);
        $pricing = aiModelPricing();
        $rate = $pricing[$model] ?? [3.0, 15.0];
        $costEur = (($in / 1e6) * $rate[0] + ($out2 / 1e6) * $rate[1]) * 0.92;

        return ['ok' => true, 'quiz' => ['questions' => $clean], 'error' => '', 'cost_eur' => $costEur];
    }
}
