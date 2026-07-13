<?php
// ============================================================
// module_quiz.php — CONTRÔLE DU QUIZ (séparé du guide).
//   L'admin/auteur relit les questions générées par l'IA : texte, options,
//   type (unique / multiple), bonnes réponses. Ajout/suppression. Puis valide.
//   Stocké dans modules.quiz_json (format multi-réponses).
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/i18n_nl.php'; // spawnNlSync : régénère le quiz NL après édition

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$uid = (int) ($_SESSION['user_id'] ?? 0);

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
$module = $id > 0 ? getModuleById($db, $id) : null;
if (!$module) { header('Location: index.php'); exit(); }
$canReview = $isAdmin || ((int) ($module['contenu_by'] ?? 0) === $uid && $uid > 0);
if (!$canReview) { header('Location: module.php?id=' . $id); exit(); }

// --- Enregistrement ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_quiz') {
    requireValidCSRF();
    $questions = [];
    foreach ((array) ($_POST['q'] ?? []) as $qi) {
        if (!is_array($qi)) { continue; }
        $text = trim((string) ($qi['text'] ?? ''));
        $optsAssoc = (array) ($qi['opt'] ?? []);
        $okAssoc = (array) ($qi['ok'] ?? []);
        ksort($optsAssoc, SORT_NUMERIC);
        $opts = []; $correct = []; $pos = 0;
        foreach ($optsAssoc as $j => $txt) {
            $txt = trim((string) $txt);
            if ($txt === '') { continue; }
            if (!empty($okAssoc[$j])) { $correct[] = $pos; }
            $opts[] = $txt;
            $pos++;
        }
        if ($text === '' || count($opts) < 2 || empty($correct)) { continue; }
        $type = (($qi['type'] ?? 'single') === 'multiple') ? 'multiple' : 'single';
        if ($type === 'single') { $correct = [$correct[0]]; }
        $questions[] = ['q' => $text, 'type' => $type, 'options' => $opts, 'correct' => array_values($correct)];
    }
    $json = $questions ? json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE) : null;
    $saveLang = (($_POST['lang'] ?? 'fr') === 'nl') ? 'nl' : 'fr';
    if ($saveLang === 'nl') {
        // Édition MANUELLE du quiz néerlandais : on n'écrit QUE le NL, pas de retraduction.
        // On marque le NL « à jour » avec le FR actuel seulement si le guide NL n'est pas en attente.
        $frHash = hash('sha256',
            trim((string) ($module['nom'] ?? '')) . '|' .
            trim((string) ($module['description'] ?? '')) . '|' .
            (string) ($module['contenu_ia'] ?? '') . '|' .
            (string) ($module['quiz_json'] ?? ''));
        $guideNlReady = trim((string) ($module['contenu_ia'] ?? '')) === '' || trim((string) ($module['contenu_ia_nl'] ?? '')) !== '';
        if ($guideNlReady) {
            $db->prepare("UPDATE modules SET quiz_json_nl = ?, nl_hash = ? WHERE id = ?")->execute([$json, $frHash, $id]);
        } else {
            $db->prepare("UPDATE modules SET quiz_json_nl = ? WHERE id = ?")->execute([$json, $id]);
        }
        $_SESSION['module_flash'] = "✅ Quiz néerlandais corrigé et enregistré.";
        header('Location: module_quiz.php?id=' . $id . '&lang=nl');
        exit();
    }
    // ÉCONOMIE — si le quiz FR n'a pas changé, on ne rappelle NI l'IA NI la traduction NL.
    if ((string) $json === (string) ($module['quiz_json'] ?? '')) {
        $_SESSION['module_flash'] = "✅ Aucun changement — rien à revérifier.";
        header('Location: module.php?id=' . $id);
        exit();
    }
    // PASSAGE 2 — re-vérification orthographe du quiz FR (forme uniquement, jamais le sens
    // ni les bonnes réponses). Sans risque : en cas d'échec, on garde le texte tel quel.
    if ($json !== null && function_exists('nlProofreadQuizJson')) {
        $pr = nlProofreadQuizJson($db, $json);
        if ($pr['ok'] && trim((string) $pr['json']) !== '') { $json = $pr['json']; }
    }
    $db->prepare("UPDATE modules SET quiz_json = ? WHERE id = ?")->execute([$json, $id]);
    spawnNlSync($id); // le quiz FR (corrigé) a changé → on régénère le quiz NL en tâche de fond
    $_SESSION['module_flash'] = $questions ? ("✅ Quiz enregistré (" . count($questions) . " question" . (count($questions) > 1 ? 's' : '') . "). 🌐 La version néerlandaise se met à jour.") : "✅ Quiz vidé.";
    header('Location: module.php?id=' . $id);
    exit();
}

// Langue éditée : FR (original) ou NL (pour corriger la traduction auto).
$lang = (($_GET['lang'] ?? 'fr') === 'nl') ? 'nl' : 'fr';
$quizCol = ($lang === 'nl') ? 'quiz_json_nl' : 'quiz_json';
$quiz = json_decode((string) ($module[$quizCol] ?? ''), true);
$quizNlFromFr = false;
if ((!is_array($quiz) || empty($quiz['questions'])) && $lang === 'nl') {
    // NL pas encore généré : on le génère MAINTENANT (synchrone), sans dépendre du worker.
    $frQuiz = (string) ($module['quiz_json'] ?? '');
    if (function_exists('nlTranslateQuizJson') && trim($frQuiz) !== '') {
        $tr = nlTranslateQuizJson($db, $frQuiz);
        if (!empty($tr['ok']) && trim((string) $tr['json']) !== '') {
            try { $db->prepare("UPDATE modules SET quiz_json_nl = ? WHERE id = ?")->execute([$tr['json'], $id]); } catch (Exception $e) {}
            $quiz = json_decode($tr['json'], true);
        }
    }
    if (!is_array($quiz) || empty($quiz['questions'])) {
        // Échec (clé API absente / réseau) : on part du quiz FR comme base à corriger.
        $quiz = json_decode($frQuiz, true);
        $quizNlFromFr = true;
    }
}
$questions = (is_array($quiz) && !empty($quiz['questions']) && is_array($quiz['questions'])) ? $quiz['questions'] : [];
$nbMul = 0; $nbSin = 0;
foreach ($questions as $q) { if (($q['type'] ?? 'single') === 'multiple') { $nbMul++; } else { $nbSin++; } }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contrôle du quiz — <?= htmlspecialchars(moduleNom($module)) ?></title>
<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
<style>
    :root { --forest:#1E4D2B; --leaf:#3E8E4E; --line:#d9e3dc; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#f4f7f6; margin:0; color:#21301F; }
    .topbar { position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 16px; flex-wrap:wrap; }
    .topbar .btn { border:none; border-radius:10px; padding:10px 15px; font-weight:700; cursor:pointer; text-decoration:none; font:inherit; }
    .btn-back { background:#e9ecef; color:#333; } .btn-save { background:var(--forest); color:#fff; }
    .wrap { max-width:820px; margin:0 auto; padding:18px 16px 130px; }
    .intro { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:16px; line-height:1.5; }
    .intro b { color:var(--forest); }
    .q { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:14px; position:relative; }
    .q .num { font-family:ui-monospace,monospace; font-size:.72rem; letter-spacing:.1em; text-transform:uppercase; color:var(--leaf); font-weight:700; }
    .q .qtext { width:100%; padding:10px; border:1px solid #ccd6cf; border-radius:8px; font:inherit; font-weight:700; margin:6px 0 10px; }
    .typewrap { display:flex; gap:16px; margin-bottom:10px; font-weight:600; flex-wrap:wrap; }
    .typewrap label { display:flex; align-items:center; gap:6px; }
    .opt { display:flex; align-items:center; gap:10px; margin:6px 0; }
    .opt .okbox { display:flex; align-items:center; gap:5px; background:#eef7f0; border:1px solid #cfe3d5; border-radius:8px; padding:6px 10px; font-size:.82rem; font-weight:700; color:var(--forest); white-space:nowrap; cursor:pointer; }
    .opt input[type=text] { flex:1; padding:8px 10px; border:1px solid #ccd6cf; border-radius:8px; font:inherit; }
    .opt .del { cursor:pointer; color:#b3261e; font-weight:800; user-select:none; padding:4px 8px; }
    .addopt { background:#eef7f0; color:var(--forest); border:1px dashed var(--leaf); border-radius:8px; padding:5px 12px; cursor:pointer; font-weight:700; font-size:.85rem; }
    .delq { position:absolute; top:12px; right:14px; background:#fdecec; color:#b3261e; border:1px solid #f3b4b4; border-radius:8px; padding:5px 10px; cursor:pointer; font-weight:700; font-size:.82rem; }
    .addq { display:block; width:100%; background:#eef7f0; color:var(--forest); border:1px dashed var(--moss,#74975b); border-radius:12px; padding:12px; cursor:pointer; font-weight:800; font-size:1rem; }
    .savebar { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--line); padding:12px; display:flex; justify-content:center; gap:12px; box-shadow:0 -6px 18px rgba(0,0,0,.06); }
    .pill { display:inline-block; background:#eef7f0; border:1px solid #cfe3d5; color:var(--forest); border-radius:999px; padding:3px 10px; font-size:.82rem; font-weight:700; }
    /* Verrou d'édition : tant qu'on n'a pas cliqué « Modifier », on ne peut rien changer. */
    body:not(.qedit) #qlist input, body:not(.qedit) #qlist select, body:not(.qedit) #qlist .okbox { pointer-events:none; }
    body:not(.qedit) .addopt, body:not(.qedit) .delq, body:not(.qedit) .del, body:not(.qedit) .addq { display:none; }
</style>
</head>
<body>
<form method="POST" action="module_quiz.php">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_quiz">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <div class="topbar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back">⬅ Quitter</a>
        <strong style="color:#1E4D2B;">📝 Contrôle du quiz <?= $lang === 'nl' ? '<span style="color:#b06a00;">— NL</span>' : '' ?></strong>
        <div style="display:inline-flex; border:1px solid var(--line); border-radius:10px; overflow:hidden;">
            <a href="module_quiz.php?id=<?= (int) $id ?>&lang=fr" class="btn" style="border-radius:0; <?= $lang === 'fr' ? 'background:#1E4D2B; color:#fff;' : 'background:#fff; color:#1E4D2B;' ?>">🇫🇷 FR</a>
            <a href="module_quiz.php?id=<?= (int) $id ?>&lang=nl" class="btn" style="border-radius:0; <?= $lang === 'nl' ? 'background:#1E4D2B; color:#fff;' : 'background:#fff; color:#1E4D2B;' ?>">🇳🇱 NL</a>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" id="qeditToggle" class="btn" style="background:#fff3d6; color:#8a5a00; border:1px solid #f0d089;" onclick="qSetEdit(!window._qedit)">✏️ Modifier</button>
            <button type="submit" class="btn btn-save">✅ Valider le quiz</button>
        </div>
    </div>
    <div class="wrap">
        <div class="intro">
            Relis les questions générées par l'IA : corrige le texte, coche <b>la ou les bonnes réponses</b>, choisis <b>Unique</b> (1 bonne réponse) ou <b>Multiple</b> (plusieurs). Tu peux ajouter/supprimer des questions.
            <div style="margin-top:8px;"><span class="pill" id="pillSin"><?= (int) $nbSin ?> unique(s)</span> <span class="pill" id="pillMul"><?= (int) $nbMul ?> multiple(s)</span></div>
        </div>
        <?php if ($lang === 'nl'): ?>
        <div class="intro" style="background:#fff3e0; border:1px solid #f0d089; color:#8a5a00;">
            🇳🇱 <b>Quiz en néerlandais.</b> Tu corriges ici la traduction. <?php if ($quizNlFromFr): ?><b>Il n'était pas encore généré : tu pars du quiz français comme base.</b> <?php endif; ?>
            ⚠️ Si tu modifies le quiz <b>français</b>, cette version NL sera <b>régénérée automatiquement</b> (le français fait autorité).
        </div>
        <?php endif; ?>

        <div id="qlist">
            <?php foreach ($questions as $i => $q): $type = (($q['type'] ?? 'single') === 'multiple') ? 'multiple' : 'single'; $correct = array_map('intval', (array) ($q['correct'] ?? [])); ?>
            <div class="q" data-q>
                <div class="delq" onclick="delQ(this)">🗑 Supprimer</div>
                <div class="num">Question <?= (int) $i + 1 ?></div>
                <input type="text" class="qtext" name="q[<?= $i ?>][text]" value="<?= htmlspecialchars((string) ($q['q'] ?? '')) ?>" placeholder="Énoncé de la question">
                <div class="typewrap">
                    <label><input type="radio" name="q[<?= $i ?>][type]" value="single" <?= $type === 'single' ? 'checked' : '' ?> onchange="syncType(this)"> Réponse unique</label>
                    <label><input type="radio" name="q[<?= $i ?>][type]" value="multiple" <?= $type === 'multiple' ? 'checked' : '' ?> onchange="syncType(this)"> Réponses multiples</label>
                </div>
                <div class="opts">
                    <?php foreach ((array) ($q['options'] ?? []) as $j => $opt): ?>
                    <div class="opt">
                        <label class="okbox"><input type="checkbox" name="q[<?= $i ?>][ok][<?= $j ?>]" value="1" <?= in_array($j, $correct, true) ? 'checked' : '' ?>> ✓ bonne</label>
                        <input type="text" name="q[<?= $i ?>][opt][<?= $j ?>]" value="<?= htmlspecialchars((string) $opt) ?>" placeholder="Option">
                        <span class="del" onclick="delOpt(this)" title="Supprimer l'option">✕</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <span class="addopt" onclick="addOpt(this)">+ Option</span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="addq" onclick="addQ()">➕ Ajouter une question</div>
    </div>
    <div class="savebar">
        <a href="module.php?id=<?= (int) $id ?>" class="btn btn-back" style="text-decoration:none;">Annuler</a>
        <button type="submit" class="btn btn-save">✅ Valider le quiz</button>
    </div>
</form>

<script>
var QC = <?= count($questions) ?>; // compteur d'index de questions (unique)
function syncType(r) { /* pas de contrainte dure : l'enregistrement normalise (single -> 1 bonne) */ }
function delOpt(x) { var o = x.closest('.opt'); if (o) { o.remove(); } }
function addOpt(btn) {
    var q = btn.closest('[data-q]');
    var qi = q.getAttribute('data-qi');
    var opts = q.querySelector('.opts');
    var jj = (parseInt(q.getAttribute('data-jc'), 10) || opts.children.length) ;
    while (opts.querySelector('input[name="q[' + qi + '][opt][' + jj + ']"]')) { jj++; }
    q.setAttribute('data-jc', jj + 1);
    var d = document.createElement('div'); d.className = 'opt';
    d.innerHTML = '<label class="okbox"><input type="checkbox" name="q[' + qi + '][ok][' + jj + ']" value="1"> ✓ bonne</label>'
        + '<input type="text" name="q[' + qi + '][opt][' + jj + ']" placeholder="Option">'
        + '<span class="del" onclick="delOpt(this)" title="Supprimer l\'option">✕</span>';
    opts.appendChild(d); d.querySelector('input[type=text]').focus();
}
function delQ(x) { if (!confirm('Supprimer définitivement cette question ?')) { return; } var q = x.closest('[data-q]'); if (q) { q.remove(); } }
function addQ() {
    var i = QC++;
    var list = document.getElementById('qlist');
    var d = document.createElement('div'); d.className = 'q'; d.setAttribute('data-q', ''); d.setAttribute('data-qi', i); d.setAttribute('data-jc', '2');
    d.innerHTML = '<div class="delq" onclick="delQ(this)">🗑 Supprimer</div>'
        + '<div class="num">Nouvelle question</div>'
        + '<input type="text" class="qtext" name="q[' + i + '][text]" placeholder="Énoncé de la question">'
        + '<div class="typewrap"><label><input type="radio" name="q[' + i + '][type]" value="single" checked> Réponse unique</label>'
        + '<label><input type="radio" name="q[' + i + '][type]" value="multiple"> Réponses multiples</label></div>'
        + '<div class="opts">'
        + '<div class="opt"><label class="okbox"><input type="checkbox" name="q[' + i + '][ok][0]" value="1"> ✓ bonne</label><input type="text" name="q[' + i + '][opt][0]" placeholder="Option"><span class="del" onclick="delOpt(this)">✕</span></div>'
        + '<div class="opt"><label class="okbox"><input type="checkbox" name="q[' + i + '][ok][1]" value="1"> ✓ bonne</label><input type="text" name="q[' + i + '][opt][1]" placeholder="Option"><span class="del" onclick="delOpt(this)">✕</span></div>'
        + '</div><span class="addopt" onclick="addOpt(this)">+ Option</span>';
    list.appendChild(d);
    d.querySelector('.qtext').focus();
}
// index de question sur les blocs existants (pour addOpt)
(function () {
    var qs = document.querySelectorAll('#qlist > .q');
    qs.forEach(function (q, idx) { q.setAttribute('data-qi', idx); q.setAttribute('data-jc', String(q.querySelectorAll('.opt').length)); });
})();
function qSetEdit(on) {
    window._qedit = !!on;
    document.body.classList.toggle('qedit', !!on);
    var b = document.getElementById('qeditToggle');
    if (b) { b.textContent = on ? '🔒 Terminer' : '✏️ Modifier'; b.style.background = on ? '#e8f5e9' : '#fff3d6'; b.style.color = on ? '#2d5a37' : '#8a5a00'; }
}
qSetEdit(false);
</script>
</body>
</html>
