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

if (!function_exists('aiSanitizeBlocks')) {
    /** Valide/normalise les blocs de design produits par l'IA. */
    function aiSanitizeBlocks($blocks)
    {
        $ok = [];
        foreach ((array) $blocks as $b) {
            if (!is_array($b) || empty($b['type'])) { continue; }
            switch ($b['type']) {
                case 'hero':
                    $ok[] = ['type' => 'hero', 'title' => (string) ($b['title'] ?? ''), 'subtitle' => (string) ($b['subtitle'] ?? '')];
                    break;
                case 'section':
                    if (!empty($b['title'])) {
                        $blk = ['type' => 'section', 'title' => (string) $b['title']];
                        if (in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true)) { $blk['align'] = $b['align']; }
                        $ok[] = $blk;
                    }
                    break;
                case 'text':
                    if (trim((string) ($b['text'] ?? '')) !== '') {
                        $blk = ['type' => 'text', 'text' => (string) $b['text']];
                        if (trim((string) ($b['fix'] ?? '')) !== '') { $blk['fix'] = (string) $b['fix']; }
                        if (in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true)) { $blk['align'] = $b['align']; }
                        $ok[] = $blk;
                    }
                    break;
                case 'list':
                    $items = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($b['items'] ?? [])), 'strlen'));
                    if (!empty($items)) { $ok[] = ['type' => 'list', 'items' => $items]; }
                    break;
                case 'steps':
                    $items = [];
                    foreach ((array) ($b['items'] ?? []) as $it) {
                        if (is_array($it)) {
                            $ti = trim((string) ($it['title'] ?? ''));
                            $de = trim((string) ($it['desc'] ?? ''));
                            if ($ti !== '' || $de !== '') { $items[] = ['title' => $ti, 'desc' => $de]; }
                        } else {
                            $s = trim((string) $it);
                            if ($s !== '') { $items[] = ['title' => '', 'desc' => $s]; }
                        }
                    }
                    if (!empty($items)) { $ok[] = ['type' => 'steps', 'items' => $items]; }
                    break;
                case 'callout':
                    $style = in_array(($b['style'] ?? 'info'), ['info', 'tip', 'warning'], true) ? $b['style'] : 'info';
                    if (trim((string) ($b['text'] ?? '')) !== '' || trim((string) ($b['title'] ?? '')) !== '') {
                        $blk = ['type' => 'callout', 'style' => $style, 'title' => (string) ($b['title'] ?? ''), 'text' => (string) ($b['text'] ?? '')];
                        if (trim((string) ($b['fix'] ?? '')) !== '') { $blk['fix'] = (string) $b['fix']; }
                        if (in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true)) { $blk['align'] = $b['align']; }
                        $ok[] = $blk;
                    }
                    break;
                case 'keyfigures':
                    $items = [];
                    foreach ((array) ($b['items'] ?? []) as $it) {
                        if (is_array($it) && trim((string) ($it['value'] ?? '')) !== '') {
                            $items[] = ['value' => (string) $it['value'], 'label' => (string) ($it['label'] ?? '')];
                        }
                    }
                    if (!empty($items)) { $ok[] = ['type' => 'keyfigures', 'items' => $items]; }
                    break;
                case 'image':
                    $rot = ((int) ($b['rotate'] ?? 0) % 360 + 360) % 360;
                    if (!in_array($rot, [0, 90, 180, 270], true)) { $rot = 0; }
                    $size = ($b['size'] ?? 'm'); if (!in_array($size, ['s', 'm', 'l'], true)) { $size = 'm'; }
                    $img = ['type' => 'image', 'n' => (int) ($b['n'] ?? 0), 'caption' => (string) ($b['caption'] ?? ''), 'rotate' => $rot, 'size' => $size];
                    $src = trim((string) ($b['src'] ?? ''));
                    if ($src !== '') { $img['src'] = $src; } // image ajoutée depuis l'éditeur (clé volume directe)
                    $ok[] = $img;
                    break;
                case 'quote':
                    if (trim((string) ($b['text'] ?? '')) !== '') {
                        $blk = ['type' => 'quote', 'text' => (string) $b['text']];
                        if (in_array(($b['align'] ?? ''), ['center', 'right', 'left'], true)) { $blk['align'] = $b['align']; }
                        $ok[] = $blk;
                    }
                    break;
            }
        }
        return $ok;
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
    function aiUniformisePdf($db, $pdfPath, $pdfRelForImages = '')
    {
        $fail = function ($msg) {
            return ['ok' => false, 'text' => '', 'error' => $msg, 'model' => '', 'in' => 0, 'out' => 0, 'cost_eur' => 0.0, 'images' => []];
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

        // 1) Extraire d'abord les images du PDF pour les fournir NUMÉROTÉES à l'IA (elle les place au bon endroit).
        $relForImg = ($pdfRelForImages !== '') ? $pdfRelForImages : $pdfPath;
        $images = function_exists('aiExtractPdfImages') ? aiExtractPdfImages($pdfPath, $relForImg) : [];
        $images = array_slice($images, 0, 12); // borne coût / taille de requête

        $system = "Tu es un designer pédagogique. À partir d'un document de formation (PDF), tu produis une FICHE web moderne, claire et agréable, adaptée à Famiflora (jardinerie : ton chaleureux, univers nature).\n"
            . "LANGUE — RÈGLE ABSOLUE : détecte la langue du document (français ou néerlandais) et rédige TOUTE la fiche DANS CETTE MÊME LANGUE. Ne traduis JAMAIS. Un document néerlandais donne une fiche 100 % en néerlandais.\n"
            . "Tu réponds UNIQUEMENT en JSON valide au format {\"lang\":\"fr\"|\"nl\",\"blocks\":[ ... ]} où \"lang\" est la langue détectée du document. AUCUN texte hors du JSON.\n"
            . "Types de blocs disponibles (choisis les plus adaptés, dans l'ordre de lecture) :\n"
            . "- {\"type\":\"hero\",\"title\":\"Titre principal\",\"subtitle\":\"sous-titre court\"} : une seule fois, tout au début.\n"
            . "- {\"type\":\"section\",\"title\":\"Titre de section\"}\n"
            . "- {\"type\":\"text\",\"text\":\"phrases complètes et lisibles ; **gras** pour les termes clés\"}\n"
            . "- {\"type\":\"list\",\"items\":[\"point\",\"point\"]}\n"
            . "- {\"type\":\"steps\",\"items\":[{\"title\":\"titre court de l'étape\",\"desc\":\"détail de l'étape\"}]} : procédure ordonnée.\n"
            . "- {\"type\":\"callout\",\"style\":\"info|tip|warning\",\"title\":\"court\",\"text\":\"information importante à mettre en avant\"}\n"
            . "- {\"type\":\"keyfigures\",\"items\":[{\"value\":\"24/7\",\"label\":\"court\"}]} : chiffres/points clés.\n"
            . "- {\"type\":\"image\",\"n\":2,\"caption\":\"légende courte\",\"rotate\":0} : place UNE image pertinente (n = son numéro fourni). \"rotate\" = angle HORAIRE (0, 90, 180 ou 270) à appliquer pour remettre l'image DROITE si elle est de travers.\n"
            . "- {\"type\":\"quote\",\"text\":\"consigne ou phrase forte à mettre en avant\"}\n"
            . "RÈGLES DE QUALITÉ (essentielles) :\n"
            . "  - Commence TOUJOURS par un bloc hero (titre + sous-titre accrocheur).\n"
            . "  - Découpe en PLUSIEURS sections claires, chacune avec un vrai contenu.\n"
            . "  - Rends la fiche VIVANTE et riche : n'utilise PAS que des blocs text. Emploie réellement : callout (pour chaque info importante / consigne / point d'attention), steps (pour toute procédure ou étapes), keyfigures (pour les chiffres, horaires, repères), quote (pour une consigne forte). Vise un bon mélange de blocs.\n"
            . "  - Fidélité au fond : n'invente aucune donnée absente, ne garde que ce qui est dans le document ; supprime numéros de page et en-têtes répétés.\n"
            . "  - CORRECTION (forme) : corrige directement les fautes d'orthographe, de grammaire, de ponctuation et de casse.\n"
            . "  - DOUTE (fond) : si une INFORMATION te semble fausse ou douteuse (chiffre, affirmation, contradiction évidente) — pas une simple faute de forme — NE la modifie PAS dans \"text\". Garde le texte original et ajoute un champ \"fix\" au bloc avec ta correction proposée (uniquement sur les blocs \"text\" et \"callout\"). L'humain tranchera. Mets \"fix\" UNIQUEMENT en cas de vrai doute, jamais autrement, et n'invente aucune donnée.\n"
            . "IMAGES : les images fournies sont déjà filtrées (pas de logos répétés). Place CHAQUE image de contenu fournie avec un bloc image, à l'endroit du texte où elle a du sens. N'ignore une image que si elle est vraiment décorative. Jamais deux fois la même. Pour CHAQUE image, regarde son orientation : si elle est pivotée/de travers, renseigne \"rotate\" (angle horaire) pour la redresser.";

        $userContent = [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]],
        ];
        foreach ($images as $ix => $imgRel) {
            $imgAbs = rtrim((defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads')), '/') . '/' . $imgRel;
            $imgBytes = @file_get_contents($imgAbs);
            if ($imgBytes === false || $imgBytes === '') { continue; }
            $ext = strtolower(pathinfo($imgAbs, PATHINFO_EXTENSION));
            $mt = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg'));
            $userContent[] = ['type' => 'text', 'text' => 'Image ' . ($ix + 1) . ' :'];
            $userContent[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt, 'data' => base64_encode($imgBytes)]];
        }
        $userContent[] = ['type' => 'text', 'text' => count($images) > 0
            ? ('Produis la fiche en JSON de blocs. Place les images pertinentes parmi les ' . count($images) . ' fournies (bloc "image" avec "n").')
            : 'Produis la fiche en JSON de blocs.'];

        $payload = [
            'model'      => $model,
            'max_tokens' => 12000,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $userContent]],
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

        // La réponse doit être un JSON de blocs de design ; on le valide/normalise (sinon on garde le brut).
        $srcLang = 'fr'; // langue DÉTECTÉE du document : c'est la langue de travail du guide
        $js = strpos($text, '{');
        $je = strrpos($text, '}');
        if ($js !== false && $je !== false && $je > $js) {
            $parsed = json_decode(substr($text, $js, $je - $js + 1), true);
            if (is_array($parsed) && strtolower(trim((string) ($parsed['lang'] ?? ''))) === 'nl') { $srcLang = 'nl'; }
            if (is_array($parsed) && !empty($parsed['blocks']) && is_array($parsed['blocks']) && function_exists('aiSanitizeBlocks')) {
                $blocks = aiSanitizeBlocks($parsed['blocks']);
                if (!empty($blocks)) {
                    // Orientation : on pivote réellement les fichiers image selon l'IA, puis on retire le champ.
                    $imgBase = rtrim((defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads')), '/');
                    foreach ($blocks as &$bl) {
                        if (($bl['type'] ?? '') === 'image' && !empty($bl['rotate'])) {
                            $idx = (int) $bl['n'] - 1;
                            if ($idx >= 0 && isset($images[$idx]) && function_exists('aiRotateImageFile')) {
                                aiRotateImageFile($imgBase . '/' . $images[$idx], (int) $bl['rotate']);
                            }
                        }
                        unset($bl['rotate']);
                    }
                    unset($bl);
                    $text = json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Coût.
        $in  = (int) ($data['usage']['input_tokens'] ?? 0);
        $out = (int) ($data['usage']['output_tokens'] ?? 0);
        $pricing = aiModelPricing();
        $rate = $pricing[$model] ?? [3.0, 15.0];
        $costUsd = ($in / 1e6) * $rate[0] + ($out / 1e6) * $rate[1];
        $costEur = $costUsd * 0.92;

        return ['ok' => true, 'text' => $text, 'error' => '', 'model' => $model, 'in' => $in, 'out' => $out, 'cost_eur' => $costEur, 'images' => $images, 'lang' => $srcLang];
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

if (!function_exists('aiRotateImageFile')) {
    /** Pivote un fichier image de $deg degrés HORAIRE (0/90/180/270) via GD. Repli propre si GD absent. */
    function aiRotateImageFile($absPath, $deg)
    {
        $deg = ((int) $deg % 360 + 360) % 360;
        if ($deg === 0 || !function_exists('imagerotate') || !is_file($absPath)) { return false; }
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $src = null;
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) { $src = @imagecreatefromjpeg($absPath); }
        elseif ($ext === 'png' && function_exists('imagecreatefrompng')) { $src = @imagecreatefrompng($absPath); }
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($absPath); }
        elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) { $src = @imagecreatefromgif($absPath); }
        if (!$src) { return false; }
        // imagerotate tourne dans le sens ANTI-horaire -> pour un angle horaire, on passe (360 - deg).
        $rot = @imagerotate($src, 360 - $deg, 0);
        if (!$rot) { @imagedestroy($src); return false; }
        $ok = false;
        if ($ext === 'jpg' || $ext === 'jpeg') { $ok = @imagejpeg($rot, $absPath, 88); }
        elseif ($ext === 'png') { $ok = @imagepng($rot, $absPath); }
        elseif ($ext === 'webp' && function_exists('imagewebp')) { $ok = @imagewebp($rot, $absPath); }
        elseif ($ext === 'gif') { $ok = @imagegif($rot, $absPath); }
        @imagedestroy($src);
        @imagedestroy($rot);
        return $ok;
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

        // 1er passage : dimensions + hash de contenu (pour repérer les images répétées = logos/bandeaux).
        $files = (array) glob($absDir . '/img-*');
        $meta = [];
        $hashCount = [];
        foreach ($files as $f) {
            $info = @getimagesize($f);
            if (!$info) { @unlink($f); continue; }
            $w = (int) $info[0];
            $h = (int) $info[1];
            $ratio = $h > 0 ? $w / $h : 0;
            $ok = ($w >= 160 && $h >= 160 && $ratio <= 6 && $ratio >= (1 / 6)); // écarte petites + bandeaux/traits
            $hash = @md5_file($f);
            $meta[$f] = ['ok' => $ok, 'hash' => (string) $hash];
            if ($ok && $hash) { $hashCount[$hash] = ($hashCount[$hash] ?? 0) + 1; }
        }
        // 2e passage : on garde les images valides ET UNIQUES (répétées sur plusieurs pages = déco -> écartées).
        $out = [];
        foreach ($files as $f) {
            $m = $meta[$f] ?? null;
            if (!$m || !$m['ok'] || (($hashCount[$m['hash']] ?? 0) !== 1)) { @unlink($f); continue; }
            $out[] = $relDir . '/' . basename($f);
        }
        sort($out); // ordre des pages
        return $out;
    }
}

if (!function_exists('aiClaudeStreamText')) {
    /**
     * Appel Claude en STREAMING (SSE) — renvoie le texte complet.
     *
     * POURQUOI : une requête NON streamée n'envoie AUCUN octet tant que la réponse
     * n'est pas entièrement produite. Avec un gros max_tokens, ça dépasse le délai de
     * cURL, qui coupe « after 240000 ms with 0 bytes received » — la réponse est perdue
     * alors qu'on l'a payée. En streaming, les octets arrivent au fur et à mesure :
     * plus de timeout, et on peut suivre l'avancement.
     *
     * @return array ['ok'=>bool,'text'=>string,'error'=>string,'in'=>int,'out'=>int]
     */
    function aiClaudeStreamText($apiKey, array $payload)
    {
        $payload['stream'] = true;
        $text = '';
        $buf = '';   // ligne SSE incomplète en attente
        $raw = '';   // corps brut (si HTTP != 200, ce n'est pas du SSE mais un JSON d'erreur)
        $inTok = 0;
        $outTok = 0;
        $apiErr = '';

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,           // pas de plafond total : le flux peut durer
            CURLOPT_LOW_SPEED_LIMIT => 1,   // mais on abandonne si…
            CURLOPT_LOW_SPEED_TIME => 120,  // …plus rien n'arrive pendant 120 s
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$text, &$buf, &$raw, &$inTok, &$outTok, &$apiErr) {
                $raw .= $chunk;
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $pos));
                    $buf = substr($buf, $pos + 1);
                    if ($line === '' || strpos($line, 'data:') !== 0) { continue; }
                    $json = trim(substr($line, 5));
                    if ($json === '' || $json === '[DONE]') { continue; }
                    $ev = json_decode($json, true);
                    if (!is_array($ev)) { continue; }
                    $t = (string) ($ev['type'] ?? '');
                    if ($t === 'content_block_delta') {
                        $d = $ev['delta'] ?? [];
                        if (($d['type'] ?? '') === 'text_delta') { $text .= (string) ($d['text'] ?? ''); }
                    } elseif ($t === 'message_start') {
                        $inTok += (int) ($ev['message']['usage']['input_tokens'] ?? 0);
                    } elseif ($t === 'message_delta') {
                        $outTok += (int) ($ev['usage']['output_tokens'] ?? 0);
                    } elseif ($t === 'error') {
                        $apiErr = (string) ($ev['error']['message'] ?? 'erreur API');
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            $d = json_decode($raw, true);
            $msg = (is_array($d) && isset($d['error']['message'])) ? $d['error']['message'] : ('HTTP ' . $code);
            return ['ok' => false, 'text' => '', 'error' => $msg, 'in' => $inTok, 'out' => $outTok];
        }
        if ($cErr !== '') { return ['ok' => false, 'text' => $text, 'error' => 'Connexion API : ' . $cErr, 'in' => $inTok, 'out' => $outTok]; }
        if ($apiErr !== '') { return ['ok' => false, 'text' => $text, 'error' => $apiErr, 'in' => $inTok, 'out' => $outTok]; }
        if (trim($text) === '') { return ['ok' => false, 'text' => '', 'error' => 'Réponse vide de l\'IA', 'in' => $inTok, 'out' => $outTok]; }
        return ['ok' => true, 'text' => $text, 'error' => '', 'in' => $inTok, 'out' => $outTok];
    }
}

if (!function_exists('aiGenerateQuiz')) {
    /**
     * Génère un quiz QCM à partir du contenu de formation (texte uniformisé).
     * @return array ['ok'=>bool, 'quiz'=>['questions'=>[...]]|null, 'error'=>string, 'cost_eur'=>float]
     */
    function aiGenerateQuiz($db, $contentText, $nbMultiple = null, $nbSingle = null)
    {
        $contentText = trim((string) $contentText);
        if ($contentText === '') {
            return ['ok' => false, 'quiz' => null, 'error' => 'Contenu vide', 'cost_eur' => 0.0];
        }
        // Par défaut : ce qui est réglé dans Paramètres → Préférences (25 questions, 75 % de multiples).
        if ($nbMultiple === null || $nbSingle === null) {
            require_once __DIR__ . '/quiz_config.php';
            list($cfgMul, $cfgSin) = quizCfgGenSplit($db);
            if ($nbMultiple === null) { $nbMultiple = $cfgMul; }
            if ($nbSingle === null)   { $nbSingle = $cfgSin; }
        }
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey && isset($_SERVER['ANTHROPIC_API_KEY'])) { $apiKey = $_SERVER['ANTHROPIC_API_KEY']; }
        if (!$apiKey) {
            return ['ok' => false, 'quiz' => null, 'error' => 'Clé ANTHROPIC_API_KEY absente', 'cost_eur' => 0.0];
        }
        $model = function_exists('iaSelectedModel') ? iaSelectedModel($db) : 'claude-sonnet-5';

        $nbTotal = (int) $nbMultiple + (int) $nbSingle;
        if ($nbTotal <= 0) { return ['ok' => false, 'quiz' => null, 'error' => 'Aucune question demandée', 'cost_eur' => 0.0]; }
        $ratioMul = $nbMultiple / $nbTotal;

        // GÉNÉRATION PAR LOTS + STREAMING.
        // Demander 75 questions d'un coup (max_tokens 32000, sans streaming) faisait
        // dépasser le délai : l'API ne renvoyait rien avant la fin et cURL coupait.
        // On génère donc par petits lots STREAMÉS : chaque appel est court, rapide et sûr,
        // et si un lot échoue on garde quand même les questions déjà obtenues.
        $BATCH = 15;
        $clean = [];
        $seen = [];
        $inTok = 0;
        $outTok = 0;
        $lastErr = '';
        $guard = 0;

        while (count($clean) < $nbTotal && $guard++ < 12) {
            $remaining = $nbTotal - count($clean);
            $n = min($BATCH, $remaining);
            $nMul = (int) round($n * $ratioMul);
            if ($nMul > $n) { $nMul = $n; }
            $nSin = $n - $nMul;

            $system = "Tu es formateur. À partir du CONTENU DE FORMATION fourni, crée EXACTEMENT $n questions de quiz, en français.\n"
                . "Règles STRICTES :\n"
                . "- Base-toi UNIQUEMENT sur le contenu fourni (aucune connaissance externe).\n"
                . "- Répartition : $nMul questions à réponses MULTIPLES (type \"multiple\") et $nSin à réponse UNIQUE (type \"single\").\n"
                . "- Une \"multiple\" a PLUSIEURS bonnes réponses (au moins 2) ; une \"single\" en a exactement UNE.\n"
                . "- Chaque question a 3 à 5 options claires et distinctes.\n"
                . "- \"correct\" = indices 0-based des bonnes options.\n"
                . "- Si le contenu ne permet pas $n questions de qualité, fais-en MOINS plutôt que d'inventer.\n"
                . "- Réponds UNIQUEMENT en JSON valide, sans aucun texte autour :\n"
                . '{"questions":[{"q":"...","type":"single","options":["...","..."],"correct":[0]}]}';

            $user = "CONTENU DE FORMATION :\n\n" . $contentText;
            if (!empty($clean)) {
                $deja = [];
                foreach (array_slice($clean, -40) as $qq) { $deja[] = '- ' . $qq['q']; }
                $user .= "\n\n---\nQUESTIONS DÉJÀ POSÉES — n'en repose AUCUNE, trouve d'autres angles :\n" . implode("\n", $deja);
            }

            $r = aiClaudeStreamText($apiKey, [
                'model' => $model,
                'max_tokens' => 8000, // largement suffisant pour 15 questions
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);
            $inTok += (int) $r['in'];
            $outTok += (int) $r['out'];

            if (empty($r['ok'])) {
                $lastErr = (string) $r['error'];
                break; // on garde ce qui a déjà été généré
            }

            $text = (string) $r['text'];
            $s = strpos($text, '{');
            $e = strrpos($text, '}');
            if ($s === false || $e === false || $e < $s) { $lastErr = 'Réponse non-JSON'; break; }
            $q = json_decode(substr($text, $s, $e - $s + 1), true);
            if (!is_array($q) || empty($q['questions']) || !is_array($q['questions'])) { $lastErr = 'JSON quiz invalide'; break; }

            $added = 0;
            foreach ($q['questions'] as $it) {
                if (empty($it['q']) || empty($it['options']) || !is_array($it['options'])) { continue; }
                $opts = array_values(array_map('strval', $it['options']));
                if (count($opts) < 2) { continue; }
                $key = mb_strtolower(trim((string) $it['q']));
                if ($key === '' || isset($seen[$key])) { continue; } // pas de doublon entre lots
                $type = (($it['type'] ?? 'single') === 'multiple') ? 'multiple' : 'single';
                $correct = array_values(array_filter(array_map('intval', (array) ($it['correct'] ?? [])), function ($i) use ($opts) {
                    return $i >= 0 && $i < count($opts);
                }));
                if (empty($correct)) { continue; }
                if ($type === 'single') { $correct = [$correct[0]]; }
                $seen[$key] = true;
                $clean[] = ['q' => (string) $it['q'], 'type' => $type, 'options' => $opts, 'correct' => $correct];
                $added++;
                if (count($clean) >= $nbTotal) { break; }
            }
            // Le contenu est épuisé : l'IA ne trouve plus de question neuve → on s'arrête là.
            if ($added === 0) { break; }
        }

        if (empty($clean)) {
            return ['ok' => false, 'quiz' => null, 'error' => ($lastErr !== '' ? $lastErr : 'Aucune question valide'), 'cost_eur' => 0.0];
        }

        $pricing = aiModelPricing();
        $rate = $pricing[$model] ?? [3.0, 15.0];
        $costEur = (($inTok / 1e6) * $rate[0] + ($outTok / 1e6) * $rate[1]) * 0.92;

        return ['ok' => true, 'quiz' => ['questions' => $clean], 'error' => '', 'cost_eur' => $costEur];
    }
}
