<?php
// ============================================================
// i18n_nl.php — Autonomie bilingue FR → NL (guide, quiz, titres).
//
// POURQUOI CE FICHIER (décisions prises en autonomie, à débattre au retour) :
//  Le site était bilingue à moitié : les modules avaient bien `nom_nl` /
//  `description_nl`, MAIS le GUIDE (contenu_ia) et le QUIZ (quiz_json) n'avaient
//  aucune version NL → un utilisateur néerlandophone voyait le titre en NL et
//  tout le contenu en français. C'est le trou qu'on bouche ici.
//
// CHOIX 1 — Qui traduit quoi :
//  - TOUT est traduit par Claude (titre, description, blocs du guide, quiz).
//    100 % automatique, aucune saisie manuelle, aucun service de traduction tiers.
//    (MyMemory abandonné : bridé, capricieux, qualité moyenne. Un titre coûte
//     une fraction de centime en tokens Claude — négligeable.)
//
// CHOIX 2 — Comment (le point important) :
//  On n'envoie PAS le JSON brut à l'IA : elle pourrait renommer une clé, réordonner
//  des options, casser les indices des bonnes réponses ou perdre une image.
//  Méthode retenue, sûre : on EXTRAIT les chaînes traduisibles dans un tableau plat,
//  on fait traduire CE tableau (même longueur, même ordre), puis on RÉINJECTE
//  chaque traduction à sa place exacte. La structure est préservée à 100 %.
//
// CHOIX 3 — Quand :
//  Synchronisation à l'enregistrement du contenu (comme l'uniformisation, déjà
//  synchrone). Un hash du FR évite de retraduire ce qui n'a pas changé.
// ============================================================

if (!function_exists('nlEnsureColumns')) {
    /** Colonnes NL (idempotent : SHOW COLUMNS + ALTER, comme le reste du projet). */
    function nlEnsureColumns(PDO $db)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $cols = [
            'contenu_ia_nl' => "ALTER TABLE modules ADD COLUMN contenu_ia_nl MEDIUMTEXT NULL",
            'quiz_json_nl'  => "ALTER TABLE modules ADD COLUMN quiz_json_nl MEDIUMTEXT NULL",
            // Empreinte du FR au moment de la dernière traduction : évite de retraduire
            // (et de repayer) un contenu qui n'a pas bougé.
            'nl_hash'       => "ALTER TABLE modules ADD COLUMN nl_hash VARCHAR(64) NULL",
        ];
        foreach ($cols as $col => $ddl) {
            try {
                $chk = $db->query("SHOW COLUMNS FROM modules LIKE " . $db->quote($col));
                if ($chk && !$chk->fetch()) {
                    $db->exec($ddl);
                }
            } catch (Exception $e) {
                // migration non bloquante
            }
        }
    }
}

if (!function_exists('nlWalkBlocks')) {
    /**
     * Parcourt les blocs du guide dans un ORDRE DÉTERMINISTE et applique $fn à chaque
     * chaîne traduisible (par référence). Le même parcours sert à extraire PUIS à
     * réinjecter → les deux passes tombent forcément sur les mêmes emplacements.
     *
     * Ne sont PAS traduits (volontairement) :
     *  - type, align, size, rotate, n, src, style : structure/mise en forme ;
     *  - keyfigures.value : c'est un chiffre ;
     *  - fix : note interne « doute IA » destinée à l'admin (reste en FR).
     */
    function nlWalkBlocks(array &$blocks, callable $fn)
    {
        foreach ($blocks as &$b) {
            if (!is_array($b)) {
                continue;
            }
            switch ((string) ($b['type'] ?? '')) {
                case 'hero':
                    foreach (['title', 'subtitle'] as $k) {
                        if (array_key_exists($k, $b)) { $fn($b[$k]); }
                    }
                    break;
                case 'section':
                    if (array_key_exists('title', $b)) { $fn($b['title']); }
                    break;
                case 'text':
                case 'quote':
                    if (array_key_exists('text', $b)) { $fn($b['text']); }
                    break;
                case 'callout':
                    foreach (['title', 'text'] as $k) {
                        if (array_key_exists($k, $b)) { $fn($b[$k]); }
                    }
                    break;
                case 'list':
                    if (isset($b['items']) && is_array($b['items'])) {
                        foreach ($b['items'] as &$it) { $fn($it); }
                        unset($it);
                    }
                    break;
                case 'steps':
                    if (isset($b['items']) && is_array($b['items'])) {
                        foreach ($b['items'] as &$it) {
                            if (!is_array($it)) { continue; }
                            foreach (['title', 'desc'] as $k) {
                                if (array_key_exists($k, $it)) { $fn($it[$k]); }
                            }
                        }
                        unset($it);
                    }
                    break;
                case 'keyfigures':
                    if (isset($b['items']) && is_array($b['items'])) {
                        foreach ($b['items'] as &$it) {
                            if (is_array($it) && array_key_exists('label', $it)) { $fn($it['label']); }
                        }
                        unset($it);
                    }
                    break;
                case 'image':
                    if (array_key_exists('caption', $b)) { $fn($b['caption']); }
                    break;
            }
        }
        unset($b);
    }
}

if (!function_exists('nlWalkQuiz')) {
    /**
     * Parcourt le quiz : énoncé + options. On ne touche NI au type, NI aux indices
     * des bonnes réponses (`correct`) → une traduction ne peut pas fausser le quiz.
     */
    function nlWalkQuiz(array &$quiz, callable $fn)
    {
        if (!isset($quiz['questions']) || !is_array($quiz['questions'])) {
            return;
        }
        foreach ($quiz['questions'] as &$q) {
            if (!is_array($q)) {
                continue;
            }
            if (array_key_exists('q', $q)) { $fn($q['q']); }
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as &$opt) { $fn($opt); }
                unset($opt);
            }
        }
        unset($q);
    }
}

if (!function_exists('aiTranslateStringsToNl')) {
    /**
     * Traduit un tableau plat de chaînes FR → NL via Claude, en garantissant
     * MÊME LONGUEUR et MÊME ORDRE. Découpé en lots pour rester dans les limites.
     * @return array ['ok'=>bool, 'items'=>string[], 'error'=>string]
     */
    function aiTranslateStringsToNl($db, array $strings)
    {
        if (empty($strings)) {
            return ['ok' => true, 'items' => [], 'error' => ''];
        }
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey && isset($_SERVER['ANTHROPIC_API_KEY'])) {
            $apiKey = $_SERVER['ANTHROPIC_API_KEY'];
        }
        if (!$apiKey) {
            return ['ok' => false, 'items' => [], 'error' => 'Clé ANTHROPIC_API_KEY absente'];
        }
        $model = function_exists('iaSelectedModel') ? iaSelectedModel($db) : 'claude-sonnet-5';

        $system = "Tu es traducteur professionnel français → néerlandais (néerlandais de Belgique), "
            . "domaine : jardinerie et formation interne d'entreprise.\n"
            . "Tu reçois un TABLEAU JSON de chaînes en français.\n"
            . "Règles STRICTES :\n"
            . "- Renvoie UNIQUEMENT un tableau JSON de MÊME LONGUEUR et dans le MÊME ORDRE.\n"
            . "- Traduis chaque élément, un pour un. N'en fusionne, n'en supprime, n'en ajoute AUCUN.\n"
            . "- Si un élément est vide, renvoie une chaîne vide au même index.\n"
            . "- Conserve la mise en forme markdown **gras** et la ponctuation.\n"
            . "- Ne traduis PAS les noms propres (Famiflora, Famiformation) ni les nombres/unités.\n"
            . "- Aucun texte autour du JSON.";

        $out = [];
        // Lots de 100 : garde des réponses courtes et fiables, et limite la casse en cas d'échec.
        foreach (array_chunk($strings, 100) as $chunk) {
            $payload = [
                'model' => $model,
                'max_tokens' => 16000,
                'system' => $system,
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ];
            // STREAMING obligatoire : sans lui, l'API n'envoie rien avant d'avoir fini et
            // cURL coupe sur timeout (0 octet reçu) — c'est ce qui rendait la relecture
            // interminable puis échouait. Voir aiClaudeStreamText().
            require_once __DIR__ . '/ai_uniformise.php';
            $r = aiClaudeStreamText($apiKey, $payload);
            if (empty($r['ok'])) {
                return ['ok' => false, 'items' => [], 'error' => (string) $r['error']];
            }
            $text = (string) $r['text'];
            $s = strpos($text, '[');
            $e = strrpos($text, ']');
            if ($s === false || $e === false || $e < $s) {
                return ['ok' => false, 'items' => [], 'error' => 'Réponse non-JSON'];
            }
            $arr = json_decode(substr($text, $s, $e - $s + 1), true);
            if (!is_array($arr) || count($arr) !== count($chunk)) {
                // Sécurité : si le compte ne tombe pas juste, on refuse plutôt que de
                // décaler les traductions (ce qui mélangerait les textes des blocs).
                return ['ok' => false, 'items' => [], 'error' => 'Traduction incomplète (nombre d\'éléments différent)'];
            }
            foreach ($arr as $v) {
                $out[] = (string) $v;
            }
        }
        return ['ok' => true, 'items' => $out, 'error' => ''];
    }
}

if (!function_exists('nlTranslateBlocksJson')) {
    /** Guide (JSON de blocs) FR → NL, structure préservée. @return array ['ok','json','error'] */
    function nlTranslateBlocksJson($db, $blocksJson)
    {
        $decoded = json_decode((string) $blocksJson, true);
        if (!is_array($decoded) || empty($decoded)) {
            return ['ok' => false, 'json' => '', 'error' => 'Contenu vide ou invalide'];
        }
        // Le guide est stocké enveloppé : {"blocks":[...]}. On travaille sur le tableau
        // de blocs et on ré-enveloppe à la sortie (sinon on n'extrait aucun texte).
        $wrapped = isset($decoded['blocks']) && is_array($decoded['blocks']);
        $blocks = $wrapped ? $decoded['blocks'] : $decoded;

        // 1) Extraction (ordre déterministe)
        $src = [];
        $copy = $blocks;
        nlWalkBlocks($copy, function (&$s) use (&$src) {
            $src[] = (string) $s;
        });
        if (empty($src)) {
            return ['ok' => false, 'json' => '', 'error' => 'Aucun texte à traduire'];
        }

        // 2) Traduction en lot
        $tr = aiTranslateStringsToNl($db, $src);
        if (!$tr['ok']) {
            return ['ok' => false, 'json' => '', 'error' => $tr['error']];
        }

        // 3) Réinjection à l'identique (même parcours → mêmes emplacements)
        $i = 0;
        $items = $tr['items'];
        nlWalkBlocks($blocks, function (&$s) use (&$i, $items) {
            if (array_key_exists($i, $items)) {
                $s = $items[$i];
            }
            $i++;
        });

        return [
            'ok' => true,
            'json' => json_encode($wrapped ? ['blocks' => $blocks] : $blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => '',
        ];
    }
}

if (!function_exists('aiProofreadStringsFr')) {
    /**
     * PASSAGE 2 — corrige l'ORTHOGRAPHE d'un tableau de chaînes FR (forme uniquement).
     * Ne reformule rien, ne change aucun mot correct, ne touche ni au sens ni au style.
     * Même mécanisme sûr que la traduction (même longueur, même ordre, refus si écart).
     * @return array ['ok','items','error']
     */
    function aiProofreadStringsFr($db, array $strings)
    {
        if (empty($strings)) {
            return ['ok' => true, 'items' => [], 'error' => ''];
        }
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey && isset($_SERVER['ANTHROPIC_API_KEY'])) {
            $apiKey = $_SERVER['ANTHROPIC_API_KEY'];
        }
        if (!$apiKey) {
            return ['ok' => false, 'items' => [], 'error' => 'Clé ANTHROPIC_API_KEY absente'];
        }
        $model = function_exists('iaSelectedModel') ? iaSelectedModel($db) : 'claude-sonnet-5';

        $system = "Tu es correcteur orthographique professionnel en français.\n"
            . "Tu reçois un TABLEAU JSON de chaînes en français.\n"
            . "Règles STRICTES :\n"
            . "- Renvoie UNIQUEMENT un tableau JSON de MÊME LONGUEUR et dans le MÊME ORDRE.\n"
            . "- Corrige SEULEMENT l'orthographe, les accents, la conjugaison, les accords et la ponctuation évidente.\n"
            . "- Ne REFORMULE rien. Ne change AUCUN mot déjà correct. Ne modifie NI le sens NI le style.\n"
            . "- Si une chaîne n'a aucune faute, renvoie-la STRICTEMENT à l'identique.\n"
            . "- Conserve la mise en forme markdown **gras** et les retours à la ligne.\n"
            . "- Ne touche pas aux noms propres (Famiflora, Famiformation) ni aux nombres/unités.\n"
            . "- Aucun texte autour du JSON.";

        $out = [];
        foreach (array_chunk($strings, 100) as $chunk) {
            $payload = [
                'model' => $model,
                'max_tokens' => 16000,
                'system' => $system,
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ];
            // STREAMING obligatoire : sans lui, l'API n'envoie rien avant d'avoir fini et
            // cURL coupe sur timeout (0 octet reçu) — c'est ce qui rendait la relecture
            // interminable puis échouait. Voir aiClaudeStreamText().
            require_once __DIR__ . '/ai_uniformise.php';
            $r = aiClaudeStreamText($apiKey, $payload);
            if (empty($r['ok'])) {
                return ['ok' => false, 'items' => [], 'error' => (string) $r['error']];
            }
            $text = (string) $r['text'];
            $s = strpos($text, '[');
            $e = strrpos($text, ']');
            if ($s === false || $e === false || $e < $s) {
                return ['ok' => false, 'items' => [], 'error' => 'Réponse non-JSON'];
            }
            $arr = json_decode(substr($text, $s, $e - $s + 1), true);
            if (!is_array($arr) || count($arr) !== count($chunk)) {
                return ['ok' => false, 'items' => [], 'error' => 'Correction incomplète (nombre d\'éléments différent)'];
            }
            foreach ($arr as $v) {
                $out[] = (string) $v;
            }
        }
        return ['ok' => true, 'items' => $out, 'error' => ''];
    }
}

if (!function_exists('nlProofreadBlocksJson')) {
    /** Guide (JSON de blocs) : corrige l'orthographe FR, structure préservée. @return ['ok','json','error'] */
    function nlProofreadBlocksJson($db, $blocksJson)
    {
        $decoded = json_decode((string) $blocksJson, true);
        if (!is_array($decoded) || empty($decoded)) {
            return ['ok' => false, 'json' => '', 'error' => 'Contenu vide ou invalide'];
        }
        $wrapped = isset($decoded['blocks']) && is_array($decoded['blocks']);
        $blocks = $wrapped ? $decoded['blocks'] : $decoded;
        $src = [];
        $copy = $blocks;
        nlWalkBlocks($copy, function (&$s) use (&$src) { $src[] = (string) $s; });
        if (empty($src)) {
            return ['ok' => false, 'json' => '', 'error' => 'Aucun texte à corriger'];
        }
        $pr = aiProofreadStringsFr($db, $src);
        if (!$pr['ok']) {
            return ['ok' => false, 'json' => '', 'error' => $pr['error']];
        }
        $i = 0;
        $items = $pr['items'];
        nlWalkBlocks($blocks, function (&$s) use (&$i, $items) {
            if (array_key_exists($i, $items)) { $s = $items[$i]; }
            $i++;
        });
        return [
            'ok' => true,
            'json' => json_encode($wrapped ? ['blocks' => $blocks] : $blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => '',
        ];
    }
}

if (!function_exists('nlApplyIncremental')) {
    /**
     * PASSAGE 2 INCRÉMENTAL — ne re-corrige (orthographe) et ne re-traduit (NL) QUE les
     * chaînes RÉELLEMENT modifiées depuis la dernière fois. Économise les appels IA.
     * @return array|null ['fr'=>json corrigé, 'nl'=>json NL à jour] ou NULL si impossible
     *                    (structure changée / NL absent) → l'appelant fait le mode plein.
     */
    function nlApplyIncremental($db, $newFrJson, $oldFrJson, $oldNlJson)
    {
        $newDec = json_decode((string) $newFrJson, true);
        $oldDec = json_decode((string) $oldFrJson, true);
        $nlDec = json_decode((string) $oldNlJson, true);
        if (!is_array($newDec) || !is_array($oldDec) || !is_array($nlDec)) { return null; }
        $unwrap = function ($d) { return (isset($d['blocks']) && is_array($d['blocks'])) ? $d['blocks'] : $d; };
        $newB = $unwrap($newDec);
        $oldB = $unwrap($oldDec);
        $nlB = $unwrap($nlDec);
        $frWrapped = isset($newDec['blocks']);
        $nlWrapped = isset($nlDec['blocks']);

        $newS = []; $oldS = []; $nlS = [];
        $c1 = $newB; nlWalkBlocks($c1, function (&$s) use (&$newS) { $newS[] = (string) $s; });
        $c2 = $oldB; nlWalkBlocks($c2, function (&$s) use (&$oldS) { $oldS[] = (string) $s; });
        $c3 = $nlB;  nlWalkBlocks($c3, function (&$s) use (&$nlS) { $nlS[] = (string) $s; });

        // Structures identiques indispensables (sinon on ne peut pas aligner les phrases).
        $n = count($newS);
        if ($n === 0 || count($oldS) !== $n || count($nlS) !== $n) { return null; }

        $changed = [];
        for ($i = 0; $i < $n; $i++) { if ($newS[$i] !== $oldS[$i]) { $changed[] = $i; } }
        if (empty($changed)) { return ['fr' => $newFrJson, 'nl' => $oldNlJson]; }

        // 1) Orthographe FR sur les SEULES phrases modifiées.
        $chFr = [];
        foreach ($changed as $i) { $chFr[] = $newS[$i]; }
        $pr = aiProofreadStringsFr($db, $chFr);
        $corr = (!empty($pr['ok']) && count($pr['items']) === count($chFr)) ? $pr['items'] : $chFr;
        foreach ($changed as $k => $i) { $newS[$i] = (string) $corr[$k]; }

        // 2) Traduction NL des SEULES phrases corrigées.
        $chCorr = [];
        foreach ($changed as $i) { $chCorr[] = $newS[$i]; }
        $tr = aiTranslateStringsToNl($db, $chCorr);
        if (empty($tr['ok']) || count($tr['items']) !== count($changed)) { return null; }
        foreach ($changed as $k => $i) { $nlS[$i] = (string) $tr['items'][$k]; }

        // Réinjection FR + NL à leur place exacte.
        $a = 0; nlWalkBlocks($newB, function (&$s) use (&$a, $newS) { if (array_key_exists($a, $newS)) { $s = $newS[$a]; } $a++; });
        $b = 0; nlWalkBlocks($nlB, function (&$s) use (&$b, $nlS) { if (array_key_exists($b, $nlS)) { $s = $nlS[$b]; } $b++; });

        return [
            'fr' => json_encode($frWrapped ? ['blocks' => $newB] : $newB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'nl' => json_encode($nlWrapped ? ['blocks' => $nlB] : $nlB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}

if (!function_exists('nlTranslateQuizJson')) {
    /** Quiz FR → NL : énoncés + options. Type et bonnes réponses INTACTS. */
    function nlTranslateQuizJson($db, $quizJson)
    {
        $quiz = json_decode((string) $quizJson, true);
        if (!is_array($quiz) || empty($quiz['questions'])) {
            return ['ok' => false, 'json' => '', 'error' => 'Quiz vide ou invalide'];
        }

        $src = [];
        $copy = $quiz;
        nlWalkQuiz($copy, function (&$s) use (&$src) {
            $src[] = (string) $s;
        });
        if (empty($src)) {
            return ['ok' => false, 'json' => '', 'error' => 'Aucun texte à traduire'];
        }

        $tr = aiTranslateStringsToNl($db, $src);
        if (!$tr['ok']) {
            return ['ok' => false, 'json' => '', 'error' => $tr['error']];
        }

        $i = 0;
        $items = $tr['items'];
        nlWalkQuiz($quiz, function (&$s) use (&$i, $items) {
            if (array_key_exists($i, $items)) {
                $s = $items[$i];
            }
            $i++;
        });

        return [
            'ok' => true,
            'json' => json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => '',
        ];
    }
}

if (!function_exists('nlProofreadQuizJson')) {
    /** PASSAGE 2 — corrige l'orthographe FR du quiz (énoncés + options). Type/correct INTACTS. */
    function nlProofreadQuizJson($db, $quizJson)
    {
        $quiz = json_decode((string) $quizJson, true);
        if (!is_array($quiz) || empty($quiz['questions'])) {
            return ['ok' => false, 'json' => '', 'error' => 'Quiz vide ou invalide'];
        }
        $src = [];
        $copy = $quiz;
        nlWalkQuiz($copy, function (&$s) use (&$src) { $src[] = (string) $s; });
        if (empty($src)) {
            return ['ok' => false, 'json' => '', 'error' => 'Aucun texte à corriger'];
        }
        $pr = aiProofreadStringsFr($db, $src);
        if (!$pr['ok']) {
            return ['ok' => false, 'json' => '', 'error' => $pr['error']];
        }
        $i = 0;
        $items = $pr['items'];
        nlWalkQuiz($quiz, function (&$s) use (&$i, $items) {
            if (array_key_exists($i, $items)) { $s = $items[$i]; }
            $i++;
        });
        return [
            'ok' => true,
            'json' => json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => '',
        ];
    }
}

if (!function_exists('nlSyncModule')) {
    /**
     * Met à jour TOUTE la version NL d'un module : titre, description, guide, quiz.
     * Appelé à chaque enregistrement de contenu → le NL suit automatiquement le FR.
     *
     * @param bool $force  true = retraduit même si le FR n'a pas changé.
     * @return array ['ok'=>bool, 'done'=>string[], 'error'=>string]
     */
    function nlSyncModule(PDO $db, $moduleId, $force = false)
    {
        $moduleId = (int) $moduleId;
        if ($moduleId <= 0) {
            return ['ok' => false, 'done' => [], 'error' => 'Module inconnu'];
        }
        nlEnsureColumns($db);

        try {
            $st = $db->prepare("SELECT * FROM modules WHERE id = ? LIMIT 1");
            $st->execute([$moduleId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['ok' => false, 'done' => [], 'error' => 'Lecture module impossible'];
        }
        if (!$m) {
            return ['ok' => false, 'done' => [], 'error' => 'Module introuvable'];
        }

        $done = [];
        $errors = [];

        // Empreinte du FR : titre + description + guide + quiz.
        // Toute modification côté français (y compris le titre) => resynchro NL automatique.
        $nom = trim((string) ($m['nom'] ?? ''));
        $desc = trim((string) ($m['description'] ?? ''));
        $frContent = (string) ($m['contenu_ia'] ?? '');
        $frQuiz = (string) ($m['quiz_json'] ?? '');
        $hash = hash('sha256', $nom . '|' . $desc . '|' . $frContent . '|' . $frQuiz);
        $needSync = $force || ($hash !== (string) ($m['nl_hash'] ?? ''));

        $nomNl = trim((string) ($m['nom_nl'] ?? ''));
        $descNl = trim((string) ($m['description_nl'] ?? ''));
        $contenuNl = (string) ($m['contenu_ia_nl'] ?? '');
        $quizNl = (string) ($m['quiz_json_nl'] ?? '');

        // --- 1) Titre + description : Claude (automatique, fiable, néerlandais de Belgique).
        // On (re)traduit si le FR a changé, ou si la version NL manque encore.
        $needTitle = ($nom !== '') && ($needSync || $nomNl === '');
        $needDesc = ($desc !== '') && ($needSync || $descNl === '');
        if ($needTitle || $needDesc) {
            $tr = aiTranslateStringsToNl($db, [$nom, $desc]);
            if ($tr['ok'] && count($tr['items']) === 2) {
                if ($nom !== '') {
                    $t = mb_substr(trim((string) $tr['items'][0]), 0, 150);
                    if ($t !== '') { $nomNl = $t; $done[] = 'titre'; }
                }
                if ($desc !== '') {
                    $t = mb_substr(trim((string) $tr['items'][1]), 0, 500);
                    if ($t !== '') { $descNl = $t; $done[] = 'description'; }
                }
            } elseif (!$tr['ok']) {
                $errors[] = 'titre/description : ' . $tr['error'];
            }
        }

        // --- 2) Guide + quiz : Claude, seulement si le FR a changé depuis la dernière fois.
        if ($needSync) {
            if (trim($frContent) !== '') {
                $r = nlTranslateBlocksJson($db, $frContent);
                if ($r['ok']) {
                    $contenuNl = $r['json'];
                    $done[] = 'guide';
                } else {
                    $errors[] = 'guide : ' . $r['error'];
                }
            }
            if (trim($frQuiz) !== '') {
                $r = nlTranslateQuizJson($db, $frQuiz);
                if ($r['ok']) {
                    $quizNl = $r['json'];
                    $done[] = 'quiz';
                } else {
                    $errors[] = 'quiz : ' . $r['error'];
                }
            }
        }

        // On ne mémorise l'empreinte que si TOUT ce qui devait être traduit l'a été,
        // sinon on retentera au prochain enregistrement (pas de contenu FR figé en NL).
        $newHash = empty($errors) ? $hash : (string) ($m['nl_hash'] ?? '');

        try {
            $db->prepare(
                "UPDATE modules SET nom_nl = ?, description_nl = ?, contenu_ia_nl = ?, quiz_json_nl = ?, nl_hash = ? WHERE id = ?"
            )->execute([
                $nomNl !== '' ? $nomNl : null,
                $descNl !== '' ? $descNl : null,
                $contenuNl !== '' ? $contenuNl : null,
                $quizNl !== '' ? $quizNl : null,
                $newHash !== '' ? $newHash : null,
                $moduleId,
            ]);
        } catch (Exception $e) {
            return ['ok' => false, 'done' => $done, 'error' => 'Enregistrement NL impossible'];
        }

        return [
            'ok' => empty($errors),
            'done' => $done,
            'error' => implode(' · ', $errors),
        ];
    }
}

if (!function_exists('famiFinalValidation')) {
    /**
     * VALIDATION FINALE d'un contenu — la DERNIÈRE étape de la relecture.
     *
     * C'est ICI, et SEULEMENT ici, que l'IA repasse sur tout :
     *   1) orthographe du GUIDE (forme uniquement),
     *   2) orthographe du QUIZ (forme uniquement, jamais les bonnes réponses),
     *   3) traduction NÉERLANDAISE (guide + quiz),
     *   4) PUBLICATION : le contenu devient enfin VISIBLE par les utilisateurs.
     *
     * Tant qu'on n'est pas passé par ici, le contenu reste caché (non validé).
     * S'il y a un quiz à relire, cette fonction n'est appelée qu'APRÈS sa validation.
     *
     * @return string message à afficher à l'utilisateur
     */
    function famiFinalValidation(PDO $db, $guideId, $actorId, $isAdmin = false)
    {
        @set_time_limit(0);
        $guideId = (int) $guideId;
        $st = $db->prepare("SELECT id, parent_id, contenu_ia, quiz_json FROM modules WHERE id = ? LIMIT 1");
        $st->execute([$guideId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) { return ''; }

        $msg = '';

        // 1) Orthographe du guide.
        $fr = (string) ($m['contenu_ia'] ?? '');
        if (trim($fr) !== '' && function_exists('nlProofreadBlocksJson')) {
            $pr = nlProofreadBlocksJson($db, $fr);
            if (!empty($pr['ok']) && trim((string) $pr['json']) !== '') {
                $db->prepare("UPDATE modules SET contenu_ia = ? WHERE id = ?")->execute([$pr['json'], $guideId]);
                $msg .= ' ✍️ Orthographe du guide vérifiée.';
            }
        }

        // 2) Orthographe du quiz.
        $qz = (string) ($m['quiz_json'] ?? '');
        if (trim($qz) !== '' && function_exists('nlProofreadQuizJson')) {
            $pr = nlProofreadQuizJson($db, $qz);
            if (!empty($pr['ok']) && trim((string) $pr['json']) !== '') {
                $db->prepare("UPDATE modules SET quiz_json = ? WHERE id = ?")->execute([$pr['json'], $guideId]);
                $msg .= ' 📝 Orthographe du quiz vérifiée.';
            }
        }

        // 3) Traduction néerlandaise (guide + quiz), en direct : ensuite tout est instantané.
        if (function_exists('nlSyncModule')) {
            nlSyncModule($db, $guideId, true);
            $msg .= ' 🌐 Version néerlandaise générée.';
        }

        // 4) PUBLICATION : jusqu'ici le contenu était caché car non validé.
        $pid = (int) ($m['parent_id'] ?? 0);
        if ($isAdmin && $pid > 0) {
            require_once __DIR__ . '/events.php';
            if (function_exists('publishSubmission')) {
                publishSubmission($db, $pid, (int) $actorId);
                $msg .= ' ✅ Contenu publié — il est maintenant visible.';
            }
        } else {
            $msg .= ' ⏳ En attente de validation par un admin (contenu encore caché).';
        }

        return $msg;
    }
}

if (!function_exists('spawnNlSync')) {
    /**
     * Lance la synchronisation NL EN TÂCHE DE FOND (l'utilisateur n'attend pas :
     * traduire un guide prend ~40-80 s). Même patron que la compression vidéo.
     *
     * Dégradation gracieuse : si le fond ne part pas (Windows en dev, exec désactivé),
     * il ne se passe RIEN de grave — le FR reste affiché, et l'admin dispose d'un
     * bouton « 🌐 Traduire en NL » pour lancer la traduction à la main.
     */
    function spawnNlSync($moduleId)
    {
        $moduleId = (int) $moduleId;
        if ($moduleId <= 0) {
            return;
        }
        if (stripos(PHP_OS, 'WIN') === 0 || !function_exists('exec')) {
            return; // dev local Windows : le worker tourne sur le serveur Linux
        }
        $worker = __DIR__ . '/../nl_sync.php';
        $cmd = 'nohup php ' . escapeshellarg($worker) . ' ' . $moduleId . ' > /dev/null 2>&1 &';
        @exec($cmd);
    }
}

if (!function_exists('moduleContenu')) {
    /** Blocs du guide dans la langue courante (NL si dispo, sinon FR). */
    function moduleContenu(array $m)
    {
        if (function_exists('currentLang') && currentLang() === 'nl') {
            $nl = trim((string) ($m['contenu_ia_nl'] ?? ''));
            if ($nl !== '') {
                return $nl;
            }
        }
        return (string) ($m['contenu_ia'] ?? '');
    }
}

if (!function_exists('moduleQuizJson')) {
    /** Quiz dans la langue courante (NL si dispo, sinon FR). */
    function moduleQuizJson(array $m)
    {
        if (function_exists('currentLang') && currentLang() === 'nl') {
            $nl = trim((string) ($m['quiz_json_nl'] ?? ''));
            if ($nl !== '') {
                return $nl;
            }
        }
        return (string) ($m['quiz_json'] ?? '');
    }
}
