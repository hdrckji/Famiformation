<?php
// ============================================================
// module_review.php — RELECTURE & CORRECTION du contenu extrait par l'IA.
//   L'uploadeur (ou l'admin) relit chaque bloc, corrige le texte mal extrait,
//   et accepte/ignore les suggestions de l'IA (champ "fix", affiché en rouge).
//   À la validation, le contenu propre est enregistré (plus de rouge à l'affichage).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/ai_uniformise.php'; // aiRotateImageFile
require_once 'includes/i18n_nl.php';       // spawnNlSync : régénère le NL après édition

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$uid = (int) ($_SESSION['user_id'] ?? 0);

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$module = $id > 0 ? getModuleById($db, $id) : null;
if (!$module) { header('Location: index.php'); exit(); }

// Accès : admin, ou l'auteur du contenu (celui qui l'a déposé).
$canReview = $isAdmin || ((int) ($module['contenu_by'] ?? 0) === $uid && $uid > 0);
if (!$canReview) { header('Location: module.php?id=' . $id); exit(); }

$images = (array) json_decode((string) ($module['contenu_images'] ?? '[]'), true);
$imgBase = rtrim((defined('FAMI_STORAGE_BASE') ? FAMI_STORAGE_BASE : (__DIR__ . '/uploads')), '/');

// ---- Enregistrement de la relecture ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_review') {
    requireValidCSRF();

    // La langue de travail est TOUJOURS celle du document (source) : à ce stade la traduction
    // n'existe pas encore, elle est produite à la toute fin (après le contrôle du quiz).
    $srcLang = moduleSourceLang($module);
    $lang = $srcLang;

    // --- Mode visuel : les blocs arrivent en JSON (édition directement sur la page) ---
    if (isset($_POST['blocks_json'])) {
        $arr = json_decode((string) $_POST['blocks_json'], true);
        $blocksIn = (is_array($arr) && isset($arr['blocks']) && is_array($arr['blocks'])) ? $arr['blocks'] : (is_array($arr) ? $arr : []);

        foreach ($blocksIn as &$bl) {
            if (is_array($bl) && ($bl['type'] ?? '') === 'image' && !empty($bl['rotate']) && function_exists('aiRotateImageFile')) {
                $src = trim((string) ($bl['src'] ?? ''));
                if ($src !== '') {
                    // Image ajoutée depuis l'éditeur : on pivote son propre fichier (clé volume directe).
                    $abs = realpath($imgBase . '/' . $src);
                    $baseReal = realpath($imgBase);
                    if ($abs !== false && $baseReal !== false && strpos($abs, $baseReal) === 0) {
                        aiRotateImageFile($abs, (int) $bl['rotate']);
                    }
                } else {
                    $idx = (int) ($bl['n'] ?? 0) - 1;
                    if ($idx >= 0 && isset($images[$idx])) {
                        aiRotateImageFile($imgBase . '/' . $images[$idx], (int) $bl['rotate']);
                    }
                }
                $bl['rotate'] = 0;
            }
        }
        unset($bl);
        $clean = function_exists('aiSanitizeBlocks') ? aiSanitizeBlocks($blocksIn) : $blocksIn;
        $frJson = json_encode(['blocks' => $clean], JSON_UNESCAPED_UNICODE);

        // 1) On ENREGISTRE le guide relu. AUCUN appel IA à ce stade.
        $db->prepare("UPDATE modules SET contenu_ia = ?, uniformized = 1 WHERE id = ?")
           ->execute([$frJson, $id]);

        // 2) Guide « à évaluer » sans quiz (ex. rédigé de zéro) : on génère le quiz maintenant,
        //    puisqu'il faudra le relire avant la validation finale.
        $quizMsg = '';
        $quizFailed = false;
        $quizFatal = false;   // échec dû à un RÉGLAGE : relancer à l'identique ne marchera jamais
        $hasQuiz = trim((string) ($module['quiz_json'] ?? '')) !== '';
        $needQuiz = !$hasQuiz && !empty($module['a_evaluer']);
        if ($needQuiz) {
            require_once 'includes/ai_uniformise.php';
            if (function_exists('aiGenerateQuiz')) {
                @set_time_limit(0);
                // Le quiz porte sur le guide ET la vidéo (si sa transcription est disponible).
                $transcript = '';
                try {
                    $ts = $db->prepare("SELECT transcript FROM modules WHERE parent_id = ? AND content_kind = 'video' LIMIT 1");
                    $ts->execute([(int) ($module['parent_id'] ?? 0)]);
                    $transcript = trim((string) $ts->fetchColumn());
                } catch (Exception $e) {}
                $qSource = "CONTENU ÉCRIT (le guide) :\n" . $frJson;
                if ($transcript !== '') {
                    $qSource .= "\n\n---\n\nCONTENU DE LA VIDÉO (transcription) :\n" . $transcript;
                }
                $qz = aiGenerateQuiz($db, $qSource);
                if (!empty($qz['ok']) && !empty($qz['quiz'])) {
                    $quizJson = json_encode($qz['quiz'], JSON_UNESCAPED_UNICODE);
                    $db->prepare("UPDATE modules SET quiz_json = ? WHERE id = ?")->execute([$quizJson, $id]);
                    $hasQuiz = true;
                    $quizMsg = ' 📝 Quiz généré (' . count($qz['quiz']['questions'] ?? []) . ').';

                    // Questions sur lesquelles l'IA a un doute → notification (admins + auteur).
                    if (function_exists('famiCountDoubts') && function_exists('logAiDoubts')) {
                        $nbQD = famiCountDoubts($quizJson);
                        if ($nbQD > 0) {
                            require_once 'includes/events.php';
                            logAiDoubts($db, $id, (int) ($_SESSION['user_id'] ?? 0), $nbQD, 'quiz');
                            $quizMsg .= ' ⚠️ ' . $nbQD . ' question' . ($nbQD > 1 ? 's' : '') . ' douteuse' . ($nbQD > 1 ? 's' : '') . ' — à trancher.';
                        }
                    }
                } else {
                    $quizFailed = true;
                    $quizFatal = !empty($qz['fatal']);
                    $quizErr = (string) ($qz['error'] ?? 'erreur');
                    $quizMsg = ' ⚠️ Quiz non généré : ' . $quizErr . '.';
                    require_once 'includes/events.php';
                    if (function_exists('logSiteError')) {
                        logSiteError($db, $id, (int) ($_SESSION['user_id'] ?? 0), 'quiz', $quizErr);
                    }
                }
            } else {
                $quizFailed = true;
                $quizFatal = true;   // le moteur IA n'est pas chargé : relancer n'y changera rien
                $quizErr = 'moteur IA indisponible';
                $quizMsg = ' ⚠️ Quiz non généré : moteur IA indisponible.';
            }
        }

        // 2bis) ÉCHEC PASSAGER (l'API a toussé) : on ne publie pas, on revient pour relancer.
        //       Le guide est enregistré (rien de perdu), un nouveau clic a de vraies chances d'aboutir.
        if ($quizFailed && !$quizFatal) {
            $_SESSION['module_flash'] = "✅ Guide enregistré, mais" . $quizMsg
                . " Le contenu reste NON publié. Reclique sur « Valider » pour relancer la génération du quiz.";
            header('Location: module_edit.php?id=' . $id);
            exit();
        }

        // 2ter) ÉCHEC DÉFINITIF (un réglage, pas une panne) : quiz coupés dans les préférences, clé
        //       API absente, 0 question demandée… Avant, on renvoyait ici vers « reclique sur Valider »
        //       — un conseil qui ne pouvait JAMAIS aboutir : on relançait, ça échouait pour la même
        //       raison, et le contenu restait bloqué en relecture, non publié, indéfiniment.
        //       Désormais on ne bloque plus : on saute l'étape quiz et on publie le guide (comme
        //       lorsque « à évaluer » est décoché), en disant clairement ce qui manque et comment
        //       ajouter le quiz plus tard. $hasQuiz reste faux → on tombe dans l'étape 4 (validation
        //       finale + publication) juste en dessous.
        if ($quizFatal) {
            $quizMsg = ' ⚠️ Quiz non généré : ' . $quizErr . '. Le guide est publié SANS quiz —'
                . ' corrige ce réglage puis reviens sur ce module pour générer le quiz.';
        }

        // 3) S'IL Y A UN QUIZ À RELIRE : on s'arrête ici. Pas d'IA, pas de traduction, pas de
        //    publication. La VÉRIFICATION FINALE se fait APRÈS la relecture du quiz — c'est là
        //    que l'IA repasse sur TOUT (guide + quiz) et que le contenu devient visible.
        if ($hasQuiz) {
            $_SESSION['module_flash'] = "✅ Guide enregistré." . $quizMsg
                . " 👉 Relis maintenant le quiz : la vérification finale et la publication se feront à SA validation.";
            header('Location: module_quiz.php?id=' . $id);
            exit();
        }

        // 4) PAS DE QUIZ DEMANDÉ (case « à évaluer » décochée) → cette validation EST l'étape
        //    finale : l'IA revérifie tout, traduit dans l'autre langue, et publie le contenu.
        $final = famiFinalValidation($db, $id, (int) ($_SESSION['user_id'] ?? 0), $isAdmin);
        $_SESSION['module_flash'] = "✅ Relecture validée." . $quizMsg . $final;
        header('Location: module.php?id=' . $id);
        exit();
    }

    $blocks = [];
    foreach ((array) ($_POST['b'] ?? []) as $bi) {
        $type = is_array($bi) ? (string) ($bi['type'] ?? '') : '';
        // « Afficher comme » : conversion entre texte simple / encadré / citation.
        if (in_array($type, ['text', 'callout', 'quote'], true) && in_array(($bi['as'] ?? ''), ['text', 'callout', 'quote'], true)) {
            $type = (string) $bi['as'];
        }
        switch ($type) {
            case 'hero':
                $blocks[] = ['type' => 'hero', 'title' => trim((string) ($bi['title'] ?? '')), 'subtitle' => trim((string) ($bi['subtitle'] ?? ''))];
                break;
            case 'section':
                if (trim((string) ($bi['title'] ?? '')) !== '') { $blocks[] = ['type' => 'section', 'title' => trim((string) $bi['title'])]; }
                break;
            case 'text':
                if (trim((string) ($bi['text'] ?? '')) !== '') { $blocks[] = ['type' => 'text', 'text' => trim((string) $bi['text'])]; }
                break;
            case 'list':
                $items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($bi['items'] ?? ''))), 'strlen'));
                if ($items) { $blocks[] = ['type' => 'list', 'items' => $items]; }
                break;
            case 'steps':
                $items = [];
                foreach ((array) ($bi['items'] ?? []) as $it) {
                    $t = trim((string) ($it['title'] ?? '')); $d = trim((string) ($it['desc'] ?? ''));
                    if ($t !== '' || $d !== '') { $items[] = ['title' => $t, 'desc' => $d]; }
                }
                if ($items) { $blocks[] = ['type' => 'steps', 'items' => $items]; }
                break;
            case 'callout':
                $style = in_array(($bi['style'] ?? 'info'), ['info', 'tip', 'warning'], true) ? $bi['style'] : 'info';
                if (trim((string) ($bi['text'] ?? '')) !== '' || trim((string) ($bi['title'] ?? '')) !== '') {
                    $blocks[] = ['type' => 'callout', 'style' => $style, 'title' => trim((string) ($bi['title'] ?? '')), 'text' => trim((string) ($bi['text'] ?? ''))];
                }
                break;
            case 'keyfigures':
                $items = [];
                foreach ((array) ($bi['items'] ?? []) as $it) {
                    $v = trim((string) ($it['value'] ?? '')); $l = trim((string) ($it['label'] ?? ''));
                    if ($v !== '') { $items[] = ['value' => $v, 'label' => $l]; }
                }
                if ($items) { $blocks[] = ['type' => 'keyfigures', 'items' => $items]; }
                break;
            case 'image':
                $n = (int) ($bi['n'] ?? 0);
                $rotate = (int) ($bi['rotate'] ?? 0);
                if (in_array((($rotate % 360) + 360) % 360, [90, 180, 270], true) && isset($images[$n - 1])) {
                    aiRotateImageFile($imgBase . '/' . $images[$n - 1], $rotate);
                }
                $size = ($bi['size'] ?? 'm'); if (!in_array($size, ['s', 'm', 'l'], true)) { $size = 'm'; }
                $blocks[] = ['type' => 'image', 'n' => $n, 'caption' => trim((string) ($bi['caption'] ?? '')), 'size' => $size];
                break;
            case 'quote':
                if (trim((string) ($bi['text'] ?? '')) !== '') { $blocks[] = ['type' => 'quote', 'text' => trim((string) $bi['text'])]; }
                break;
        }
    }
    $clean = function_exists('aiSanitizeBlocks') ? aiSanitizeBlocks($blocks) : $blocks;
    $json = json_encode(['blocks' => $clean], JSON_UNESCAPED_UNICODE);
    // Aucune traduction ici : elle est produite à la validation finale (après le quiz).
    $db->prepare("UPDATE modules SET contenu_ia = ?, uniformized = 1 WHERE id = ?")->execute([$json, $id]);
    $_SESSION['module_flash'] = "✅ Contenu relu et enregistré.";
    header('Location: ' . (!empty($module['quiz_json']) ? 'module_quiz.php?id=' . $id : 'module.php?id=' . $id));
    exit();
}

// ---- Chargement des blocs pour l'éditeur ----
$data = json_decode((string) ($module['contenu_ia'] ?? ''), true);
$blocks = (is_array($data) && !empty($data['blocks']) && is_array($data['blocks'])) ? $data['blocks'] : null;
if (!$blocks) { header('Location: module.php?id=' . $id); exit(); }

$pdfUrl = !empty($module['pdf_path']) ? moduleFileUrl($module['pdf_path']) : '';
$ta = function ($s) { return htmlspecialchars((string) $s); };
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relecture — <?= $ta(moduleNom($module)) ?></title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<style>
    :root { --forest:#1E4D2B; --leaf:#3E8E4E; --line:#d9e3dc; --paper:#f4f7f6; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:var(--paper); margin:0; color:#21301F; }
    .topbar { position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; flex-wrap:wrap; }
    .topbar a, .btn { text-decoration:none; border:none; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; font:inherit; }
    .btn-back { background:#e9ecef; color:#333; }
    .btn-pdf { background:#eef7f0; color:var(--forest); border:1px solid #cfe3d5; }
    .btn-save { background:var(--forest); color:#fff; }
    .wrap { max-width:820px; margin:0 auto; padding:22px 18px 120px; }
    .intro { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:18px; line-height:1.55; }
    .intro strong { color:var(--forest); }
    .blk { position:relative; background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:14px; }
    .blk-lock { position:absolute; inset:0; z-index:3; background:rgba(247,248,242,.45); border-radius:12px; display:flex; align-items:flex-start; justify-content:flex-end; padding:8px; }
    .blk:not(.locked) .blk-lock { display:none; }
    .blk-lock button { background:var(--forest); color:#fff; border:none; border-radius:8px; padding:7px 14px; font-weight:700; cursor:pointer; font:inherit; box-shadow:0 2px 8px rgba(0,0,0,.12); }
    .as-select { max-width:220px; }
    .blk-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
    .blk-type { font-family:ui-monospace,Consolas,monospace; font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--leaf); font-weight:700; }
    label.mini { display:block; font-size:.78rem; color:#5a6b60; font-weight:700; margin:8px 0 3px; }
    input[type=text], textarea, select { width:100%; padding:9px 10px; border:1px solid #ccd6cf; border-radius:8px; font:inherit; background:#fdfefb; }
    textarea { min-height:70px; resize:vertical; line-height:1.5; }
    .row2 { display:flex; gap:10px; flex-wrap:wrap; }
    .row2 > * { flex:1; min-width:180px; }
    .item { border-left:3px solid #e1e8e3; padding-left:10px; margin:8px 0; }
    .fix-box { margin-top:8px; background:#fdecec; border:1px solid #f3b4b4; border-radius:10px; padding:10px 12px; }
    .fix-label { font-weight:800; color:#c0392b; font-size:.82rem; }
    .fix-suggestion { color:#c0392b; font-weight:700; margin:4px 0 8px; line-height:1.5; }
    .btn-mini { border:none; border-radius:8px; padding:6px 12px; font-weight:700; cursor:pointer; font-size:.85rem; margin-right:6px; }
    .btn-apply { background:var(--forest); color:#fff; }
    .btn-ignore { background:#e9ecef; color:#444; }
    .img-prev { max-width:260px; max-height:220px; border-radius:8px; border:1px solid var(--line); display:block; margin:6px 0; }
    .rot { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:6px 0; }
    .rot button { border:1px solid #ccd6cf; background:#fff; border-radius:8px; padding:5px 10px; cursor:pointer; font:inherit; }
    .rot button.on { background:var(--forest); color:#fff; border-color:var(--forest); }
    .savebar { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--line); padding:12px 18px; display:flex; justify-content:center; gap:12px; box-shadow:0 -6px 18px rgba(0,0,0,.06); }
    .pdf-panel { display:none; background:#fff; border:1px solid var(--line); border-radius:12px; overflow:hidden; margin-bottom:18px; }
    .pdf-panel.open { display:block; }
    .pdf-panel iframe { width:100%; height:70vh; border:none; }
</style>
</head>
<body>
<form method="POST" action="module_review.php">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_review">
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <div class="topbar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back">⬅ Quitter sans enregistrer</a>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="module_edit.php?id=<?= (int) $id ?>" class="btn btn-pdf" style="background:#eef2ff; color:#33417a; border-color:#ccd4f5;">🖼 Aperçu visuel</a>
            <?php if ($pdfUrl !== ''): ?><button type="button" class="btn btn-pdf" onclick="togglePdf()">📄 Voir le PDF original</button><?php endif; ?>
            <button type="submit" class="btn btn-save">✅ Valider la relecture</button>
        </div>
    </div>

    <div class="wrap">
        <div class="intro">
            <strong>Relis le contenu extrait par l'IA.</strong> Corrige librement un texte mal extrait en le retapant.
            Là où l'IA a un <span style="color:#c0392b; font-weight:700;">doute (en rouge)</span>, elle propose une correction : clique
            <strong>Appliquer</strong> pour la prendre, ou <strong>Ignorer</strong> pour garder l'original. À l'affichage final, plus de rouge.
        </div>

        <?php if ($pdfUrl !== ''): ?>
        <div class="pdf-panel" id="pdfPanel"><iframe src="<?= $ta($pdfUrl) ?>" title="PDF original"></iframe></div>
        <?php endif; ?>

        <?php foreach ($blocks as $i => $b): $type = (string) ($b['type'] ?? ''); $switchable = in_array($type, ['text', 'callout', 'quote'], true); ?>
        <div class="blk locked">
            <div class="blk-lock"><button type="button" onclick="unlockBlk(this)">✏️ Modifier ce bloc</button></div>
            <div class="blk-head">
                <span class="blk-type"><?= $ta($type) ?></span>
                <?php if ($switchable): ?>
                <span><label class="mini" style="display:inline; margin:0 6px 0 0;">Afficher comme</label>
                <select class="as-select" name="b[<?= $i ?>][as]" style="display:inline-block; width:auto;">
                    <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>Texte simple</option>
                    <option value="callout" <?= $type === 'callout' ? 'selected' : '' ?>>Encadré</option>
                    <option value="quote" <?= $type === 'quote' ? 'selected' : '' ?>>Citation</option>
                </select></span>
                <?php endif; ?>
            </div>
            <input type="hidden" name="b[<?= $i ?>][type]" value="<?= $ta($type) ?>">
            <?php if ($type === 'hero'): ?>
                <label class="mini">Titre</label>
                <input type="text" name="b[<?= $i ?>][title]" value="<?= $ta($b['title'] ?? '') ?>">
                <label class="mini">Sous-titre</label>
                <input type="text" name="b[<?= $i ?>][subtitle]" value="<?= $ta($b['subtitle'] ?? '') ?>">

            <?php elseif ($type === 'section'): ?>
                <label class="mini">Titre de section</label>
                <input type="text" name="b[<?= $i ?>][title]" value="<?= $ta($b['title'] ?? '') ?>">

            <?php elseif ($type === 'text'): ?>
                <label class="mini">Texte</label>
                <textarea id="b_<?= $i ?>_text" name="b[<?= $i ?>][text]"><?= $ta($b['text'] ?? '') ?></textarea>
                <?php if (trim((string) ($b['fix'] ?? '')) !== ''): ?>
                <div class="fix-box" data-fix="<?= htmlspecialchars((string) $b['fix'], ENT_QUOTES) ?>">
                    <span class="fix-label">⚠ Doute de l'IA — correction proposée :</span>
                    <div class="fix-suggestion"><?= nl2br($ta($b['fix'])) ?></div>
                    <button type="button" class="btn-mini btn-apply" onclick="applyFix(this,'b_<?= $i ?>_text')">✓ Appliquer</button>
                    <button type="button" class="btn-mini btn-ignore" onclick="ignoreFix(this)">✗ Ignorer</button>
                </div>
                <?php endif; ?>

            <?php elseif ($type === 'list'): ?>
                <label class="mini">Points (un par ligne)</label>
                <textarea name="b[<?= $i ?>][items]"><?= $ta(implode("\n", array_map('strval', (array) ($b['items'] ?? [])))) ?></textarea>

            <?php elseif ($type === 'steps'): ?>
                <?php foreach ((array) ($b['items'] ?? []) as $j => $it): ?>
                <div class="item">
                    <label class="mini">Étape <?= (int) $j + 1 ?> — titre</label>
                    <input type="text" name="b[<?= $i ?>][items][<?= $j ?>][title]" value="<?= $ta(is_array($it) ? ($it['title'] ?? '') : '') ?>">
                    <label class="mini">Détail</label>
                    <textarea name="b[<?= $i ?>][items][<?= $j ?>][desc]"><?= $ta(is_array($it) ? ($it['desc'] ?? '') : (string) $it) ?></textarea>
                </div>
                <?php endforeach; ?>

            <?php elseif ($type === 'callout'): ?>
                <label class="mini">Type d'encadré</label>
                <select name="b[<?= $i ?>][style]">
                    <?php foreach (['info' => 'Info', 'tip' => 'Astuce', 'warning' => 'Attention'] as $sv => $sl): ?>
                    <option value="<?= $sv ?>" <?= (($b['style'] ?? 'info') === $sv) ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="mini">Titre</label>
                <input type="text" name="b[<?= $i ?>][title]" value="<?= $ta($b['title'] ?? '') ?>">
                <label class="mini">Texte</label>
                <textarea id="b_<?= $i ?>_ctext" name="b[<?= $i ?>][text]"><?= $ta($b['text'] ?? '') ?></textarea>
                <?php if (trim((string) ($b['fix'] ?? '')) !== ''): ?>
                <div class="fix-box" data-fix="<?= htmlspecialchars((string) $b['fix'], ENT_QUOTES) ?>">
                    <span class="fix-label">⚠ Doute de l'IA — correction proposée :</span>
                    <div class="fix-suggestion"><?= nl2br($ta($b['fix'])) ?></div>
                    <button type="button" class="btn-mini btn-apply" onclick="applyFix(this,'b_<?= $i ?>_ctext')">✓ Appliquer</button>
                    <button type="button" class="btn-mini btn-ignore" onclick="ignoreFix(this)">✗ Ignorer</button>
                </div>
                <?php endif; ?>

            <?php elseif ($type === 'keyfigures'): ?>
                <?php foreach ((array) ($b['items'] ?? []) as $j => $it): ?>
                <div class="item row2">
                    <div><label class="mini">Chiffre</label><input type="text" name="b[<?= $i ?>][items][<?= $j ?>][value]" value="<?= $ta(is_array($it) ? ($it['value'] ?? '') : '') ?>"></div>
                    <div><label class="mini">Libellé</label><input type="text" name="b[<?= $i ?>][items][<?= $j ?>][label]" value="<?= $ta(is_array($it) ? ($it['label'] ?? '') : '') ?>"></div>
                </div>
                <?php endforeach; ?>

            <?php elseif ($type === 'image'): ?>
                <?php $n = (int) ($b['n'] ?? 0); $imgUrl = isset($images[$n - 1]) ? moduleFileUrl($images[$n - 1]) : ''; ?>
                <?php $curSize = ($b['size'] ?? 'm'); if (!in_array($curSize, ['s', 'm', 'l'], true)) { $curSize = 'm'; } $prevW = ['s' => 160, 'm' => 220, 'l' => 280][$curSize]; ?>
                <input type="hidden" name="b[<?= $i ?>][n]" value="<?= $n ?>">
                <input type="hidden" name="b[<?= $i ?>][rotate]" id="b_<?= $i ?>_rot" value="0">
                <input type="hidden" name="b[<?= $i ?>][size]" id="b_<?= $i ?>_size" value="<?= $curSize ?>">
                <?php if ($imgUrl !== ''): ?>
                    <img class="img-prev" id="b_<?= $i ?>_img" src="<?= $ta($imgUrl) ?>" alt="" style="max-width:<?= (int) $prevW ?>px;">
                    <div class="rot">
                        <span class="mini" style="margin:0;">Pivoter :</span>
                        <?php foreach ([0, 90, 180, 270] as $deg): ?>
                        <button type="button" class="<?= $deg === 0 ? 'on' : '' ?>" onclick="setRot(<?= $i ?>,<?= $deg ?>,this)"><?= $deg ?>°</button>
                        <?php endforeach; ?>
                    </div>
                    <div class="rot">
                        <span class="mini" style="margin:0;">Taille :</span>
                        <button type="button" class="<?= $curSize === 's' ? 'on' : '' ?>" onclick="setSize(<?= $i ?>,'s',this)">Petite</button>
                        <button type="button" class="<?= $curSize === 'm' ? 'on' : '' ?>" onclick="setSize(<?= $i ?>,'m',this)">Moyenne</button>
                        <button type="button" class="<?= $curSize === 'l' ? 'on' : '' ?>" onclick="setSize(<?= $i ?>,'l',this)">Grande</button>
                    </div>
                <?php endif; ?>
                <label class="mini">Légende</label>
                <input type="text" name="b[<?= $i ?>][caption]" value="<?= $ta($b['caption'] ?? '') ?>">

            <?php elseif ($type === 'quote'): ?>
                <label class="mini">Citation / consigne forte</label>
                <textarea name="b[<?= $i ?>][text]"><?= $ta($b['text'] ?? '') ?></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="savebar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back">Annuler</a>
        <button type="submit" class="btn btn-save">✅ Valider la relecture</button>
    </div>
</form>

<script>
function applyFix(btn, taId) {
    var box = btn.closest('.fix-box');
    var ta = document.getElementById(taId);
    if (ta && box) { ta.value = box.getAttribute('data-fix'); }
    if (box) { box.style.display = 'none'; }
}
function ignoreFix(btn) {
    var box = btn.closest('.fix-box');
    if (box) { box.style.display = 'none'; }
}
function unlockBlk(btn) {
    var blk = btn.closest('.blk');
    if (blk) { blk.classList.remove('locked'); }
}
function setSize(i, val, btn) {
    document.getElementById('b_' + i + '_size').value = val;
    var w = { s: 160, m: 220, l: 280 }[val] || 220;
    var img = document.getElementById('b_' + i + '_img');
    if (img) { img.style.maxWidth = w + 'px'; }
    btn.parentNode.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); });
    btn.classList.add('on');
}
function setRot(i, deg, btn) {
    document.getElementById('b_' + i + '_rot').value = deg;
    var img = document.getElementById('b_' + i + '_img');
    if (img) { img.style.transform = 'rotate(' + deg + 'deg)'; }
    var wrap = btn.parentNode;
    wrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('on'); });
    btn.classList.add('on');
}
function togglePdf() {
    var p = document.getElementById('pdfPanel');
    if (p) { p.classList.toggle('open'); }
}
</script>
</body>
</html>
