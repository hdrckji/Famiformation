<?php
// ============================================================
// quiz_check.php — corrige le quiz côté serveur (les bonnes réponses ne
// transitent jamais côté client avant validation) et affiche le score.
// ============================================================
require_once 'config.php';
verifierConnexion($db);
require_once 'includes/modules.php';
require_once 'includes/i18n_nl.php'; // moduleQuizJson() : énoncés dans la langue de l'utilisateur

$id = (int) ($_POST['module_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id <= 0) {
    header('Location: index.php');
    exit();
}
requireValidCSRF();

$module = getModuleById($db, $id);
if (!$module || empty($module['quiz_json'])) {
    header('Location: module.php?id=' . $id);
    exit();
}

$isAdmin = ((($_SESSION['role'] ?? '') === 'admin') && (!function_exists('isApercuActif') || !isApercuActif()));
if (!$isAdmin && function_exists('userCanSeeModule') && !userCanSeeModule($module, function_exists('currentDisplayRole') ? currentDisplayRole() : (string) ($_SESSION['role'] ?? ''))) {
    header('Location: index.php');
    exit();
}

// On corrige TOUJOURS sur la banque d'origine (les bonnes réponses ne sortent
// jamais du serveur) ; on affiche les énoncés dans la langue de l'utilisateur
// (les indices de `correct` sont identiques en FR et en NL).
$quiz = json_decode((string) $module['quiz_json'], true);
$qs = (isset($quiz['questions']) && is_array($quiz['questions'])) ? $quiz['questions'] : [];

$quizLbl = function_exists('moduleQuizJson') ? json_decode((string) moduleQuizJson($module), true) : null;
$qsLbl = (isset($quizLbl['questions']) && is_array($quizLbl['questions'])) ? $quizLbl['questions'] : $qs;

$answers = $_POST['a'] ?? [];

// Seules les questions RÉELLEMENT posées (tirage aléatoire de 10) sont notées.
// Sans `sel` (ancien lien / quiz complet), on corrige tout : rétrocompatible.
$sel = is_array($_POST['sel'] ?? null)
    ? array_values(array_unique(array_map('intval', $_POST['sel'])))
    : array_keys($qs);
$sel = array_values(array_filter($sel, function ($i) use ($qs) { return isset($qs[$i]); }));
if (empty($sel)) { $sel = array_keys($qs); }

$total = count($sel);
$score = 0;
$results = [];
foreach ($sel as $i) {
    $q = $qs[$i];
    $ua = array_values(array_unique(array_map('intval', (array) ($answers[$i] ?? []))));
    sort($ua);
    $cor = array_values(array_map('intval', (array) ($q['correct'] ?? [])));
    sort($cor);
    $ok = ($ua === $cor);
    if ($ok) { $score++; }
    $results[] = ['q' => (isset($qsLbl[$i]) ? $qsLbl[$i] : $q) + $q, 'ua' => $ua, 'cor' => $cor, 'ok' => $ok];
}
$pct = $total > 0 ? round($score * 100 / $total) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('Résultat du quiz', 'Resultaat van de quiz') ?> — FamiFormation</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Open Sans', sans-serif; background: #f4f7f6; margin: 0; padding: 24px; }
        .container { max-width: 900px; margin: 0 auto; }
        a.back { color: #2d5a37; text-decoration: none; font-weight: 700; }
        .scorecard { background:#fff; border-radius:16px; box-shadow:0 8px 26px rgba(0,0,0,.08); padding:26px; margin:16px 0; text-align:center; }
        .score-big { font-size:2.6rem; font-weight:800; color:#2d5a37; }
        .bar { height:14px; background:#e8efe9; border-radius:999px; overflow:hidden; max-width:420px; margin:14px auto 0; }
        .bar > span { display:block; height:100%; background:<?= $pct >= 50 ? '#2d5a37' : '#c94a42' ?>; width:<?= (int) $pct ?>%; }
        .q { background:#fff; border-radius:14px; box-shadow:0 4px 14px rgba(0,0,0,.06); padding:18px 20px; margin:12px 0; border-left:6px solid #ccc; }
        .q.ok { border-left-color:#2d9a4e; } .q.no { border-left-color:#c94a42; }
        .qh { font-weight:800; color:#243b2e; margin-bottom:8px; }
        .opt { padding:7px 12px; border-radius:8px; margin:4px 0; display:flex; gap:8px; align-items:center; }
        .opt.correct { background:#e7f6ec; color:#1d6f42; font-weight:700; }
        .opt.wrongpick { background:#fbe6e4; color:#a83232; text-decoration:line-through; }
        .tag { font-size:.75rem; font-weight:800; padding:2px 8px; border-radius:999px; }
        .tag.ok { background:#e7f6ec; color:#1d6f42; } .tag.no { background:#fbe6e4; color:#a83232; }
        .btn { border:none; border-radius:10px; padding:11px 20px; font-weight:700; text-decoration:none; display:inline-block; }
        .btn-primary { background:#2d5a37; color:#fff; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/topbar.php'; famiTopbar($db, false); ?>
<div class="container">
    <a class="back" href="module.php?id=<?= (int) $id ?>">⬅ <?= t('Retour au module', 'Terug naar de module') ?></a>
    <div class="scorecard">
        <div class="score-big"><?= (int) $score ?> / <?= (int) $total ?></div>
        <div style="color:#5a6b60; font-weight:700; margin-top:4px;"><?= (int) $pct ?>% <?= t('de bonnes réponses', 'juiste antwoorden') ?></div>
        <div class="bar"><span></span></div>
    </div>

    <?php foreach ($results as $k => $r): $q = $r['q']; ?>
        <div class="q <?= $r['ok'] ? 'ok' : 'no' ?>">
            <div class="qh"><?= ($k + 1) ?>. <?= htmlspecialchars((string) $q['q']) ?>
                <span class="tag <?= $r['ok'] ? 'ok' : 'no' ?>"><?= $r['ok'] ? '✓ ' . t('Juste', 'Juist') : '✗ ' . t('Faux', 'Fout') ?></span>
            </div>
            <?php foreach (($q['options'] ?? []) as $j => $opt):
                $isCorrect = in_array((int) $j, $r['cor'], true);
                $isPicked = in_array((int) $j, $r['ua'], true);
                $cls = $isCorrect ? 'correct' : ($isPicked ? 'wrongpick' : '');
            ?>
                <div class="opt <?= $cls ?>">
                    <?= $isCorrect ? '✓' : ($isPicked ? '✗' : '•') ?>
                    <span><?= htmlspecialchars((string) $opt) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div style="text-align:center; margin:20px 0;">
        <a class="btn btn-primary" href="quiz.php?id=<?= (int) $id ?>"><?= t('Refaire le quiz', 'Quiz opnieuw doen') ?></a>
    </div>
</div>
</body>
</html>
